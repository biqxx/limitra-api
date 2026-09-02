<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_event_settings', function (Blueprint $table): void {
            $table->string('event', 120)->primary();
            $table->string('label', 160);
            $table->string('category', 80)->index();
            $table->json('available_channels');
            $table->json('default_channels');
            $table->json('required_channels');
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 120);
            $table->string('channel', 40);
            $table->boolean('enabled');
            $table->timestamps();

            $table->foreign('event')
                ->references('event')
                ->on('notification_event_settings')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unique(['user_id', 'event', 'channel']);
        });

        $now = now();
        DB::table('notification_event_settings')->insert([
            $this->event('account.login', 'New login detected', 'security', $now),
            $this->event('order.inventory_reservation_expired', 'Order reservation expired', 'orders', $now),
            $this->event('refund.initiated', 'Refund started', 'refunds', $now),
            $this->event('refund.processed', 'Refund completed', 'refunds', $now),
            $this->event('refund.needs_attention', 'Refund needs attention', 'refunds', $now),
            $this->event('refund.staff_attention_required', 'Refund staff alert', 'operations', $now),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notification_event_settings');
    }

    private function event(string $event, string $label, string $category, mixed $now): array
    {
        return [
            'event' => $event,
            'label' => $label,
            'category' => $category,
            'available_channels' => json_encode(['database', 'mail'], JSON_THROW_ON_ERROR),
            'default_channels' => json_encode(['database', 'mail'], JSON_THROW_ON_ERROR),
            'required_channels' => json_encode(['database'], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
