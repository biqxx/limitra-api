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
        Schema::create('support_ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_message_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->timestamps();

            $table->index(['support_ticket_message_id', 'id']);
        });

        Schema::create('support_ticket_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20);
            $table->string('event', 60);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['support_ticket_id', 'created_at']);
        });

        $now = now();
        DB::table('business_settings')->insert([
            [
                'key' => 'support.sla_warning_minutes',
                'group' => 'support',
                'label' => 'Support SLA warning window',
                'description' => 'Minutes before an SLA deadline when a ticket is considered due soon.',
                'type' => 'integer',
                'value' => json_encode(30, JSON_THROW_ON_ERROR),
                'constraints' => json_encode(['min' => 5, 'max' => 1440], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('notification_event_settings')->insert([
            $this->notificationEvent(
                'support.ticket.staff_action_required',
                'Support ticket needs attention',
                'operations',
                $now,
            ),
            $this->notificationEvent(
                'support.ticket.customer_update',
                'Support ticket updated',
                'support',
                $now,
            ),
        ]);

        Cache::forget('business_settings.values.v1');
    }

    public function down(): void
    {
        $events = [
            'support.ticket.staff_action_required',
            'support.ticket.customer_update',
        ];
        DB::table('notification_preferences')->whereIn('event', $events)->delete();
        DB::table('notification_event_settings')->whereIn('event', $events)->delete();
        DB::table('business_settings')->where('key', 'support.sla_warning_minutes')->delete();
        Cache::forget('business_settings.values.v1');

        Schema::dropIfExists('support_ticket_events');
        Schema::dropIfExists('support_ticket_attachments');
    }

    private function notificationEvent(string $event, string $label, string $category, mixed $now): array
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
