<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('family_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('relationship', 50)->nullable();
            $table->boolean('notify_missed_dose')->default(true);
            $table->boolean('notify_device_offline')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['patient_user_id', 'family_user_id']);
            $table->index(['family_user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};
