<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dose_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_schedule_id')
                ->constrained('medicine_schedules')
                ->cascadeOnDelete();

            $table->dateTime('scheduled_for');
            $table->dateTime('reminder_triggered_at')->nullable();
            $table->dateTime('sensor_detected_at')->nullable();
            $table->dateTime('confirmed_taken_at')->nullable();
            $table->dateTime('missed_at')->nullable();

            $table->enum('status', [
                'pending',
                'reminded',
                'sensor_detected',
                'taken',
                'missed',
                'skipped',
            ])->default('pending');

            $table->string('confirmation_method', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['medicine_schedule_id', 'scheduled_for'],
                'dose_schedule_time_unique'
            );
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dose_events');
    }
};
