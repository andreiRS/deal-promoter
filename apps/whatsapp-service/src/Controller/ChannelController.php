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
 *                   NOT gated (no X-Internal-Key) — that belongs to slice 4's /send.
 */
final class ChannelController extends AbstractController
{
    public function __construct(private readonly WahaClient $waha)
    {
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
        /** @var array{chatId?: string, text?: string} $body */
        $body = json_decode((string) $request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $chatId = $body['chatId'] ?? '';
        $text = trim($body['text'] ?? '');

        $error = $this->guardSend($chatId, $text);
        if (null !== $error) {
            return new JsonResponse(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $result = $this->waha->sendText($chatId, $text);

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
     * on success. Extracted so slice 4's gated `/send` can reuse the exact same
     * validation without duplicating it.
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
