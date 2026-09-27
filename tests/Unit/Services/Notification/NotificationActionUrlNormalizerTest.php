<?php

namespace Tests\Unit\Services\Notification;

use App\Services\Notification\NotificationActionUrlNormalizer;
use PHPUnit\Framework\TestCase;

class NotificationActionUrlNormalizerTest extends TestCase
{
    public function test_normalizes_null_and_empty_inputs()
    {
        $this->assertNull(NotificationActionUrlNormalizer::normalize(null));
        $this->assertNull(NotificationActionUrlNormalizer::normalize(''));
        $this->assertNull(NotificationActionUrlNormalizer::normalize('   '));
    }

    public function test_preserves_hash_placeholder()
    {
        $this->assertEquals('#', NotificationActionUrlNormalizer::normalize('#'));
    }

    public function test_accepts_valid_origin_relative_paths()
    {
        $this->assertEquals('/purchases', NotificationActionUrlNormalizer::normalize('/purchases'));
        $this->assertEquals('/purchases/123', NotificationActionUrlNormalizer::normalize('/purchases/123'));
        $this->assertEquals('/purchases/123', NotificationActionUrlNormalizer::normalize('purchases/123'));
    }

    public function test_preserves_query_and_fragment_in_relative_paths()
    {
        $this->assertEquals('/purchases?filter=active&page=2#tab-items', NotificationActionUrlNormalizer::normalize('/purchases?filter=active&page=2#tab-items'));
        $this->assertEquals('/notifications?unread=1#latest', NotificationActionUrlNormalizer::normalize('/notifications?unread=1#latest'));
    }

    public function test_rejects_protocol_relative_and_malformed_urls()
    {
        $this->assertNull(NotificationActionUrlNormalizer::normalize('//evil.com/hack'));
        $this->assertNull(NotificationActionUrlNormalizer::normalize('///evil.com/hack'));
        $this->assertNull(NotificationActionUrlNormalizer::normalize('\\evil.com'));
        $this->assertNull(NotificationActionUrlNormalizer::normalize('/\\evil.com'));
    }

    public function test_rejects_external_absolute_urls_when_no_allowed_origins_passed()
    {
        $this->assertNull(NotificationActionUrlNormalizer::normalize('https://evil.com/phishing'));
        $this->assertNull(NotificationActionUrlNormalizer::normalize('http://localhost/purchases'));
        $this->assertNull(NotificationActionUrlNormalizer::normalize('http://192.168.1.100/purchases'));
    }

    public function test_converts_allowed_external_origins_to_relative_paths()
    {
        $allowedOrigins = [
            'http://192.168.1.50:8000',
            'https://erp.tigasaudara.com',
        ];

        $this->assertEquals(
            '/purchases/1',
            NotificationActionUrlNormalizer::normalize('http://192.168.1.50:8000/purchases/1', $allowedOrigins)
        );

        $this->assertEquals(
            '/sale-returns/10?tab=details#items',
            NotificationActionUrlNormalizer::normalize('https://erp.tigasaudara.com/sale-returns/10?tab=details#items', $allowedOrigins)
        );

        // Disallowed origin returns null
        $this->assertNull(
            NotificationActionUrlNormalizer::normalize('https://attacker.com/purchases/1', $allowedOrigins)
        );

        // Different port returns null
        $this->assertNull(
            NotificationActionUrlNormalizer::normalize('http://192.168.1.50:9000/purchases/1', $allowedOrigins)
        );
    }
}
