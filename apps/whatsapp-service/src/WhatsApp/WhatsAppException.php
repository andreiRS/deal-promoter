<?php

declare(strict_types=1);

namespace App\WhatsApp;

/**
 * Raised when the engine returns an unexpected, non-tolerated error so callers
 * can map it to a 502 without depending on the HttpClient exception hierarchy.
 */
final class WhatsAppException extends \RuntimeException
{
}
