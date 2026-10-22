<?php

namespace Tests\Feature;

use App\Models\DeviceScheduleSlot;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use App\Services\SlotAssignmentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScheduleSlotAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->fail('Slot assignment tests must use the isolated sqlite memory database.');
        }

        config([
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'user_id'], 'medicines_id_user_unique');
        });

        Schema::create('medicine_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->string('scheduled_time');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
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

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_assignments_stay_in_place_when_a_lower_id_medicine_is_added(): void
    {
        $user = $this->authenticate('owner@example.com');
        $middle = $this->medicine($user, 10, 'Middle');
        $highest = $this->medicine($user, 20, 'Highest');
        $this->schedule($middle, '11:00');
        $this->schedule($highest, '18:00');

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $middle->id])->assertCreated();
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $highest->id])->assertCreated();

        $lower = $this->medicine($user, 5, 'Lower');
        $this->schedule($lower, '07:00');

        $this->getJson('/api/device/schedule-sync/dry-run')
            ->assertOk()
            ->assertJsonPath('sync_mode', 'dry_run')
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slot_stability', 'persistent')
            ->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.status', 'unused');

        $this->assertNotContains(5, array_column(
            $this->getJson('/api/device/schedule-slots')->assertOk()->json('slots'),
            'medicine_id'
        ));
        Http::assertNothingSent();
    }

    public function test_deleting_an_unrelated_medicine_does_not_move_occupied_slots(): void
    {
        $user = $this->authenticate('owner@example.com');
        $first = $this->medicine($user, 10, 'First');
        $second = $this->medicine($user, 20, 'Second');
        $this->schedule($first, '08:00');
        $this->schedule($second, '12:00');
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $first->id])->assertCreated();
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $second->id])->assertCreated();

        $unrelated = $this->medicine($user, 5, 'Unrelated');
        $this->schedule($unrelated, '07:00');
        $unrelated->delete();

        $this->getJson('/api/device/schedule-slots')
            ->assertOk()
            ->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.status', 'unused');

        Http::assertNothingSent();
    }

    public function test_deletion_and_deactivation_do_not_reassign_other_slots(): void
    {
        $user = $this->authenticate('owner@example.com');
        $first = $this->medicine($user, 10, 'First');
        $second = $this->medicine($user, 20, 'Second');
        $third = $this->medicine($user, 30, 'Third');
        $this->schedule($first, '08:00');
        $this->schedule($second, '12:00');
        $this->schedule($third, '18:00');

        foreach ([$first, $second, $third] as $medicine) {
            $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])->assertCreated();
        }

        $second->is_active = false;
        $second->save();

        $this->assertSame(2, DeviceScheduleSlot::query()->where('medicine_id', 20)->value('slot'));
        $this->getJson('/api/device/schedule-slots')
            ->assertOk()
            ->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.medicine_id', 30);

        try {
            $first->delete();
            $this->fail('An occupied medicine must stay until its slot is released.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNotNull(Medicine::query()->find(10));
        }

        $this->postJson('/api/device/schedule-slots/1/release')
            ->assertOk()
            ->assertJsonPath('result', 'released');

        $this->getJson('/api/device/schedule-slots')
            ->assertOk()
            ->assertJsonPath('slots.0.status', 'unused')
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.medicine_id', 30);

        $replacement = $this->medicine($user, 40, 'Replacement');
        $this->schedule($replacement, '09:00');
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $replacement->id])
            ->assertCreated()
            ->assertJsonPath('slot.slot', 1)
            ->assertJsonPath('slot.medicine_id', 40);

        $this->assertSame(2, (int) DeviceScheduleSlot::query()->where('medicine_id', 20)->value('slot'));
        $this->assertSame(3, (int) DeviceScheduleSlot::query()->where('medicine_id', 30)->value('slot'));
        Http::assertNothingSent();
    }

    public function test_a_fourth_medicine_gets_no_free_slot(): void
    {
        $user = $this->authenticate('owner@example.com');

        foreach ([1, 2, 3, 4] as $id) {
            $medicine = $this->medicine($user, $id, 'Medicine '.$id);
            $this->schedule($medicine, sprintf('0%d:00', $id));

            $response = $this->postJson('/api/device/schedule-slots', ['medicine_id' => $id]);

            if ($id < 4) {
                $response->assertCreated()->assertJsonPath('slot.slot', $id);
            } else {
                $response->assertStatus(409)->assertJsonPath('result', 'no_free_slot');
            }
        }

        $this->assertSame(3, DeviceScheduleSlot::query()->count());
        $this->assertNull(DeviceScheduleSlot::query()->where('medicine_id', 4)->first());
        Http::assertNothingSent();
    }

    public function test_sqlite_lock_clause_is_empty_and_uniqueness_guards_conflicts(): void
    {
        $grammar = new \ReflectionMethod(DB::connection()->getQueryGrammar(), 'compileLock');
        $builder = DB::table('device_schedule_slots');

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame('', $grammar->invoke(DB::connection()->getQueryGrammar(), $builder, true));
    }

    public function test_unique_slot_constraint_rejects_a_second_row_for_the_same_slot(): void
    {
        $user = $this->authenticate('owner@example.com');
        $first = $this->medicine($user, 10, 'First');
        $second = $this->medicine($user, 20, 'Second');
        $firstSchedule = $this->schedule($first, '08:00');
        $secondSchedule = $this->schedule($second, '09:00');
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $first->id])->assertCreated();

        try {
            DeviceScheduleSlot::query()->create([
                'user_id' => $user->id,
                'slot' => 1,
                'medicine_id' => $second->id,
                'schedule_id' => $secondSchedule->id,
                'assigned_at' => Carbon::now(),
            ]);
            $this->fail('The unique user and slot constraint did not reject the conflicting row.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DeviceScheduleSlot::query()->count());
            $this->assertSame($firstSchedule->id, (int) DeviceScheduleSlot::query()->where('slot', 1)->value('schedule_id'));
        }
    }

    public function test_cross_user_access_is_denied(): void
    {
        $owner = $this->authenticate('owner@example.com');
        $medicine = $this->medicine($owner, 10, 'Owner medicine');
        $schedule = $this->schedule($medicine, '08:00');
        $this->postJson('/api/device/schedule-slots', [
            'medicine_id' => $medicine->id,
            'user_id' => $owner->id,
        ])->assertStatus(422)->assertJsonPath('result', 'user_id_not_accepted');

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])
            ->assertCreated()
            ->assertJsonPath('slot.slot', 1);

        $other = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => 'password1234',
        ]);
        $otherMedicine = $this->medicine($other, 11, 'Other medicine');
        $this->schedule($otherMedicine, '09:00');
        Sanctum::actingAs($other, ['*']);

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])
            ->assertNotFound()
            ->assertJsonPath('result', 'not_found');

        $this->postJson('/api/device/schedule-slots/1/release')->assertNotFound();
        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $otherMedicine->id,
            'schedule_id' => $otherMedicine->schedules()->first()->id,
            'confirmed' => true,
        ])->assertNotFound();

        $this->getJson('/api/device/schedule-slots')
            ->assertOk()
            ->assertJsonPath('slots.0.status', 'unused');

        $this->assertSame($schedule->id, (int) DeviceScheduleSlot::query()->where('user_id', $owner->id)->value('schedule_id'));
        Http::assertNothingSent();
    }

    public function test_multiple_active_schedules_are_rejected_and_replacement_is_explicit(): void
    {
        $user = $this->authenticate('owner@example.com');
        $medicine = $this->medicine($user, 10, 'Split');
        $morning = $this->schedule($medicine, '08:00');
        $evening = $this->schedule($medicine, '20:00');

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])
            ->assertStatus(422)
            ->assertJsonPath('result', 'unsupported_multiple_schedules_for_medicine')
            ->assertJsonPath('schedule_ids', [$morning->id, $evening->id]);

        $evening->is_active = false;
        $evening->save();

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])
            ->assertCreated()
            ->assertJsonPath('slot.schedule_id', $morning->id)
            ->assertJsonPath('slot.slot', 1);

        $evening->is_active = true;
        $evening->save();

        $this->postJson('/api/device/schedule-slots', [
            'medicine_id' => $medicine->id,
            'schedule_id' => $evening->id,
        ])->assertStatus(409)
            ->assertJsonPath('result', 'already_assigned')
            ->assertJsonPath('slot.schedule_id', $morning->id)
            ->assertJsonPath('schedule_replacement_required', true);

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $medicine->id,
            'schedule_id' => $evening->id,
            'confirmed' => false,
        ])->assertStatus(422)->assertJsonPath('result', 'confirmation_required');

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $medicine->id,
            'schedule_id' => $evening->id,
            'confirmed' => true,
        ])->assertStatus(422)->assertJsonPath('result', 'unsupported_multiple_schedules_for_medicine');

        $this->assertSame($morning->id, (int) DeviceScheduleSlot::query()->value('schedule_id'));

        $morning->is_active = false;
        $morning->save();

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $medicine->id,
            'schedule_id' => $evening->id,
            'confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('result', 'replaced')
            ->assertJsonPath('slot.slot', 1)
            ->assertJsonPath('slot.schedule_id', $evening->id);

        $this->getJson('/api/device/schedule-sync/dry-run')
            ->assertOk()
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('sync_mode', 'dry_run')
            ->assertJsonPath('slots.0.schedule_id', $evening->id)
            ->assertJsonPath('slots.0.slot', 1);

        Http::assertNothingSent();
    }

    public function test_a_medicine_or_schedule_cannot_be_stored_for_another_user(): void
    {
        $owner = $this->authenticate('owner@example.com');
        $medicine = $this->medicine($owner, 10, 'Owner medicine');
        $schedule = $this->schedule($medicine, '08:00');
        $otherMedicine = $this->medicine($owner, 11, 'Other medicine');
        $otherSchedule = $this->schedule($otherMedicine, '09:00');
        $other = User::create([
            'name' => 'Other',
            'email' => 'other@example.com',
            'password' => 'password1234',
        ]);

        try {
            DeviceScheduleSlot::query()->create([
                'user_id' => $other->id,
                'slot' => 1,
                'medicine_id' => $medicine->id,
                'schedule_id' => $schedule->id,
                'assigned_at' => Carbon::now(),
            ]);
            $this->fail('Another user must not be able to store this medicine.');
        } catch (QueryException) {
            $this->assertSame(0, DeviceScheduleSlot::query()->count());
        }

        try {
            DeviceScheduleSlot::query()->create([
                'user_id' => $owner->id,
                'slot' => 1,
                'medicine_id' => $medicine->id,
                'schedule_id' => $otherSchedule->id,
                'assigned_at' => Carbon::now(),
            ]);
            $this->fail('A schedule must not be stored against a different medicine.');
        } catch (QueryException) {
            $this->assertSame(0, DeviceScheduleSlot::query()->count());
        }

        $this->assertNotNull($other->id);
        $this->assertNotNull($otherSchedule->id);
    }

    public function test_deleting_an_assigned_medicine_returns_a_slot_release_error(): void
    {
        $user = $this->authenticate('owner@example.com');
        $assigned = $this->medicine($user, 10, 'Assigned');
        $free = $this->medicine($user, 20, 'Free');
        $this->schedule($assigned, '08:00');
        $this->schedule($free, '09:00');
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $assigned->id])
            ->assertCreated()
            ->assertJsonPath('slot.slot', 1);
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $free->id])->assertCreated();

        $this->deleteJson('/api/medicines/'.$assigned->id)
            ->assertStatus(409)
            ->assertJsonPath('result', 'slot_release_required')
            ->assertJsonPath('slot', 1)
            ->assertJsonPath('message', 'Release the reminder slot before deleting this medicine.');

        $this->assertNotNull(Medicine::query()->find($assigned->id));
        $this->assertSame(1, (int) DeviceScheduleSlot::query()->where('medicine_id', $assigned->id)->value('slot'));
        $this->assertSame(2, (int) DeviceScheduleSlot::query()->where('medicine_id', $free->id)->value('slot'));

        $this->deleteJson('/api/medicines/'.$free->id)
            ->assertStatus(409)
            ->assertJsonPath('result', 'slot_release_required')
            ->assertJsonPath('slot', 2);

        $this->postJson('/api/device/schedule-slots/2/release')->assertOk();

        $this->deleteJson('/api/medicines/'.$free->id)
            ->assertOk()
            ->assertJsonPath('message', 'Medicine deleted successfully.');

        $this->assertNull(Medicine::query()->find($free->id));
        $this->assertSame(10, (int) DeviceScheduleSlot::query()->value('medicine_id'));
        $this->assertSame(1, (int) DeviceScheduleSlot::query()->value('slot'));
        Http::assertNothingSent();
    }

    public function test_replacement_requires_confirmation_and_does_not_take_another_occupied_slot(): void
    {
        $user = $this->authenticate('owner@example.com');
        $first = $this->medicine($user, 10, 'First');
        $second = $this->medicine($user, 20, 'Second');
        $third = $this->medicine($user, 30, 'Third');
        $firstSchedule = $this->schedule($first, '08:00');
        $secondSchedule = $this->schedule($second, '12:00');
        $thirdSchedule = $this->schedule($third, '18:00');
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $first->id])->assertCreated();
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $second->id])->assertCreated();

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $second->id,
            'schedule_id' => $secondSchedule->id,
            'confirmed' => false,
        ])->assertStatus(422)->assertJsonPath('result', 'confirmation_required');

        $this->assertSame($firstSchedule->id, (int) DeviceScheduleSlot::query()->where('slot', 1)->value('schedule_id'));
        $this->assertSame($secondSchedule->id, (int) DeviceScheduleSlot::query()->where('slot', 2)->value('schedule_id'));

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $second->id,
            'schedule_id' => $secondSchedule->id,
            'confirmed' => true,
        ])->assertStatus(409)->assertJsonPath('result', 'medicine_already_assigned');

        $this->assertSame(10, (int) DeviceScheduleSlot::query()->where('slot', 1)->value('medicine_id'));
        $this->assertSame(20, (int) DeviceScheduleSlot::query()->where('slot', 2)->value('medicine_id'));

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $third->id,
            'schedule_id' => $thirdSchedule->id,
            'confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('result', 'replaced')
            ->assertJsonPath('slot.slot', 1)
            ->assertJsonPath('slot.medicine_id', 30);

        $this->assertSame(20, (int) DeviceScheduleSlot::query()->where('slot', 2)->value('medicine_id'));
        $this->assertNull(DeviceScheduleSlot::query()->where('medicine_id', 10)->first());
        Http::assertNothingSent();
    }

    public function test_slot_routes_require_authentication_and_do_not_call_blynk(): void
    {
        $this->getJson('/api/device/schedule-slots')->assertUnauthorized();
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => 1])->assertUnauthorized();
        $this->postJson('/api/device/schedule-slots/1/release')->assertUnauthorized();
        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => 1,
            'schedule_id' => 1,
            'confirmed' => true,
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_create_or_change_slot_rows(): void
    {
        $user = $this->authenticate('owner@example.com');
        $medicine = $this->medicine($user, 10, 'Aspirin');
        $this->schedule($medicine, '08:00');
        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])->assertCreated();
        $before = DeviceScheduleSlot::query()->get()->map->only(['slot', 'medicine_id', 'schedule_id'])->all();

        $this->getJson('/api/device/schedule-sync/dry-run')
            ->assertOk()
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('can_apply', true);

        $this->assertSame($before, DeviceScheduleSlot::query()->get()->map->only(['slot', 'medicine_id', 'schedule_id'])->all());
        Http::assertNothingSent();
    }

    public function test_slot_screen_contract_rejects_invalid_input_without_deleting_medicines(): void
    {
        $user = $this->authenticate('owner@example.com');
        $medicine = $this->medicine($user, 10, 'Aspirin');
        $schedule = $this->schedule($medicine, '08:30');
        $replacement = $this->medicine($user, 20, 'Vitamin');
        $replacementSchedule = $this->schedule($replacement, '21:00');

        $this->getJson('/api/device/schedule-slots')
            ->assertOk()
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slots.0', [
                'slot' => 1,
                'status' => 'unused',
                'medicine_id' => null,
                'schedule_id' => null,
                'assigned_at' => null,
                'hour_pin' => 'V1',
                'minute_pin' => 'V2',
            ])
            ->assertJsonPath('slots.1.hour_pin', 'V3')
            ->assertJsonPath('slots.1.minute_pin', 'V4')
            ->assertJsonPath('slots.2.hour_pin', 'V5')
            ->assertJsonPath('slots.2.minute_pin', 'V6');

        $this->assertStringNotContainsString('V7', $this->getJson('/api/device/schedule-slots')->getContent());

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => 999])
            ->assertNotFound()
            ->assertJsonPath('result', 'not_found');
        $this->assertSame(0, DeviceScheduleSlot::query()->count());

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])
            ->assertCreated()
            ->assertJsonPath('slot.slot', 1)
            ->assertJsonPath('slot.hour_pin', 'V1')
            ->assertJsonPath('slot.minute_pin', 'V2');

        $this->postJson('/api/device/schedule-slots', ['medicine_id' => $medicine->id])
            ->assertStatus(409)
            ->assertJsonPath('result', 'already_assigned')
            ->assertJsonPath('slot.slot', 1);

        foreach ([0, 4, 9] as $slot) {
            $this->postJson('/api/device/schedule-slots/'.$slot.'/release')
                ->assertStatus(422)
                ->assertJsonPath('result', 'invalid_slot');
            $this->postJson('/api/device/schedule-slots/'.$slot.'/replace', [
                'medicine_id' => $replacement->id,
                'schedule_id' => $replacementSchedule->id,
                'confirmed' => true,
            ])->assertStatus(422)->assertJsonPath('result', 'invalid_slot');
        }

        $this->postJson('/api/device/schedule-slots/1/replace', [
            'medicine_id' => $replacement->id,
            'schedule_id' => $replacementSchedule->id,
            'confirmed' => true,
        ])->assertOk()->assertJsonPath('slot.medicine_id', $replacement->id);

        $this->assertNotNull(Medicine::query()->find($medicine->id));
        $this->assertNotNull(MedicineSchedule::query()->find($schedule->id));
        $this->assertSame(1, MedicineSchedule::query()->where('medicine_id', $medicine->id)->count());

        $this->postJson('/api/device/schedule-slots/1/release')->assertOk();

        $this->assertSame(0, DeviceScheduleSlot::query()->count());
        $this->assertNotNull(Medicine::query()->find($medicine->id));
        $this->assertNotNull(Medicine::query()->find($replacement->id));
        $this->assertNotNull(MedicineSchedule::query()->find($schedule->id));
        $this->assertNotNull(MedicineSchedule::query()->find($replacementSchedule->id));
        Http::assertNothingSent();
    }

    private function authenticate(string $email): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password1234',
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function medicine(User $user, int $id, string $name): Medicine
    {
        $medicine = new Medicine;
        $medicine->forceFill([
            'id' => $id,
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);
        $medicine->save();

        return $medicine;
    }

    private function schedule(Medicine $medicine, string $time): MedicineSchedule
    {
        return $medicine->schedules()->create([
            'scheduled_time' => $time,
            'is_active' => true,
        ]);
    }
}
