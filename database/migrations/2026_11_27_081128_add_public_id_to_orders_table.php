<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // PostgreSQL cannot build an index concurrently inside a transaction.
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->migrateWithSchemaBuilder();

            return;
        }

        // Set the default before releasing the lock so older writers also get a UUID.
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE orders ADD COLUMN IF NOT EXISTS public_id uuid');
            DB::statement('ALTER TABLE orders ALTER COLUMN public_id SET DEFAULT gen_random_uuid()');
        });

        DB::table('orders')->whereNull('public_id')->select('id')->chunkById(1000, function ($orders): void {
            DB::table('orders')->whereIn('id', $orders->pluck('id'))->whereNull('public_id')
                ->update(['public_id' => DB::raw('gen_random_uuid()')]);
        });

        // A failed concurrent build may leave an invalid index with the same name.
        $invalidIndex = DB::selectOne(
            "SELECT 1 FROM pg_index WHERE indexrelid = to_regclass('orders_public_id_unique') AND NOT indisvalid"
        );
        if ($invalidIndex !== null) {
            DB::statement('DROP INDEX CONCURRENTLY orders_public_id_unique');
        }
        DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS orders_public_id_unique ON orders (public_id)');

        $checkExists = DB::selectOne(
            "SELECT 1 FROM pg_constraint WHERE conrelid = 'orders'::regclass AND conname = 'orders_public_id_not_null'"
        );
        if ($checkExists === null) {
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_public_id_not_null CHECK (public_id IS NOT NULL) NOT VALID');
        }

        // Validate without blocking writes. The validated check lets SET NOT NULL skip a table scan.
        DB::statement('ALTER TABLE orders VALIDATE CONSTRAINT orders_public_id_not_null');
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE orders ALTER COLUMN public_id SET NOT NULL');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_public_id_not_null');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('orders', fn (Blueprint $table) => $table->dropUnique(['public_id']));
        }

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('public_id'));
    }

    private function migrateWithSchemaBuilder(): void
    {
        // SQLite rebuilds the table when setting NOT NULL. Disable foreign keys before the transaction.
        Schema::withoutForeignKeyConstraints(fn () => DB::transaction(function (): void {
            Schema::table('orders', fn (Blueprint $table) => $table->uuid('public_id')->nullable());

            DB::table('orders')->select('id')->chunkById(1000, function ($orders): void {
                foreach ($orders as $order) {
                    DB::table('orders')->where('id', $order->id)->update(['public_id' => (string) Str::uuid()]);
                }
            });

            Schema::table('orders', function (Blueprint $table): void {
                $table->uuid('public_id')->nullable(false)->change();
                $table->unique('public_id');
            });
        }));
    }
};
