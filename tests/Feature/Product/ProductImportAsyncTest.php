<?php

namespace Tests\Feature\Product;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\ProductImportJob;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Reports\Formatters\XlsxFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProductImportAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Storage::fake('local');
    }

    public function test_admin_can_import_products_from_csv_file(): void
    {
        $admin = $this->createAdminUser();
        $category = Category::create(['name' => 'Audio', 'slug' => 'audio']);

        $csvData = "name,price,stock,sku,category_id,brand,description\n";
        $csvData .= "Pro Headphones,149.99,35,HDPH-PRO-01,{$category->id},Acme,Studio quality audio\n";

        $file = UploadedFile::fake()->createWithContent('products.csv', $csvData);

        $res = $this->actingAs($admin, 'api')->post('/api/v1/products/imports', [
            'file' => $file,
            'dry_run' => false,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 0);

        $jobId = $res->json('data.id');
        $job = ProductImportJob::findOrFail($jobId);

        $this->assertEquals(ProductImportJob::STATUS_COMPLETED, $job->status);
        $this->assertEquals('csv', $job->file_format);

        // Product is draft by default
        $product = Product::where('sku', 'HDPH-PRO-01')->firstOrFail();
        $this->assertEquals('Pro Headphones', $product->name);
        $this->assertEquals(149.99, (float) $product->price);
        $this->assertEquals(35, $product->stock);
        $this->assertEquals('draft', $product->status);
        $this->assertFalse((bool) $product->is_active);

        // Inventory adjustment ledger entry exists
        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $product->id,
            'quantity_delta' => 35,
            'quantity_before' => 0,
            'quantity_after' => 35,
            'reason' => 'supplier_receipt',
        ]);

        // Audit events recorded
        $this->assertDatabaseHas('audit_events', [
            'action' => 'product_import.requested',
            'subject_id' => $jobId,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'product_import.completed',
            'subject_id' => $jobId,
        ]);
    }

    public function test_admin_can_import_products_from_xlsx_file(): void
    {
        $admin = $this->createAdminUser();
        Category::create(['name' => 'Tech', 'slug' => 'tech']);

        $headers = ['name', 'price', 'stock', 'sku'];
        $rows = [
            ['name' => 'Mechanical Keyboard', 'price' => '89.99', 'stock' => '40', 'sku' => 'KEY-MECH-01'],
        ];

        $formatter = new XlsxFormatter;
        $xlsxContent = $formatter->format($headers, $rows);

        $file = UploadedFile::fake()->createWithContent('products.xlsx', $xlsxContent);

        $res = $this->actingAs($admin, 'api')->post('/api/v1/products/imports', [
            'file' => $file,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.total_rows', 1)
            ->assertJsonPath('data.successful_rows', 1);

        $product = Product::where('sku', 'KEY-MECH-01')->firstOrFail();
        $this->assertEquals('draft', $product->status);
        $this->assertEquals(40, $product->stock);
    }

    public function test_duplicate_sku_policies_update_skip_and_error(): void
    {
        $admin = $this->createAdminUser();
        $category = Category::create(['name' => 'Gadgets', 'slug' => 'gadgets']);

        $existing = Product::create([
            'category_id' => $category->id,
            'name' => 'Original Smartwatch',
            'slug' => 'original-smartwatch',
            'sku' => 'WATCH-DUP-01',
            'price' => 199.99,
            'stock' => 10,
            'status' => 'draft',
            'is_active' => false,
        ]);

        // 1. Policy 'skip'
        $resSkip = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'duplicate_sku_policy' => 'skip',
            'rows' => [
                ['name' => 'Updated Smartwatch', 'price' => '249.99', 'stock' => 25, 'sku' => 'WATCH-DUP-01'],
            ],
        ]);
        $resSkip->assertStatus(201);
        $this->assertEquals('Original Smartwatch', $existing->fresh()->name);
        $this->assertEquals(10, $existing->fresh()->stock);

        // 2. Policy 'update'
        $resUpdate = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'duplicate_sku_policy' => 'update',
            'rows' => [
                ['name' => 'Updated Smartwatch', 'price' => '249.99', 'stock' => 25, 'sku' => 'WATCH-DUP-01'],
            ],
        ]);
        $resUpdate->assertStatus(201);
        $this->assertEquals('Updated Smartwatch', $existing->fresh()->name);
        $this->assertEquals(25, $existing->fresh()->stock);

        // Inventory adjustment for stock delta (25 - 10 = 15)
        $this->assertDatabaseHas('inventory_adjustments', [
            'product_id' => $existing->id,
            'quantity_delta' => 15,
            'quantity_before' => 10,
            'quantity_after' => 25,
        ]);

        // 3. Policy 'error'
        $resError = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'duplicate_sku_policy' => 'error',
            'rows' => [
                ['name' => 'Third Smartwatch', 'price' => '299.99', 'sku' => 'WATCH-DUP-01'],
            ],
        ]);
        $resError->assertStatus(201)
            ->assertJsonPath('data.failed_rows', 1);
    }

    public function test_duplicate_slug_policies_generate_suffix_and_error(): void
    {
        $admin = $this->createAdminUser();
        $category = Category::create(['name' => 'Sound', 'slug' => 'sound']);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Bluetooth Speaker',
            'slug' => 'bluetooth-speaker',
            'sku' => 'SPK-001',
            'price' => 59.99,
            'stock' => 5,
        ]);

        // Policy 'generate_suffix'
        $resSuffix = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'duplicate_slug_policy' => 'generate_suffix',
            'rows' => [
                ['name' => 'Bluetooth Speaker', 'price' => '69.99', 'sku' => 'SPK-002'],
            ],
        ]);
        $resSuffix->assertStatus(201)->assertJsonPath('data.successful_rows', 1);

        $newProduct = Product::where('sku', 'SPK-002')->firstOrFail();
        $this->assertStringStartsWith('bluetooth-speaker-', $newProduct->slug);

        // Policy 'error'
        $resError = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'duplicate_slug_policy' => 'error',
            'rows' => [
                ['name' => 'Bluetooth Speaker', 'price' => '79.99', 'sku' => 'SPK-003'],
            ],
        ]);
        $resError->assertStatus(201)->assertJsonPath('data.failed_rows', 1);
    }

    public function test_error_report_generation_and_signed_download(): void
    {
        $admin = $this->createAdminUser();

        $res = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'rows' => [
                ['name' => '', 'price' => 'not-a-number'], // invalid row
            ],
        ]);

        $res->assertStatus(201);
        $jobId = $res->json('data.id');
        $job = ProductImportJob::findOrFail($jobId);

        $this->assertNotNull($job->error_report_path);
        $this->assertTrue(Storage::disk('local')->exists($job->error_report_path));

        // Show endpoint includes signed error report url
        $showRes = $this->actingAs($admin, 'api')->getJson("/api/v1/products/imports/{$jobId}");
        $showRes->assertOk();
        $errorReportUrl = $showRes->json('data.error_report_url');
        $this->assertNotNull($errorReportUrl);

        // Download error report
        $downloadRes = $this->actingAs($admin, 'api')->get($errorReportUrl);
        $downloadRes->assertOk();
        $downloadRes->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'product_import.error_report_downloaded',
            'subject_id' => $jobId,
        ]);
    }

    public function test_idempotency_key_replays_and_rejects_mismatch(): void
    {
        $admin = $this->createAdminUser();

        $headers = ['Idempotency-Key' => 'idemp-prod-imp-test-1'];
        $payload = [
            'rows' => [
                ['name' => 'Unique Gadget', 'price' => '19.99', 'sku' => 'UG-01'],
            ],
        ];

        $res1 = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', $payload, $headers);
        $res1->assertStatus(201);
        $id1 = $res1->json('data.id');

        // Replay exact same payload
        $res2 = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', $payload, $headers);
        $res2->assertOk();
        $this->assertEquals($id1, $res2->json('data.id'));

        // Payload mismatch throws 422
        $res3 = $this->actingAs($admin, 'api')->postJson('/api/v1/products/imports', [
            'rows' => [
                ['name' => 'Different Gadget', 'price' => '29.99'],
            ],
        ], $headers);
        $res3->assertStatus(422)->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_cancel_and_retry_import_job(): void
    {
        $admin = $this->createAdminUser();

        $job = ProductImportJob::create([
            'user_id' => $admin->id,
            'file_name' => 'test-cancel.csv',
            'status' => ProductImportJob::STATUS_PENDING,
            'total_rows' => 10,
        ]);

        // Cancel
        $cancelRes = $this->actingAs($admin, 'api')->postJson("/api/v1/products/imports/{$job->id}/cancel");
        $cancelRes->assertOk();
        $this->assertEquals(ProductImportJob::STATUS_CANCELLED, $job->fresh()->status);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'product_import.cancelled',
            'subject_id' => $job->id,
        ]);

        // Retry
        $retryRes = $this->actingAs($admin, 'api')->postJson("/api/v1/products/imports/{$job->id}/retry");
        $retryRes->assertOk();
        $this->assertEquals(1, $job->fresh()->retry_count);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'product_import.retried',
            'subject_id' => $job->id,
        ]);
    }

    public function test_non_admin_cannot_import_products(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'api')->postJson('/api/v1/products/imports', [
            'rows' => [
                ['name' => 'Unauthorized Item', 'price' => '10.00'],
            ],
        ])->assertForbidden();
    }

    private function createAdminUser(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $adminRole = Role::firstOrCreate(['name' => 'admin'], [
            'display_name' => 'Admin',
            'is_super_admin' => true,
        ]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        return $admin;
    }
}
