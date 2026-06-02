<?php

declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Result of a QR fetch: either the raw image bytes plus their content type, or
 * an unavailable marker carrying the upstream HTTP status for the controller to
 * relay. Mirrors the prototype's discriminated `getQrImage` return.
 */
final class QrImage
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $body,
        public readonly string $contentType,
        public readonly int $status,
    ) {
    }

    public static function available(string $body, string $contentType): self
    {
        return new self(true, $body, $contentType, 200);
    }

    public static function unavailable(int $status): self
    {
        return new self(false, '', '', $status);
    }
}
