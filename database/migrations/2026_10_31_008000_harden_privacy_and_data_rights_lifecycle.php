<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Add file path and completed_at to account_data_exports
        Schema::table('account_data_exports', function (Blueprint $table): void {
            if (! Schema::hasColumn('account_data_exports', 'file_path')) {
                $table->string('file_path')->nullable()->after('status');
            }
            if (! Schema::hasColumn('account_data_exports', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('expires_at');
            }
        });

        // 2. Add anonymized_at to users
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'anonymized_at')) {
                $table->timestamp('anonymized_at')->nullable()->after('deletion_scheduled_for');
            }
        });

        // 3. Add is_age_gated to products
        if (Schema::hasTable('products') && ! Schema::hasColumn('products', 'is_age_gated')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->boolean('is_age_gated')->default(false)->after('status');
            });
        }

        // 4. Add is_age_gated to categories
        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'is_age_gated')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->boolean('is_age_gated')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_data_exports', function (Blueprint $table): void {
            $table->dropColumn(['file_path', 'completed_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['anonymized_at']);
        });

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'is_age_gated')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropColumn(['is_age_gated']);
            });
        }

        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'is_age_gated')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropColumn(['is_age_gated']);
            });
        }
    }
};
