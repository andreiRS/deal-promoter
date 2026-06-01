<?php

declare(strict_types=1);

namespace App\Waha;

/**
 * Raised when WAHA returns an unexpected, non-tolerated error so callers can
 * map it to a 502 without depending on the HttpClient exception hierarchy.
 */
final class WahaException extends \RuntimeException
{
}
