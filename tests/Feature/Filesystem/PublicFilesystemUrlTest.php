<?php

namespace Tests\Feature\Filesystem;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFilesystemUrlTest extends TestCase
{
    public function test_public_disk_generates_origin_relative_url()
    {
        $url = Storage::disk('public')->url('products/test-image.jpg');

        $this->assertEquals('/storage/products/test-image.jpg', $url);
        $this->assertStringStartsWith('/storage/', $url);
        $this->assertFalse(str_starts_with($url, 'http://'));
        $this->assertFalse(str_starts_with($url, 'https://'));
    }

    public function test_public_disk_url_under_lan_request_origin()
    {
        $response = $this->get('http://192.168.1.100:8000/login');
        $response->assertSuccessful();

        $url = Storage::disk('public')->url('avatars/user.png');
        $this->assertEquals('/storage/avatars/user.png', $url);
    }

    public function test_public_disk_url_under_cloudflare_request_origin()
    {
        $response = $this->get('https://erp.tigasaudara.com/login');
        $response->assertSuccessful();

        $url = Storage::disk('public')->url('attachments/doc.pdf');
        $this->assertEquals('/storage/attachments/doc.pdf', $url);
    }
}
