<?php

namespace Tests\Unit\Integration;

use App\Integration\Support\SignatureVerifier;
use PHPUnit\Framework\TestCase;

final class SignatureVerifierTest extends TestCase
{
    /** Sır test içinde üretilir; depoda sabit bir sır/anahtar tutulmaz. */
    private function secret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function test_valid_tsoft_signature_is_accepted(): void
    {
        $secret = $this->secret();
        $body = '{"topic":"order.created","id":"ord-1"}';
        $timestamp = time();
        $signature = base64_encode(hash_hmac('sha256', "{$timestamp}.{$body}", $secret, true));

        $this->assertTrue(SignatureVerifier::verifyTsoft(
            $body,
            "t={$timestamp},v1={$signature}",
            $secret,
        ));
    }

    public function test_tampered_body_or_signature_is_rejected(): void
    {
        $secret = $this->secret();
        $body = '{"topic":"order.created"}';
        $timestamp = time();
        $signature = base64_encode(hash_hmac('sha256', "{$timestamp}.{$body}", $secret, true));

        $this->assertFalse(SignatureVerifier::verifyTsoft(
            $body.' ',
            "t={$timestamp},v1={$signature}",
            $secret,
        ));

        $badSignature = base64_encode(hash_hmac('sha256', "{$timestamp}.{$body}", $this->secret(), true));
        $this->assertFalse(SignatureVerifier::verifyTsoft(
            $body,
            "t={$timestamp},v1={$badSignature}",
            $secret,
        ));
    }

    public function test_old_timestamp_is_rejected(): void
    {
        $secret = $this->secret();
        $body = '{"topic":"order.created"}';
        $timestamp = time() - 400;
        $signature = base64_encode(hash_hmac('sha256', "{$timestamp}.{$body}", $secret, true));

        $this->assertFalse(SignatureVerifier::verifyTsoft(
            $body,
            "t={$timestamp},v1={$signature}",
            $secret,
        ));
    }

    public function test_malformed_header_and_empty_secret_are_rejected(): void
    {
        $secret = $this->secret();
        $body = '{}';

        $this->assertFalse(SignatureVerifier::verifyTsoft($body, 'not-a-signature', $secret));
        $this->assertFalse(SignatureVerifier::verifyTsoft($body, null, $secret));
        $this->assertFalse(SignatureVerifier::verifyTsoft($body, 't=abc,v1=xyz', $secret));
        $this->assertFalse(SignatureVerifier::verifyTsoft($body, 't=1,v1=xyz', ''));
    }

    public function test_legacy_hub_signature_is_accepted(): void
    {
        $secret = $this->secret();
        $body = '{"event":"stock.updated"}';
        $signature = 'sha1='.hash_hmac('sha1', $body, $secret);

        $this->assertTrue(SignatureVerifier::verifyHub($body, $signature, $secret));
        $this->assertFalse(SignatureVerifier::verifyHub($body.' ', $signature, $secret));
        $this->assertFalse(SignatureVerifier::verifyHub($body, 'md5=abc', $secret));
        $this->assertFalse(SignatureVerifier::verifyHub($body, $signature, ''));
    }
}
