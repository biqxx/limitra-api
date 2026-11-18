<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('redstar_city_code', 20)->nullable()->after('postal_code');
            $table->unsignedBigInteger('redstar_town_id')->nullable()->after('redstar_city_code');
            $table->index(['redstar_city_code', 'redstar_town_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('weight_kg', 8, 3)->nullable()->after('price');
        });

        // Remove legacy non-RedStar delivery addresses and static delivery rules
        // without breaking foreign keys on historical orders or checkout quotes.
        $referencedAddressIds = DB::table('orders')
            ->whereNotNull('shipping_address_id')
            ->pluck('shipping_address_id')
            ->merge(DB::table('checkout_quotes')->whereNotNull('address_id')->pluck('address_id'))
            ->unique()
            ->values()
            ->all();

        DB::table('addresses')
            ->where('type', 'delivery')
            ->when($referencedAddressIds !== [], fn ($q) => $q->whereNotIn('id', $referencedAddressIds))
            ->delete();

        DB::table('pickup_locations')->delete();
        DB::table('delivery_zone_method')->delete();
        DB::table('delivery_zones')->delete();

        $referencedMethodIds = DB::table('checkout_quotes')
            ->whereNotNull('delivery_method_id')
            ->pluck('delivery_method_id')
            ->unique()
            ->values()
            ->all();

        if ($referencedMethodIds !== []) {
            DB::table('delivery_methods')
                ->whereIn('id', $referencedMethodIds)
                ->update(['active' => false, 'updated_at' => now()]);
        }

        DB::table('delivery_methods')
            ->when($referencedMethodIds !== [], fn ($q) => $q->whereNotIn('id', $referencedMethodIds))
            ->delete();

        $now = now();
        $zoneId = DB::table('delivery_zones')->insertGetId([
            'name' => 'Nigeria (Red Star Express)',
            'country' => 'NG',
            'states' => null,
            'cities' => null,
            'priority' => 100,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $methodId = DB::table('delivery_methods')->insertGetId([
            'code' => 'redstar',
            'name' => 'Red Star Express Delivery',
            'type' => 'standard',
            'description' => 'Doorstep and express centre delivery powered by Red Star Logistics.',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('delivery_zone_method')->insert([
            'delivery_zone_id' => $zoneId,
            'delivery_method_id' => $methodId,
            'fee' => 0,
            'minimum_order' => null,
            'free_shipping_threshold' => null,
            'estimated_days_min' => 1,
            'estimated_days_max' => 5,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('weight_kg');
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropIndex(['redstar_city_code', 'redstar_town_id']);
            $table->dropColumn(['redstar_city_code', 'redstar_town_id']);
        });
    }
};

