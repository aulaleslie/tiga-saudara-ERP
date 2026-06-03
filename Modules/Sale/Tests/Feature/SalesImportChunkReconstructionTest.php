<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Tests\TestCase;

class SalesImportChunkReconstructionTest extends TestCase
{
    use RefreshDatabase;

    public function test_binary_safe_chunk_reconstruction_with_split_row()
    {
        Storage::fake('local');
        
        // Ensure user has sales.create permission
        \Spatie\Permission\Models\Permission::create(['name' => 'sales.create', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('sales.create');
        
        $this->actingAs($user);

        // Simulate a CSV content
        $csvContent = "Tanggal,Customer,No Faktur,Produk,Kuantitas,Satuan,Harga per Unit\n";
        $csvContent .= "2020-01-01,Test Customer,INV-001,Product A,10,PCS,1000\n";
        // JL2915 shape: split happens before unit price
        $csvContent .= "2020-01-02,Test Customer,JL2915,Product B,1,PCS,2400\n";

        // Split the content artificially
        $splitPoint = strpos($csvContent, "2400\n");
        $chunk1 = substr($csvContent, 0, $splitPoint);
        $chunk2 = substr($csvContent, $splitPoint);

        $fileId = 'test_upload_id';
        $fileName = 'test_split.csv';

        // Upload chunk 1
        $response1 = $this->postJson(route('sales.upload.store'), [
            'is_chunked' => 1,
            'file_id' => $fileId,
            'chunk_index' => 0,
            'total_chunks' => 2,
            'file_name' => $fileName,
            'chunk' => UploadedFile::fake()->createWithContent('chunk0.part', $chunk1)
        ]);

        $response1->assertStatus(200);

        // Upload chunk 2
        $response2 = $this->postJson(route('sales.upload.store'), [
            'is_chunked' => 1,
            'file_id' => $fileId,
            'chunk_index' => 1,
            'total_chunks' => 2,
            'file_name' => $fileName,
            'chunk' => UploadedFile::fake()->createWithContent('chunk1.part', $chunk2)
        ]);

        if ($response2->status() !== 200) {
            dump($response2->json());
        }
        $response2->assertStatus(200);

        // Assert file reconstructed correctly
        $finalPath = 'imports/sales/' . $fileName;
        Storage::disk('local')->assertExists($finalPath);

        $reconstructedContent = Storage::disk('local')->get($finalPath);
        $this->assertEquals($csvContent, $reconstructedContent, 'Reconstructed file bytes do not match original file bytes');

        // Check the batch is created and rows are staged correctly
        $batch = SalesImportBatch::first();
        $this->assertNotNull($batch);

        // Job might not run synchronously if not using SyncQueue
        // but we can manually dispatch the stage job if needed, or if queue is sync, it will run.
    }

    public function test_staging_recovers_harga_satuan_from_jumlah_per_baris()
    {
        Storage::fake('local');
        
        \Spatie\Permission\Models\Permission::create(['name' => 'sales.create', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->givePermissionTo('sales.create');
        
        $this->actingAs($user);

        // JL2915 shape where unit price is missing but jumlah_per_baris is present
        $csvContent = "Tanggal,Customer,No Faktur,Produk,Kuantitas,Satuan,Harga per Unit,Jumlah Per Baris\n";
        $csvContent .= "2020-01-02,Test Customer,JL2915,Product B,2,PCS,,4800\n";

        // We can just use standard upload for this test since we are testing staging logic
        $response = $this->postJson(route('sales.upload.store'), [
            'file' => UploadedFile::fake()->createWithContent('jl2915.csv', $csvContent)
        ]);

        $response->assertStatus(302);

        $batch = SalesImportBatch::first();
        $this->assertNotNull($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        
        $rawJson = $row->raw_json;
        if (is_string($rawJson)) {
            $rawJson = json_decode($rawJson, true);
        }
        $this->assertEquals('2400', $rawJson['harga_satuan'] ?? null);
    }
}
