<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['name' => 'mail_logs.read'],
            [
                'domain' => 'mail_logs',
                'description' => 'Read non-production test email logs.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'mail_logs.read')->delete();
    }
};
