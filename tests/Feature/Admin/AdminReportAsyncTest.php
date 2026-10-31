<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Admin\AdminReport;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateCommission;
use App\Models\Affiliate\AffiliateSale;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\Analytics\DailyAggregate;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use App\Models\User\Permission;
use App\Models\User\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

class AdminReportAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Storage::fake('local');
    }

    public function test_admin_can_request_and_generate_orders_report_csv_and_xlsx(): void
    {
        $admin = $this->createAdminUser();
        $buyer = User::factory()->create(['role' => 'user', 'email' => 'buyer@example.com']);

        Order::create([
            'user_id' => $buyer->id,
            'number' => 'ORD-REP-101',
            'currency' => 'NGN',
            'subtotal' => 5000.00,
            'grand_total' => 5000.00,
            'total_amount' => 5000.00,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'contact_email' => 'buyer@example.com',
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '123 Victoria Island'],
        ]);

        // 1. Request CSV
        $resCsv = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reports', [
            'type' => 'orders',
            'format' => 'csv',
            'timezone' => 'Africa/Lagos',
        ]);

        $resCsv->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'orders')
            ->assertJsonPath('data.format', 'csv')
            ->assertJsonPath('data.status', 'queued');

        $reportId = $resCsv->json('data.id');
        $report = AdminReport::findOrFail($reportId);

        $this->assertEquals(AdminReport::STATUS_COMPLETED, $report->status);
        $this->assertEquals(1, $report->row_count);
        $this->assertTrue(Storage::disk('local')->exists($report->file_path));

        $csvContent = Storage::disk('local')->get($report->file_path);
        $this->assertStringContainsString('ORD-REP-101', $csvContent);
        $this->assertStringContainsString('buyer@example.com', $csvContent);

        // 2. Request XLSX
        $resXlsx = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reports', [
            'type' => 'orders',
            'format' => 'xlsx',
        ]);

        $resXlsx->assertCreated();
        $xlsxReport = AdminReport::findOrFail($resXlsx->json('data.id'));
        $this->assertEquals(AdminReport::STATUS_COMPLETED, $xlsxReport->status);
        $this->assertTrue(Storage::disk('local')->exists($xlsxReport->file_path));

        // Verify valid zip/openxml structure
        $zip = new ZipArchive;
        $tempPath = tempnam(sys_get_temp_dir(), 'test_xlsx_');
        file_put_contents($tempPath, Storage::disk('local')->get($xlsxReport->file_path));
        $this->assertTrue($zip->open($tempPath));
        $this->assertNotEmpty($zip->getFromName('xl/worksheets/sheet1.xml'));
        $this->assertNotEmpty($zip->getFromName('[Content_Types].xml'));
        $zip->close();
        @unlink($tempPath);

        // Audit events
        $this->assertDatabaseHas('audit_events', [
            'action' => 'report.requested',
            'subject_id' => $report->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'report.completed',
            'subject_id' => $report->id,
        ]);
    }

    public function test_admin_can_generate_all_ten_report_types(): void
    {
        $admin = $this->createAdminUser();
        $user = User::factory()->create(['role' => 'user']);
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Flagship Phone',
            'slug' => 'flagship-phone',
            'sku' => 'PHONE-001',
            'price' => 120000.00,
            'compare_at_price' => 130000.00,
            'stock' => 3,
            'is_active' => true,
        ]);

        $order = Order::create([
            'user_id' => $user->id,
            'number' => 'ORD-10-TEST',
            'currency' => 'NGN',
            'subtotal' => 120000.00,
            'grand_total' => 120000.00,
            'total_amount' => 120000.00,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => 'Test'],
        ]);

        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'afftest10',
            'code' => 'AFFTEST10',
            'status' => 'active',
            'total_earnings' => 5000.00,
            'pending_balance' => 2000.00,
            'paid_balance' => 3000.00,
        ]);

        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 120000.00,
            'price_at_purchase' => 120000.00,
            'line_total' => 120000.00,
        ]);

        $sale = AffiliateSale::create([
            'affiliate_id' => $affiliate->id,
            'order_id' => $order->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $product->id,
            'buyer_user_id' => $user->id,
            'sale_type' => 'direct',
            'sale_amount' => 1000.00,
        ]);

        AffiliateCommission::create([
            'affiliate_id' => $affiliate->id,
            'affiliate_sale_id' => $sale->id,
            'commission_amount' => 100.00,
            'commission_percent' => 10.00,
            'payment_status' => 'pending',
        ]);

        AffiliateWithdrawal::create([
            'affiliate_id' => $affiliate->id,
            'reference' => 'WDR-TEST-001',
            'amount' => 2000.00,
            'currency' => 'NGN',
            'status' => 'paid',
            'provider_reference' => 'WITHDRAW-REF-99',
        ]);

        DailyAggregate::create([
            'date' => now()->toDateString(),
            'visits' => 100,
            'unique_visitors' => 80,
            'page_views' => 250,
            'product_views' => 120,
            'add_to_carts' => 30,
            'checkouts_started' => 20,
            'checkouts_completed' => 15,
            'orders_placed' => 15,
            'orders_revenue' => 150000.00,
            'conversion_rate' => 15.00,
        ]);

        $types = [
            'orders',
            'customers',
            'products',
            'inventory',
            'affiliates',
            'commissions',
            'withdrawals',
            'revenue',
            'traffic',
            'conversion',
        ];

        foreach ($types as $type) {
            $response = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reports', [
                'type' => $type,
                'format' => 'csv',
            ]);

            $response->assertCreated();
            $report = AdminReport::findOrFail($response->json('data.id'));
            $this->assertEquals(AdminReport::STATUS_COMPLETED, $report->status, "Report type [{$type}] failed to complete.");
            $this->assertTrue(Storage::disk('local')->exists($report->file_path));
            $this->assertGreaterThan(0, $report->row_count, "Report type [{$type}] produced 0 rows.");
        }
    }

    public function test_idempotency_key_replays_existing_report_and_rejects_payload_mutation(): void
    {
        $admin = $this->createAdminUser();

        $headers = [
            'Idempotency-Key' => 'idemp-report-unique-key-100',
        ];

        // First call creates report
        $res1 = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/reports', [
                'type' => 'orders',
                'format' => 'csv',
            ], $headers);

        $res1->assertCreated();
        $id1 = $res1->json('data.id');

        // Replay with exact same payload
        $res2 = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/reports', [
                'type' => 'orders',
                'format' => 'csv',
            ], $headers);

        $res2->assertOk();
        $this->assertEquals($id1, $res2->json('data.id'));

        // Replay with different payload throws 422
        $res3 = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/reports', [
                'type' => 'orders',
                'format' => 'xlsx',
            ], $headers);

        $res3->assertStatus(422)
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_purpose_specific_permissions_for_reports(): void
    {
        $staffWithoutReportPerms = $this->createStaffUserWithPermissions(['analytics.read']);

        // Cannot read reports
        $this->actingAs($staffWithoutReportPerms, 'api')
            ->getJson('/api/v1/admin/reports')
            ->assertForbidden();

        // Cannot create reports
        $this->actingAs($staffWithoutReportPerms, 'api')
            ->postJson('/api/v1/admin/reports', ['type' => 'orders'])
            ->assertForbidden();

        // User with reports.create can create
        $staffWithCreate = $this->createStaffUserWithPermissions(['reports.create', 'reports.read']);

        $res = $this->actingAs($staffWithCreate, 'api')
            ->postJson('/api/v1/admin/reports', ['type' => 'orders']);

        $res->assertCreated();
    }

    public function test_temporary_signed_download_route_security(): void
    {
        $admin = $this->createAdminUser();

        $res = $this->actingAs($admin, 'api')->postJson('/api/v1/admin/reports', [
            'type' => 'orders',
            'format' => 'csv',
        ]);
        $reportId = $res->json('data.id');
        $report = AdminReport::findOrFail($reportId);

        // Show endpoint gives download url
        $showRes = $this->actingAs($admin, 'api')->getJson("/api/v1/admin/reports/{$reportId}");
        $showRes->assertOk();
        $downloadUrl = $showRes->json('data.download_url');
        $this->assertNotNull($downloadUrl);

        // Unsigned request fails
        $this->actingAs($admin, 'api')
            ->get("/api/v1/admin/reports/{$reportId}/download")
            ->assertForbidden();

        // Request without reports.download permission fails
        $staffWithoutDownload = $this->createStaffUserWithPermissions(['reports.read']);
        $this->actingAs($staffWithoutDownload, 'api')
            ->get($downloadUrl)
            ->assertForbidden();

        // Valid signed download with permission succeeds
        $downloadRes = $this->actingAs($admin, 'api')->get($downloadUrl);
        $downloadRes->assertOk();
        $downloadRes->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'report.downloaded',
            'subject_id' => $reportId,
        ]);

        // Expired report returns 410
        $report->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $expiredUrl = URL::temporarySignedRoute('admin.reports.download', now()->addMinutes(15), ['report' => $reportId]);
        $this->actingAs($admin, 'api')
            ->get($expiredUrl)
            ->assertStatus(410);
    }

    public function test_retry_failed_report_resets_status_and_redispatches(): void
    {
        $admin = $this->createAdminUser();

        $report = AdminReport::create([
            'user_id' => $admin->id,
            'type' => 'orders',
            'format' => 'csv',
            'status' => AdminReport::STATUS_FAILED,
            'parameters' => ['type' => 'orders', 'format' => 'csv'],
            'file_name' => 'report-orders-failed.csv',
            'file_path' => 'reports/report-orders-failed.csv',
            'error_message' => 'Simulated previous failure',
            'failed_at' => now()->subHour(),
        ]);

        $res = $this->actingAs($admin, 'api')->postJson("/api/v1/admin/reports/{$report->id}/retry");
        $res->assertOk();

        $fresh = $report->fresh();
        $this->assertEquals(1, $fresh->retry_count);
        $this->assertNull($fresh->error_message);
        $this->assertEquals(AdminReport::STATUS_COMPLETED, $fresh->status); // ran synchronously in test
        $this->assertTrue(Storage::disk('local')->exists($fresh->file_path));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'report.retried',
            'subject_id' => $report->id,
        ]);
    }

    public function test_prune_expired_reports_command_cleans_files_and_marks_expired(): void
    {
        $admin = $this->createAdminUser();

        $filePath = 'reports/expired-report.csv';
        Storage::disk('local')->put($filePath, 'id,number\n1,ORD-OLD');

        $report = AdminReport::create([
            'user_id' => $admin->id,
            'type' => 'orders',
            'format' => 'csv',
            'status' => AdminReport::STATUS_COMPLETED,
            'file_name' => 'expired-report.csv',
            'file_path' => $filePath,
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue(Storage::disk('local')->exists($filePath));

        $this->artisan('reports:prune')
            ->expectsOutput('Pruning expired reports...')
            ->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($filePath));
        $this->assertEquals(AdminReport::STATUS_EXPIRED, $report->fresh()->status);
        $this->assertNull($report->fresh()->file_path);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'report.expired',
            'subject_id' => $report->id,
        ]);
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

    /**
     * @param  list<string>  $permissionNames
     */
    private function createStaffUserWithPermissions(array $permissionNames): User
    {
        $user = User::factory()->create(['role' => 'staff']);
        $role = Role::create([
            'name' => 'custom-staff-'.uniqid(),
            'display_name' => 'Custom Staff',
            'is_super_admin' => false,
        ]);

        $permIds = [];
        foreach ($permissionNames as $pName) {
            $perm = Permission::firstOrCreate(['name' => $pName], [
                'domain' => 'reports',
                'description' => $pName,
            ]);
            $permIds[] = $perm->id;
        }

        $role->permissions()->sync($permIds);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }
}
