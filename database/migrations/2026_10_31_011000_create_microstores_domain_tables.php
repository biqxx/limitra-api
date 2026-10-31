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
        if (! Schema::hasTable('microstores')) {
            Schema::create('microstores', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('slug', 100)->unique();
                $table->string('tagline', 255)->nullable();
                $table->text('bio')->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('banner_url', 500)->nullable();
                $table->string('theme_color', 20)->default('#000000');
                $table->string('status', 30)->default('active')->index();
                $table->string('moderation_status', 30)->default('approved')->index();
                $table->text('moderation_notes')->nullable();
                $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('moderated_at')->nullable();
                $table->boolean('is_featured')->default(false)->index();
                $table->unsignedInteger('views_count')->default(0);
                $table->unsignedInteger('orders_count')->default(0);
                $table->decimal('total_revenue', 15, 2)->default(0.00);
                $table->json('settings')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('microstore_products')) {
            Schema::create('microstore_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('microstore_id')->constrained('microstores')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('custom_title', 255)->nullable();
                $table->text('custom_description')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_pinned')->default(false);
                $table->timestamps();

                $table->unique(['microstore_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('microstore_events')) {
            Schema::create('microstore_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('microstore_id')->constrained('microstores')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 50)->index();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('session_id', 100)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['microstore_id', 'event_type']);
            });
        }

        $now = now();
        $permissions = [
            'microstores.read' => ['domain' => 'microstores', 'description' => 'View microstores and analytics.'],
            'microstores.manage' => ['domain' => 'microstores', 'description' => 'Moderate and manage microstores.'],
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
        Schema::dropIfExists('microstore_events');
        Schema::dropIfExists('microstore_products');
        Schema::dropIfExists('microstores');
    }
};
