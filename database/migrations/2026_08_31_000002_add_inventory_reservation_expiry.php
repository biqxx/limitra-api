<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->index(['status', 'expires_at'], 'inventory_reservations_expiry_idx');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('cancellation_code', 60)->nullable()->after('cancellation_reason')->index();
        });

        DB::table('business_settings')->updateOrInsert(
            ['key' => 'orders.inventory_reservation_minutes'],
            [
                'group' => 'orders',
                'label' => 'Inventory reservation window',
                'description' => 'Minutes that stock remains reserved while an online order awaits payment.',
                'type' => 'integer',
                'value' => json_encode(30, JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min' => 5, 'max' => 1440], JSON_THROW_ON_ERROR),
                'is_public' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        DB::table('business_settings')->where('key', 'orders.inventory_reservation_minutes')->delete();
        Cache::forget('business_settings.values.v1');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cancellation_code');
        });

        Schema::table('inventory_reservations', function (Blueprint $table) {
            $table->dropIndex('inventory_reservations_expiry_idx');
            $table->dropColumn('expires_at');
        });
    }
};
