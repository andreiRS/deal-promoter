<?php

declare(strict_types=1);

namespace App\Waha;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The single class that talks to WAHA and the only holder of the WAHA
 * `X-Api-Key` (ADR 0002). The key must never reach a browser: it is set as a
 * request header here and nowhere else.
 *
 * Ports the validated prototype's `src/lib/waha.ts`, preserving WAHA's
 * inconsistent path shapes (plural `/api/sessions/...` for lifecycle, singular
 * `/api/{session}/...` for QR).
 */
final class WahaClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $session,
    ) {
    }

    /**
     * GET /api/sessions/{session}. 404 means the session was never created
     * (STOPPED); any other non-ok response is reported as UNKNOWN; otherwise
     * the upstream `status` field is returned (falling back to UNKNOWN).
     */
    public function getSessionStatus(): string
    {
        $response = $this->request('GET', "/api/sessions/{$this->session}");
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
     * POST /api/sessions/{session}/start. WAHA replies 422 when the session is
     * already started, which the prototype treats as success; any other non-ok
     * status is a real failure.
     */
    public function startSession(): void
    {
        $response = $this->request('POST', "/api/sessions/{$this->session}/start");
        $status = $response->getStatusCode();

        if (($status < 200 || $status >= 300) && 422 !== $status) {
            throw new WahaException("WAHA start failed: {$status}");
        }
    }

    /**
     * POST /api/sessions/{session}/logout. Fire-and-forget: the prototype
     * ignores the outcome, so we only force the request to flush and swallow
     * the result.
     */
    public function logoutSession(): void
    {
        $this->request('POST', "/api/sessions/{$this->session}/logout")->getStatusCode();
    }

    /**
     * GET /api/{session}/channels (SINGULAR path, matching the QR endpoint). Filters
     * the list to channels whose id ends with `@newsletter` AND whose role is OWNER
     * or ADMIN (i.e. the account can post to it). Mirrors `listOwnedChannels` in
     * the prototype's `waha.ts`. Throws on a non-ok upstream response.
     *
     * @return list<array{id: string, name: string, role: string}>
     */
    public function listOwnedChannels(): array
    {
        $response = $this->request('GET', "/api/{$this->session}/channels");
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            throw new WahaException("WAHA channels failed: {$status}");
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
     * POST /api/sendText. Sends `text` to `chatId` using the configured session.
     * Returns a result array mirroring the prototype's `{ ok, status, data }` shape
     * so the caller can relay the outcome without knowing WAHA's response schema.
     *
     * @return array{ok: bool, status: int, data: mixed}
     */
    public function sendText(string $chatId, string $text): array
    {
        $response = $this->request('POST', '/api/sendText', [
            'session' => $this->session,
            'chatId' => $chatId,
            'text' => $text,
        ]);
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
     * GET /api/{session}/auth/qr?format=image. Note the SINGULAR `/api/{session}`
     * shape (WAHA is inconsistent vs. the plural lifecycle paths). Returns the
     * raw image bytes and content type for the controller to stream; on a non-ok
     * response it surfaces the upstream status so the caller can relay it.
     */
    public function getQrImage(): QrImage
    {
        $response = $this->request('GET', "/api/{$this->session}/auth/qr?format=image");
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            return QrImage::unavailable($status);
        }

        $headers = $response->getHeaders(false);
        $contentType = $headers['content-type'][0] ?? 'image/png';

        return QrImage::available($response->getContent(false), $contentType);
    }

    /**
     * @param array<string, mixed>|null $json JSON-encodable body; when provided,
     *                                        Content-Type: application/json is set automatically
     */
    private function request(string $method, string $path, ?array $json = null): ResponseInterface
    {
        // The X-Api-Key is attached here and only here (ADR 0002).
        $options = ['headers' => ['X-Api-Key' => $this->apiKey]];

        if (null !== $json) {
            $options['json'] = $json;
        }

        return $this->http->request($method, $this->baseUrl.$path, $options);
    }
}
