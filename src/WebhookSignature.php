<?php

namespace ContaShare\Socialite;

/**
 * Checks the `Signature` header ContaShare sends with every webhook: the HMAC-SHA256 (hex) of the raw request body, keyed
 * with your webhook secret. Always pass the raw body, never JSON you decoded and encoded again.
 */
final class WebhookSignature
{
    public static function isValid(string $rawBody, ?string $signature, string $secret): bool
    {
        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }
}
