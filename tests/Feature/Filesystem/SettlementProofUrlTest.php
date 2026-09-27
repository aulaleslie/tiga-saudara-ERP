<?php

namespace Tests\Feature\Filesystem;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettlementProofUrlTest extends TestCase
{
    public function test_settlement_proof_urls_resolve_to_origin_relative_storage_paths(): void
    {
        config(['filesystems.disks.public.url' => '/storage']);

        // Case 1: Standard relative path stored in database
        $proofPath = 'settlements/proof_12345.jpg';
        $publicUrl = Storage::disk('public')->url($proofPath);

        $this->assertEquals('/storage/settlements/proof_12345.jpg', $publicUrl);
        $this->assertStringStartsWith('/storage/', $publicUrl);
        $this->assertStringNotContainsString('http://', $publicUrl);
        $this->assertStringNotContainsString('https://', $publicUrl);

        // Case 2: Subdirectory relative path
        $proofPathNested = 'proofs/2026/09/payment_slip.pdf';
        $publicUrlNested = Storage::disk('public')->url($proofPathNested);

        $this->assertEquals('/storage/proofs/2026/09/payment_slip.pdf', $publicUrlNested);
        $this->assertStringStartsWith('/storage/', $publicUrlNested);
        $this->assertStringNotContainsString('http://', $publicUrlNested);
        $this->assertStringNotContainsString('https://', $publicUrlNested);
    }
}
