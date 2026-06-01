<?php

declare(strict_types=1);

namespace App\Waha;

/**
 * The WAHA session-status domain, mirroring the prototype's `SessionStatus`
 * union. Modelled as string constants so the values pass straight through to
 * JSON responses and `<img>` polling without conversion.
 */
final class SessionStatus
{
    public const string STOPPED = 'STOPPED';
    public const string STARTING = 'STARTING';
    public const string SCAN_QR_CODE = 'SCAN_QR_CODE';
    public const string WORKING = 'WORKING';
    public const string FAILED = 'FAILED';
    public const string UNKNOWN = 'UNKNOWN';

    private function __construct()
    {
    }
}
