<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('supplier_orders')) {
            Schema::create('supplier_orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->string('supplier', 50)->index();
                $table->string('external_supplier_order_id', 100)->nullable()->index();
                $table->string('status', 50)->default('pending')->index();
                $table->string('carrier', 100)->nullable();
                $table->string('tracking_number', 150)->nullable()->index();
                $table->decimal('procurement_cost_amount', 15, 2)->default(0.00);
                $table->char('procurement_cost_currency', 3)->default('CNY');
                $table->decimal('exchange_rate', 12, 4)->default(1.0000);
                $table->decimal('local_cost_amount', 15, 2)->default(0.00);
                $table->decimal('shipping_cost_amount', 15, 2)->default(0.00);
                $table->decimal('customs_cost_amount', 15, 2)->default(0.00);
                $table->decimal('weight_kg', 8, 3)->nullable();
                $table->decimal('volumetric_weight_kg', 8, 3)->nullable();
                $table->timestamp('purchased_at')->nullable();
                $table->timestamp('received_at_hub_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_order_events')) {
            Schema::create('supplier_order_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('supplier_order_id')->constrained('supplier_orders')->cascadeOnDelete();
                $table->string('status', 50);
                $table->text('description');
                $table->string('location', 100)->nullable();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('source', 30)->default('system');
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['supplier_order_id', 'created_at']);
            });
        }

        $now = now();
        $permissions = [
            'procurement.read' => ['domain' => 'procurement', 'description' => 'View supplier orders and procurement status.'],
            'procurement.manage' => ['domain' => 'procurement', 'description' => 'Manage supplier orders, purchasing, and logistics.'],
        ];

        foreach ($permissions as $name => $data) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'domain' => $data['domain'],
                'description' => $data['description'],
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_order_events');
        Schema::dropIfExists('supplier_orders');
    }
};
