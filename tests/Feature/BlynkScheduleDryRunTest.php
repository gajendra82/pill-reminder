<?php

namespace Tests\Feature;

use App\Models\DeviceScheduleSlot;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use App\Services\SlotAssignmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlynkScheduleDryRunTest extends TestCase
{
    private const ENDPOINT = '/api/device/schedule-sync/dry-run';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->fail('Schedule dry-run tests must use the isolated sqlite memory database.');
        }

        config([
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

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
            $table->string('dosage_label', 100)->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('medicine_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->string('scheduled_time');
            $table->json('days_of_week')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('grace_period_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(self::ENDPOINT.'?pin=V1&token=injected-token')
            ->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_one_two_and_three_medicines_each_get_one_slot(): void
    {
        $user = $this->authenticate();
        $empty = $this->medicine($user, 'No schedule');
        $first = $this->medicine($user, 'Aspirin');
        $second = $this->medicine($user, 'Vitamin');
        $third = $this->medicine($user, 'Iron');

        $firstSchedule = $this->schedule($first, '22:00');
        $secondSchedule = $this->schedule($second, '07:15');
        $thirdSchedule = $this->schedule($third, '12:45', [
            'grace_period_minutes' => 60,
        ]);
        $this->usePersistentSlots();
        $this->assignSlot($user, $first);
        $this->assignSlot($user, $second);
        $this->assignSlot($user, $third);

        $response = $this->getJson(self::ENDPOINT.'?pin=V9&url=https://evil.example');

        $response->assertOk()
            ->assertJsonPath('sync_mode', 'dry_run')
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('validation_errors', [])
            ->assertJsonPath('unsupported_rules', [])
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slot_stability', 'persistent')
            ->assertJsonPath('slots.2.status', 'proposed')
            ->assertJsonPath('laravel_only_rules.0.rule', 'grace_period_minutes')
            ->assertJsonPath('laravel_only_rules.0.value', 60)
            ->assertJsonPath('laravel_only_rules.0.is_api_default', true)
            ->assertJsonPath('laravel_only_rules.0.applied_by_firmware', false)
            ->assertJsonPath('proposed_datastreams', [
                'V1' => 22,
                'V2' => 0,
                'V3' => 7,
                'V4' => 15,
                'V5' => 12,
                'V6' => 45,
            ])
            ->assertJsonPath('slots.0', [
                'slot' => 1,
                'status' => 'proposed',
                'medicine_id' => $first->id,
                'medicine_name' => 'Aspirin',
                'schedule_id' => $firstSchedule->id,
                'scheduled_time' => '22:00',
                'hour_pin' => 'V1',
                'minute_pin' => 'V2',
            ])
            ->assertJsonPath('slots.1.medicine_id', $second->id)
            ->assertJsonPath('slots.1.schedule_id', $secondSchedule->id)
            ->assertJsonPath('slots.1.hour_pin', 'V3')
            ->assertJsonPath('slots.1.minute_pin', 'V4')
            ->assertJsonPath('slots.2.medicine_id', $third->id)
            ->assertJsonPath('slots.2.schedule_id', $thirdSchedule->id)
            ->assertJsonPath('slots.2.hour_pin', 'V5')
            ->assertJsonPath('slots.2.minute_pin', 'V6');

        $assignedIds = array_column($response->json('slots'), 'medicine_id');
        $this->assertNotContains($empty->id, $assignedIds);
        $this->assertSame(
            ['V1', 'V2', 'V3', 'V4', 'V5', 'V6'],
            array_keys($response->json('proposed_datastreams'))
        );
        $this->assertStringNotContainsString('evil.example', $response->getContent());
        Http::assertNothingSent();
    }

    public function test_slot_assignment_follows_medicine_id_and_stays_stable(): void
    {
        $user = $this->authenticate();
        $later = $this->medicine($user, 'Evening');
        $earlier = $this->medicine($user, 'Morning');
        $this->schedule($later, '01:00', ['is_active' => false]);
        $laterSchedule = $this->schedule($later, '22:00');
        $earlierSchedule = $this->schedule($earlier, '07:00');
        $this->usePersistentSlots();
        $this->assignSlot($user, $later);
        $this->assignSlot($user, $earlier);
        $before = DeviceScheduleSlot::query()->count();

        $first = $this->getJson(self::ENDPOINT)->assertOk();
        $second = $this->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame($before, DeviceScheduleSlot::query()->count());
        $first->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slot_stability', 'persistent')
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('slots.0.medicine_id', $later->id)
            ->assertJsonPath('slots.0.schedule_id', $laterSchedule->id)
            ->assertJsonPath('slots.0.scheduled_time', '22:00')
            ->assertJsonPath('slots.0.slot', 1)
            ->assertJsonPath('slots.1.medicine_id', $earlier->id)
            ->assertJsonPath('slots.1.schedule_id', $earlierSchedule->id)
            ->assertJsonPath('slots.1.slot', 2)
            ->assertJsonPath('slots.2.status', 'unused')
            ->assertJsonPath('proposed_datastreams.V1', 22)
            ->assertJsonPath('proposed_datastreams.V3', 7)
            ->assertJsonPath('proposed_datastreams.V5', null);

        $this->assertSame($first->json('slots'), $second->json('slots'));
        $this->assertNotSame($laterSchedule->id, $first->json('slots.0.slot'));
        $this->assertSame($laterSchedule->id, $first->json('slots.0.schedule_id'));
        Http::assertNothingSent();
    }

    public function test_a_medicine_with_multiple_schedules_is_not_reduced_to_one(): void
    {
        $user = $this->authenticate();
        $split = $this->medicine($user, 'Aspirin');
        $single = $this->medicine($user, 'Vitamin');
        $morning = $this->schedule($split, '08:00');
        $evening = $this->schedule($split, '20:00');
        $vitamin = $this->schedule($single, '12:00');
        $this->usePersistentSlots();
        $this->assignSlot($user, $single);
        $rejected = app(SlotAssignmentService::class)->assign($user, $split->id);

        $this->assertSame('unsupported_multiple_schedules_for_medicine', $rejected['result']);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('sync_mode', 'dry_run')
            ->assertJsonPath('validation_errors.0', [
                'code' => 'unsupported_multiple_schedules_for_medicine',
                'blocking' => true,
                'medicine_id' => $split->id,
                'medicine_name' => 'Aspirin',
                'schedule_ids' => [$morning->id, $evening->id],
            ])
            ->assertJsonPath('slots.0.medicine_id', $single->id)
            ->assertJsonPath('slots.0.schedule_id', $vitamin->id)
            ->assertJsonPath('slots.0.slot', 1)
            ->assertJsonPath('slots.1.status', 'unused')
            ->assertJsonPath('slots.2.status', 'unused')
            ->assertJsonPath('proposed_datastreams.V1', 12)
            ->assertJsonPath('proposed_datastreams.V2', 0)
            ->assertJsonPath('proposed_datastreams.V3', null)
            ->assertJsonPath('proposed_datastreams.V5', null);

        $slotted = array_column($response->json('slots'), 'medicine_id');
        $this->assertNotContains($split->id, $slotted);
        Http::assertNothingSent();
    }

    public function test_more_than_three_active_medicines_do_not_produce_a_pin_map(): void
    {
        $user = $this->authenticate();
        $ids = [];

        $this->usePersistentSlots();

        foreach (['One', 'Two', 'Three', 'Four'] as $index => $name) {
            $medicine = $this->medicine($user, $name);
            $ids[] = $medicine->id;
            $this->schedule($medicine, sprintf('%02d:00', 8 + $index));

            if ($index < 3) {
                $this->assignSlot($user, $medicine);
            }
        }

        $fourth = app(SlotAssignmentService::class)->assign($user, $ids[3]);
        $this->assertSame('no_free_slot', $fourth['result']);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('validation_errors', [])
            ->assertJsonPath('proposed_datastreams', [
                'V1' => 8,
                'V2' => 0,
                'V3' => 9,
                'V4' => 0,
                'V5' => 10,
                'V6' => 0,
            ])
            ->assertJsonPath('slots.0.medicine_id', $ids[0])
            ->assertJsonPath('slots.1.medicine_id', $ids[1])
            ->assertJsonPath('slots.2.medicine_id', $ids[2]);

        $this->assertNotContains($ids[3], array_column($response->json('slots'), 'medicine_id'));
        Http::assertNothingSent();
    }

    public function test_no_active_schedules_are_an_explicit_error(): void
    {
        $user = $this->authenticate();
        $this->usePersistentSlots();
        $medicine = $this->medicine($user, 'Empty');
        $this->medicine($user, 'Also empty');

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('sync_mode', 'dry_run')
            ->assertJsonPath('validation_errors.0.code', 'missing_schedules')
            ->assertJsonPath('slots.0.status', 'unused')
            ->assertJsonPath('proposed_datastreams.V1', null)
            ->assertJsonPath('unsupported_rules', []);

        $this->assertNotNull($medicine->id);
        Http::assertNothingSent();
    }

    public function test_duplicate_scheduled_times_are_not_applicable(): void
    {
        $user = $this->authenticate();
        $first = $this->medicine($user, 'Aspirin');
        $second = $this->medicine($user, 'Vitamin');
        $firstSchedule = $this->schedule($first, '08:30');
        $secondSchedule = $this->schedule($second, '08:30:00');
        $this->usePersistentSlots();
        $this->assignSlot($user, $first);
        $this->assignSlot($user, $second);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('slots.0.medicine_id', $first->id)
            ->assertJsonPath('slots.0.schedule_id', $firstSchedule->id)
            ->assertJsonPath('slots.1.medicine_id', $second->id)
            ->assertJsonPath('slots.1.schedule_id', $secondSchedule->id)
            ->assertJsonPath('proposed_datastreams.V1', 8)
            ->assertJsonPath('proposed_datastreams.V2', 30)
            ->assertJsonPath('proposed_datastreams.V3', 8)
            ->assertJsonPath('proposed_datastreams.V4', 30)
            ->assertJsonPath('proposed_datastreams.V5', null)
            ->assertJsonPath('validation_errors.0', [
                'code' => 'duplicate_scheduled_time',
                'blocking' => true,
                'scheduled_time' => '08:30',
                'medicine_ids' => [$first->id, $second->id],
                'schedule_ids' => [$firstSchedule->id, $secondSchedule->id],
            ]);

        Http::assertNothingSent();
    }

    #[DataProvider('invalidTimes')]
    public function test_invalid_times_are_not_placed_in_a_slot(string $time): void
    {
        $user = $this->authenticate();
        $invalidMedicine = $this->medicine($user, 'Bad');
        $validMedicine = $this->medicine($user, 'Good');
        $invalid = $this->schedule($invalidMedicine, $time);
        $valid = $this->schedule($validMedicine, '09:05');
        $this->usePersistentSlots();
        $this->assignSlot($user, $invalidMedicine);
        $this->assignSlot($user, $validMedicine);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('validation_errors.0', [
                'code' => 'invalid_schedule_time',
                'blocking' => true,
                'medicine_id' => $invalidMedicine->id,
                'medicine_name' => 'Bad',
                'schedule_id' => $invalid->id,
                'scheduled_time' => $time,
            ])
            ->assertJsonPath('slots.0.status', 'held')
            ->assertJsonPath('slots.0.medicine_id', $invalidMedicine->id)
            ->assertJsonPath('slots.0.schedule_id', $invalid->id)
            ->assertJsonPath('slots.1.medicine_id', $validMedicine->id)
            ->assertJsonPath('slots.1.schedule_id', $valid->id)
            ->assertJsonPath('slots.1.slot', 2)
            ->assertJsonPath('proposed_datastreams.V1', null)
            ->assertJsonPath('proposed_datastreams.V2', null)
            ->assertJsonPath('proposed_datastreams.V3', 9)
            ->assertJsonPath('proposed_datastreams.V4', 5)
            ->assertJsonPath('proposed_datastreams.V5', null);

        $this->assertSame($invalidMedicine->id, $response->json('slots.0.medicine_id'));
        $this->assertNotSame($invalidMedicine->id, $response->json('slots.1.medicine_id'));
        Http::assertNothingSent();
    }

    public static function invalidTimes(): array
    {
        return [
            'hour out of range' => ['24:00'],
            'minute out of range' => ['08:60'],
            'not a time' => ['not-a-time'],
            'single digit hour' => ['8:30'],
        ];
    }

    public function test_start_dates_in_the_past_and_today_are_explicit_daily_assumptions(): void
    {
        $user = $this->authenticate();
        $past = $this->medicineWithId($user, 10, 'Past');
        $today = $this->medicineWithId($user, 20, 'Today');
        $pastSchedule = $this->schedule($past, '08:00', ['starts_on' => '2026-10-01']);
        $todaySchedule = $this->schedule($today, '09:00', ['starts_on' => '2026-10-22']);
        $this->usePersistentSlots();
        $this->assignSlot($user, $past);
        $this->assignSlot($user, $today);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('evaluation.date', '2026-10-22')
            ->assertJsonPath('evaluation.timezone', 'Asia/Kolkata')
            ->assertJsonPath('evaluation.device_timezone_verified', false)
            ->assertJsonPath('unsupported_rules', [])
            ->assertJsonPath('slots.0.schedule_id', $pastSchedule->id)
            ->assertJsonPath('slots.1.schedule_id', $todaySchedule->id)
            ->assertJsonPath('assumptions.0.accepted', true)
            ->assertJsonPath('assumptions.0.starts_on', '2026-10-01')
            ->assertJsonPath('assumptions.1.accepted', true)
            ->assertJsonPath('assumptions.1.starts_on', '2026-10-22');

        Http::assertNothingSent();
    }

    public function test_a_future_start_date_is_not_a_daily_reminder_yet(): void
    {
        $user = $this->authenticate();
        $medicine = $this->medicine($user, 'Future');
        $schedule = $this->schedule($medicine, '08:00', ['starts_on' => '2026-11-01']);
        $this->usePersistentSlots();
        $this->assignSlot($user, $medicine);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules.0.rule', 'starts_on')
            ->assertJsonPath('unsupported_rules.0.schedule_id', $schedule->id)
            ->assertJsonPath('assumptions.0.accepted', false)
            ->assertJsonPath('assumptions.0.starts_on', '2026-11-01')
            ->assertJsonPath('slots.0.schedule_id', $schedule->id);

        Http::assertNothingSent();
    }

    public function test_weekday_only_schedules_are_unsupported(): void
    {
        $user = $this->authenticate();
        $medicine = $this->medicine($user, 'Weekdays');
        $schedule = $this->schedule($medicine, '08:30', [
            'days_of_week' => [1, 3, 5],
        ]);
        $this->usePersistentSlots();
        $this->assignSlot($user, $medicine);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules.0.rule', 'days_of_week')
            ->assertJsonPath('unsupported_rules.0.schedule_id', $schedule->id)
            ->assertJsonPath('unsupported_rules.0.value', [1, 3, 5])
            ->assertJsonPath('slots.0.scheduled_time', '08:30');

        Http::assertNothingSent();
    }

    public function test_all_seven_iso_weekdays_are_a_daily_schedule(): void
    {
        $user = $this->authenticate();
        $medicine = $this->medicine($user, 'Every day');
        $schedule = $this->schedule($medicine, '08:30', [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'starts_on' => '2026-10-22',
        ]);
        $this->usePersistentSlots();
        $this->assignSlot($user, $medicine);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules', [])
            ->assertJsonPath('evaluation.date', '2026-10-22')
            ->assertJsonPath('evaluation.timezone', 'Asia/Kolkata')
            ->assertJsonPath('slots.0.schedule_id', $schedule->id)
            ->assertJsonPath('proposed_datastreams.V1', 8)
            ->assertJsonPath('proposed_datastreams.V2', 30);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $schedule->fresh()->days_of_week);
        Http::assertNothingSent();
    }

    public function test_the_evaluation_date_is_the_asia_kolkata_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:31:00', 'UTC'));
        $user = $this->authenticate();
        $today = $this->medicineWithId($user, 10, 'Local today');
        $future = $this->medicineWithId($user, 20, 'Local future');
        $todaySchedule = $this->schedule($today, '08:00', ['starts_on' => '2026-10-23']);
        $futureSchedule = $this->schedule($future, '09:00', ['starts_on' => '2026-10-24']);
        $this->usePersistentSlots();
        $this->assignSlot($user, $today);
        $this->assignSlot($user, $future);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('evaluation.date', '2026-10-23')
            ->assertJsonPath('evaluation.timezone', 'Asia/Kolkata')
            ->assertJsonPath('slots.0.schedule_id', $todaySchedule->id)
            ->assertJsonPath('slots.0.status', 'proposed')
            ->assertJsonPath('slots.1.schedule_id', $futureSchedule->id)
            ->assertJsonPath('assumptions.0.accepted', true)
            ->assertJsonPath('assumptions.0.evaluated_on', '2026-10-23')
            ->assertJsonPath('assumptions.0.timezone', 'Asia/Kolkata')
            ->assertJsonPath('unsupported_rules.0.rule', 'starts_on')
            ->assertJsonPath('unsupported_rules.0.schedule_id', $futureSchedule->id);

        Http::assertNothingSent();
    }

    public function test_end_dates_are_unsupported(): void
    {
        $user = $this->authenticate();
        $medicine = $this->medicine($user, 'Limited');
        $schedule = $this->schedule($medicine, '08:30', [
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-12-01',
        ]);
        $this->usePersistentSlots();
        $this->assignSlot($user, $medicine);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules.0.rule', 'ends_on')
            ->assertJsonPath('unsupported_rules.0.schedule_id', $schedule->id)
            ->assertJsonPath('assumptions.0.accepted', true)
            ->assertJsonPath('assumptions.0.starts_on', '2026-10-01');

        Http::assertNothingSent();
    }

    public function test_grace_periods_remain_laravel_only(): void
    {
        $user = $this->authenticate();
        $custom = $this->medicineWithId($user, 10, 'Custom grace');
        $default = $this->medicineWithId($user, 20, 'Default grace');
        $customSchedule = $this->schedule($custom, '08:00', ['grace_period_minutes' => 15]);
        $defaultSchedule = $this->schedule($default, '09:00', ['grace_period_minutes' => 60]);
        $this->usePersistentSlots();
        $this->assignSlot($user, $custom);
        $this->assignSlot($user, $default);

        $response = $this->getJson(self::ENDPOINT);

        $response->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules', [])
            ->assertJsonPath('laravel_only_rules.0', [
                'medicine_id' => $custom->id,
                'schedule_id' => $customSchedule->id,
                'rule' => 'grace_period_minutes',
                'value' => 15,
                'is_api_default' => false,
                'applied_by_firmware' => false,
                'message' => 'The grace period stays in Laravel. The ESP8266 stores only the hour and minute and does not apply a grace window.',
            ])
            ->assertJsonPath('laravel_only_rules.1.schedule_id', $defaultSchedule->id)
            ->assertJsonPath('laravel_only_rules.1.value', 60)
            ->assertJsonPath('laravel_only_rules.1.is_api_default', true)
            ->assertJsonPath('laravel_only_rules.1.applied_by_firmware', false);

        Http::assertNothingSent();
    }

    public function test_deactivating_a_medicine_removes_its_slot_and_activation_ranks_by_id(): void
    {
        $user = $this->authenticate();
        $paused = $this->medicine($user, 'Paused');
        $pausedSchedule = $this->schedule($paused, '08:00');
        $active = $this->medicine($user, 'Active');
        $activeSchedule = $this->schedule($active, '09:30');
        $this->usePersistentSlots();
        $this->assignSlot($user, $paused);
        $this->assignSlot($user, $active);
        $paused->is_active = false;
        $paused->save();

        $whilePaused = $this->getJson(self::ENDPOINT)->assertOk();
        $whilePaused->assertJsonPath('can_apply', false)
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slots.0.status', 'held')
            ->assertJsonPath('slots.0.medicine_id', $paused->id)
            ->assertJsonPath('slots.0.schedule_id', $pausedSchedule->id)
            ->assertJsonPath('slots.1.medicine_id', $active->id)
            ->assertJsonPath('slots.1.schedule_id', $activeSchedule->id)
            ->assertJsonPath('proposed_datastreams.V1', null)
            ->assertJsonPath('proposed_datastreams.V3', 9)
            ->assertJsonPath('validation_errors.0.code', 'assigned_medicine_inactive')
            ->assertJsonPath('unmapped_schedules.0', [
                'medicine_id' => $paused->id,
                'schedule_id' => $pausedSchedule->id,
                'reason' => 'medicine_inactive',
            ]);

        $paused->is_active = true;
        $paused->save();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slots.0.medicine_id', $paused->id)
            ->assertJsonPath('slots.1.medicine_id', $active->id)
            ->assertJsonPath('unmapped_schedules', []);

        Http::assertNothingSent();
    }

    public function test_adding_a_lower_medicine_id_changes_provisional_ranks(): void
    {
        $user = $this->authenticate();
        $middle = $this->medicineWithId($user, 10, 'Middle');
        $highest = $this->medicineWithId($user, 20, 'Highest');
        $this->schedule($middle, '11:00');
        $this->schedule($highest, '18:00');
        $this->usePersistentSlots();
        $this->assignSlot($user, $middle);
        $this->assignSlot($user, $highest);

        $before = $this->getJson(self::ENDPOINT)->assertOk();
        $before->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slot_assignment_persistent', true);

        $lower = $this->medicineWithId($user, 5, 'Lower');
        $this->schedule($lower, '07:00');

        $after = $this->getJson(self::ENDPOINT)->assertOk();
        $after->assertJsonPath('can_apply', true)
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slot_stability', 'persistent')
            ->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.status', 'unused');

        $this->assertNotContains(5, array_column($after->json('slots'), 'medicine_id'));
        Http::assertNothingSent();
    }

    public function test_deleting_a_mapped_medicine_recomputes_provisional_ranks(): void
    {
        $user = $this->authenticate();
        $first = $this->medicineWithId($user, 10, 'First');
        $second = $this->medicineWithId($user, 20, 'Second');
        $third = $this->medicineWithId($user, 30, 'Third');
        $this->schedule($first, '08:00');
        $this->schedule($second, '12:00');
        $this->schedule($third, '18:00');
        $this->usePersistentSlots();
        $this->assignSlot($user, $first);
        $this->assignSlot($user, $second);
        $this->assignSlot($user, $third);

        $unrelated = $this->medicineWithId($user, 40, 'Unrelated');
        $this->schedule($unrelated, '21:00');
        $unrelated->delete();

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.medicine_id', 30)
            ->assertJsonPath('slots.0.status', 'proposed')
            ->assertJsonPath('slots.1.status', 'proposed')
            ->assertJsonPath('slots.2.status', 'proposed');

        try {
            $first->delete();
            $this->fail('An assigned medicine must not be deleted until its slot is released.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertNotNull(Medicine::query()->find(10));
        }

        $after = $this->getJson(self::ENDPOINT)->assertOk();
        $after->assertJsonPath('slot_assignment_persistent', true)
            ->assertJsonPath('slots.0.medicine_id', 10)
            ->assertJsonPath('slots.1.medicine_id', 20)
            ->assertJsonPath('slots.2.medicine_id', 30)
            ->assertJsonPath('proposed_datastreams.V1', 8)
            ->assertJsonPath('proposed_datastreams.V5', 18);
        Http::assertNothingSent();
    }

    public function test_inactive_schedule_is_listed_and_does_not_take_a_slot(): void
    {
        $user = $this->authenticate();
        $medicine = $this->medicine($user, 'Aspirin');
        $active = $this->schedule($medicine, '09:15');
        $inactive = $this->schedule($medicine, '22:00', ['is_active' => false]);
        $this->usePersistentSlots();
        $this->assignSlot($user, $medicine);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('can_apply', true)
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('validation_errors', [])
            ->assertJsonPath('slots.0.schedule_id', $active->id)
            ->assertJsonPath('slots.0.scheduled_time', '09:15')
            ->assertJsonPath('proposed_datastreams.V1', 9)
            ->assertJsonPath('proposed_datastreams.V2', 15)
            ->assertJsonPath('proposed_datastreams.V3', null)
            ->assertJsonPath('unmapped_schedules', [[
                'medicine_id' => $medicine->id,
                'schedule_id' => $inactive->id,
                'reason' => 'inactive',
            ]]);

        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_rank_medicines_when_slot_storage_is_unavailable(): void
    {
        $user = $this->authenticate();
        $lower = $this->medicineWithId($user, 5, 'Lower');
        $higher = $this->medicineWithId($user, 20, 'Higher');
        $this->schedule($lower, '07:00');
        $this->schedule($higher, '18:00');

        $response = $this->getJson(self::ENDPOINT)->assertOk();

        $response->assertJsonPath('sync_mode', 'dry_run')
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('can_apply', false)
            ->assertJsonPath('slot_assignment_persistent', false)
            ->assertJsonPath('slot_stability', 'unavailable')
            ->assertJsonPath('slot_assignment_status', 'slot_assignment_persistence_unavailable')
            ->assertJsonPath('validation_errors.0.code', 'slot_assignment_persistence_unavailable')
            ->assertJsonPath('proposed_datastreams', [
                'V1' => null,
                'V2' => null,
                'V3' => null,
                'V4' => null,
                'V5' => null,
                'V6' => null,
            ]);

        foreach ($response->json('slots') as $slot) {
            $this->assertSame('unused', $slot['status']);
            $this->assertNull($slot['medicine_id']);
        }

        Http::assertNothingSent();
    }

    private function authenticate(): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password1234',
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function usePersistentSlots(): void
    {
        DB::statement('PRAGMA foreign_keys = ON');

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
    }

    private function assignSlot(User $user, Medicine $medicine, ?int $scheduleId = null): void
    {
        $result = app(SlotAssignmentService::class)->assign($user, $medicine->id, $scheduleId);
        $this->assertSame('assigned', $result['result'], json_encode($result));
    }

    private function medicine(User $user, string $name): Medicine
    {
        return $user->medicines()->create([
            'name' => $name,
        ]);
    }

    private function medicineWithId(User $user, int $id, string $name): Medicine
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function schedule(Medicine $medicine, string $time, array $overrides = []): MedicineSchedule
    {
        return $medicine->schedules()->create(array_merge([
            'scheduled_time' => $time,
            'days_of_week' => null,
            'starts_on' => null,
            'ends_on' => null,
            'grace_period_minutes' => null,
            'is_active' => true,
        ], $overrides));
    }
}
