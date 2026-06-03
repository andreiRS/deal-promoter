<?php

declare(strict_types=1);

namespace App\WhatsApp;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The single class that talks to the whatsmeow engine.
 *
 * The engine is keyless and exposes clean paths with no /api prefix and no
 * {session} segment, so it needs only a base URL.
 */
final class WhatsAppClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * GET /session. 404 means no session yet (STOPPED); other non-2xx → UNKNOWN;
     * 2xx → parse {status} from the response body.
     */
    public function getSessionStatus(): string
    {
        $response = $this->request('GET', '/session');
        $status = $response->getStatusCode();

        if (404 === $status) {
            return SessionStatus::STOPPED;
        }
        if ($status < 200 || $status >= 300) {
            return SessionStatus::UNKNOWN;
        }

        /** @var array{status?: string} $data */
        $data = $response->toArray(false);

        return $data['status'] ?? SessionStatus::UNKNOWN;
    }

    /**
     * POST /session/start. 2xx → success; non-2xx → WhatsAppException.
     */
    public function startSession(): void
    {
        $response = $this->request('POST', '/session/start');
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new WhatsAppException("Engine start failed: {$status}");
        }
    }

    /**
     * POST /session/logout. Fire-and-forget: only flushes the response.
     */
    public function logoutSession(): void
    {
        $this->request('POST', '/session/logout')->getStatusCode();
    }

    /**
     * GET /channels. Filters to @newsletter channels with OWNER/ADMIN role as a
     * defense-in-depth guard (the engine already pre-filters; the PHP filter is
     * harmless and keeps the existing unit test assertion intact).
     *
     * @return list<array{id: string, name: string, role: string}>
     */
    public function listOwnedChannels(): array
    {
        $response = $this->request('GET', '/channels');
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new WhatsAppException("Engine channels failed: {$status}");
        }

        /** @var list<array{id: string, name: string, role: string}> $raw */
        $raw = $response->toArray(false);

        return array_values(array_filter(
            $raw,
            static fn (array $channel): bool => str_ends_with($channel['id'], '@newsletter')
                && \in_array($channel['role'], ['OWNER', 'ADMIN'], true),
        ));
    }

    /**
     * POST /send. Sends {chatId, text} — and optionally
     * {preview:{url,title,image,highRes}} — to the engine. The opt-in highRes flag
     * is normalized to a bool (default false) so the engine always receives an
     * explicit value. Returns {ok, status, data} matching the existing caller shape.
     *
     * @param array{url: string, title: string, image: string, highRes?: bool}|null $preview
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendText(string $chatId, string $text, ?array $preview = null): array
    {
        $body = ['chatId' => $chatId, 'text' => $text];

        if (null !== $preview) {
            $preview['highRes'] = (bool) ($preview['highRes'] ?? false);
            $body['preview'] = $preview;
        }

        $response = $this->request('POST', '/send', $body);
        $status = $response->getStatusCode();
        $ok = $status >= 200 && $status < 300;

        try {
            $data = $response->toArray(false);
        } catch (\Throwable) {
            $data = null;
        }

        return ['ok' => $ok, 'status' => $status, 'data' => $data];
    }

    /**
     * GET /qr. 2xx → QrImage::available(bytes, contentType); any non-2xx (incl.
     * 404 when no QR is available) → QrImage::unavailable(status).
     */
    public function getQrImage(): QrImage
    {
        $response = $this->request('GET', '/qr');
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return QrImage::unavailable($status);
        }

        $headers = $response->getHeaders(false);
        $contentType = $headers['content-type'][0] ?? 'image/png';

        return QrImage::available($response->getContent(false), $contentType);
    }

    /**
     * @param array<string, mixed>|null $json JSON body; sets Content-Type automatically
     */
    private function request(string $method, string $path, ?array $json = null): ResponseInterface
    {
        $options = [];

        if (null !== $json) {
            $options['json'] = $json;
        }

        return $this->http->request($method, $this->baseUrl.$path, $options);
    }
}
