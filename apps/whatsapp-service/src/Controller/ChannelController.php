<?php

declare(strict_types=1);

namespace App\Controller;

use App\Waha\WahaClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Open, host-bound channel listing and manual send surface (ADR 0002).
 *
 * GET  /channels  — proxies WahaClient::listOwnedChannels; 502 on transport failure.
 * POST /ui/send   — the human send path. Guards chatId + text SERVER-SIDE before any
 *                   WAHA call; routes through WahaClient::sendText on success.
 *                   NOT gated (no X-Internal-Key).
 * POST /send      — machine-facing, gated send path. Requires X-Internal-Key header;
 *                   401 is returned BEFORE guards and BEFORE any WAHA call when the
 *                   key is missing or wrong. Shares the same guard + delivery path as
 *                   /ui/send — exactly one WAHA delivery path in the codebase.
 */
final class ChannelController extends AbstractController
{
    public function __construct(
        private readonly WahaClient $waha,
        private readonly string $internalKey,
    ) {
    }

    #[Route('/channels', name: 'channels', methods: ['GET'])]
    public function channels(): JsonResponse
    {
        try {
            return new JsonResponse($this->waha->listOwnedChannels());
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/ui/send', name: 'ui_send', methods: ['POST'])]
    public function uiSend(Request $request): JsonResponse
    {
        /** @var array{chatId?: string, text?: string, preview?: array{url: string, title: string, image: string}} $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $chatId = $body['chatId'] ?? '';
        $text = trim($body['text'] ?? '');
        $preview = $body['preview'] ?? null;

        return $this->deliver($chatId, $text, $preview);
    }

    #[Route('/send', name: 'send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        // 401 fires BEFORE guards and BEFORE any WAHA call.
        // Fail-closed: empty internalKey (misconfigured gateway) → always 401.
        $providedKey = $request->headers->get('X-Internal-Key', '');
        if ('' === $this->internalKey || $providedKey !== $this->internalKey) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        /** @var array{chatId?: string, text?: string, preview?: array{url: string, title: string, image: string}} $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $chatId = $body['chatId'] ?? '';
        $text = trim($body['text'] ?? '');
        $preview = $body['preview'] ?? null;

        return $this->deliver($chatId, $text, $preview);
    }

    /**
     * Single engine delivery path shared by /ui/send and /send.
     *
     * Applies guardSend (400 on failure), then calls WahaClient::sendText (502 on
     * transport failure). Both routes must go through here — no duplicated engine call.
     *
     * @param array{url: string, title: string, image: string}|null $preview
     */
    private function deliver(string $chatId, string $text, ?array $preview = null): JsonResponse
    {
        $error = $this->guardSend($chatId, $text);
        if (null !== $error) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->waha->sendText($chatId, $text, $preview);

        if (!$result['ok']) {
            return new JsonResponse(
                ['error' => "WAHA returned {$result['status']}"],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse(['ok' => true, 'data' => $result['data']]);
    }

    /**
     * Shared guard for chatId and text. Returns an error string on failure, null
     * on success. Used by deliver() which is called by both /ui/send and /send.
     */
    private function guardSend(string $chatId, string $text): ?string
    {
        if ('' === $chatId || !str_ends_with($chatId, '@newsletter')) {
            return 'chatId must be present and end with @newsletter';
        }

        if ('' === $text) {
            return 'text must be non-empty';
        }

        return null;
    }
}
