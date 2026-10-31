<?php

// This migration was originally "add avatar/phone to users".
// Those fields now live in the profiles table (see 2026_04_08_000012).
// Kept as a no-op so migration history is not disturbed.

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
