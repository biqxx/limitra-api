<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();

            // One affiliate account per user.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Public-facing identifiers used in tracking links.
            //   slug → human-readable: /ref/john-doe
            //   code → short token:    ?ref=ABC123
            $table->string('slug')->unique();
            $table->string('code', 12)->unique();

            // Default commission the affiliate earns on each qualifying sale (%).
            $table->decimal('commission_rate', 5, 2)->default(5.00);

            // pending → awaiting admin approval
            // active  → can generate commissions
            // suspended → account frozen
            $table->enum('status', ['pending', 'active', 'suspended'])->default('pending')->index();

            // Flexible JSON for payout account details:
            // { bank_name, account_name, account_number, routing_number, notes }
            $table->json('payout_details')->nullable();

            // Running totals maintained for fast dashboard queries.
            $table->decimal('total_earnings', 12, 2)->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
