<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review only. Do not run this migration against a deployed database until it
 * has been explicitly approved. It creates an empty table and does not assign
 * existing medicines. See docs/blynk-schedule-dry-run.md for the approval steps.
 *
 * Foreign keys follow the existing bigint unsigned keys on users, medicines,
 * and medicine_schedules. MySQL 8.0.16+ enforces the slot CHECK constraint.
 * medicine_id is unique on its own, so one medicine cannot occupy two slots
 * or two users. (medicine_id, user_id) must match medicines, and
 * (schedule_id, medicine_id) must match medicine_schedules. Both foreign keys
 * restrict deletes. user_id cascades, matching medicines and devices.
 * SQLite cannot add the slot CHECK with ALTER TABLE, so that constraint is
 * created only on MySQL and MariaDB. The API still rejects slots outside 1-3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->unique(['id', 'user_id'], 'medicines_id_user_unique');
        });

        Schema::table('medicine_schedules', function (Blueprint $table) {
            $table->unique(['id', 'medicine_id'], 'medicine_schedules_id_medicine_unique');
        });

        Schema::create('device_schedule_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->unsignedBigInteger('medicine_id');
            $table->unsignedBigInteger('schedule_id');
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->unique(['user_id', 'slot']);
            $table->unique('medicine_id');
            $table->unique('schedule_id');

            $table->foreign(['medicine_id', 'user_id'], 'device_schedule_slots_medicine_user_fk')
                ->references(['id', 'user_id'])
                ->on('medicines')
                ->restrictOnDelete();

            $table->foreign(['schedule_id', 'medicine_id'], 'device_schedule_slots_schedule_medicine_fk')
                ->references(['id', 'medicine_id'])
                ->on('medicine_schedules')
                ->restrictOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::getConnection()->statement(
                'alter table device_schedule_slots add constraint device_schedule_slots_slot_range check (slot >= 1 and slot <= 3)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_schedule_slots');

        Schema::table('medicine_schedules', function (Blueprint $table) {
            $table->dropUnique('medicine_schedules_id_medicine_unique');
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropUnique('medicines_id_user_unique');
        });
    }
};
