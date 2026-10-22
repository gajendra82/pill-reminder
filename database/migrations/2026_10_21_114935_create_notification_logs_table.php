<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_member_id')
                ->nullable()
                ->constrained('family_members')
                ->nullOnDelete();

            $table->foreignId('dose_event_id')
                ->nullable()
                ->constrained('dose_events')
                ->nullOnDelete();

            $table->string('channel', 30)->default('fcm');
            $table->string('notification_type', 50);
            $table->string('title', 150);
            $table->text('message');
            $table->string('status', 30)->default('pending');
            $table->string('provider_message_id', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['notification_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
