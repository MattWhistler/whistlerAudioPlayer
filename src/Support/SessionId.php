<?php

declare(strict_types=1);

namespace AudioSummaryPlayer\Support;

/**
 * UUID v4 validation. Generation lives on the client (localStorage),
 * server only verifies shape before persisting.
 */
final class SessionId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function isValid(string $candidate): bool
    {
        return $candidate !== '' && (bool) preg_match(self::PATTERN, $candidate);
    }
}
