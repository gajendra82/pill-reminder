<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->time('scheduled_time');
            $table->json('days_of_week')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('grace_period_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['medicine_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_schedules');
    }
};
