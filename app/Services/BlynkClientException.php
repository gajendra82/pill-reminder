<?php

namespace App\Services;

use RuntimeException;

class BlynkClientException extends RuntimeException
{
    public const NOT_CONFIGURED = 'blynk_not_configured';

    public const UNAUTHORIZED = 'blynk_unauthorized';

    public const RATE_LIMITED = 'blynk_rate_limited';

    public const CONNECTION_FAILED = 'blynk_connection_failed';

    public const INVALID_RESPONSE = 'blynk_invalid_response';

    public const UNAVAILABLE = 'blynk_unavailable';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Blynk read failed.');
    }
}
