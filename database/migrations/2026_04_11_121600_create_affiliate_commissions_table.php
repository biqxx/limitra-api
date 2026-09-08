<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One commission record per affiliate_sale.
        // Stores the financial details and payment lifecycle separately from the sale itself.
        Schema::create('affiliate_commissions', function (Blueprint $table) {
            $table->id();

            // Denormalised affiliate_id allows fast aggregate queries
            // without always joining through affiliate_sales.
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('affiliate_sale_id')->unique()->constrained()->cascadeOnDelete();

            // Snapshot: rate captured at time of sale, not recalculated retroactively.
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 10, 2);

            // Payment lifecycle: pending → approved → paid
            //                    pending → rejected
            $table->enum('payment_status', ['pending', 'approved', 'paid', 'rejected'])
                ->default('pending')
                ->index();

            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('affiliate_id');
            $table->index(['affiliate_id', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_commissions');
    }
};
