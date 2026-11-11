<?php

namespace ContaShare\Socialite\Tests;

use ContaShare\Socialite\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const BODY = '{"id":"9b3f8a2e","type":"address","action":"update"}';

    public function test_a_signature_made_with_the_secret_is_valid(): void
    {
        $this->assertTrue(WebhookSignature::isValid(self::BODY, hash_hmac('sha256', self::BODY, 'whsec'), 'whsec'));
    }

    public function test_a_wrong_secret_a_changed_body_or_no_signature_is_refused(): void
    {
        $signature = hash_hmac('sha256', self::BODY, 'whsec');

        $this->assertFalse(WebhookSignature::isValid(self::BODY, $signature, 'another-secret'));
        $this->assertFalse(WebhookSignature::isValid(str_replace('update', 'delete', self::BODY), $signature, 'whsec'));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, null, 'whsec'));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, '', 'whsec'));
        $this->assertFalse(WebhookSignature::isValid(self::BODY, $signature, ''));
    }

    public function test_json_decoded_and_encoded_again_no_longer_matches(): void
    {
        $body = "{\n  \"id\": \"9b3f8a2e\"\n}";

        $this->assertFalse(WebhookSignature::isValid((string) json_encode(json_decode($body, true)), hash_hmac('sha256', $body, 'whsec'), 'whsec'));
    }
}
