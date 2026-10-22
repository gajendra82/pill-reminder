<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive table for demo activity. Do not run this against production until
 * it has been explicitly approved. It does not change dose_events.
 *
 * dose_events.status is a fixed enum and dose_events has one row per schedule
 * occurrence. Demo activity cannot be stored there without either colliding
 * with a real dose or being counted as real adherence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_schedule_id')
                ->constrained('medicine_schedules')
                ->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->dateTime('scheduled_for');
            $table->dateTime('occurred_at');
            $table->string('confirmation_method', 40);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['medicine_schedule_id', 'event_type', 'scheduled_for'],
                'demo_activity_occurrence_unique'
            );
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_activity_events');
    }
};
