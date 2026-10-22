<?php

namespace Tests\Feature;

use App\Models\DeviceScheduleSlot;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeviceScheduleSyncTest extends TestCase
{
    private const TOKEN = 'dedicated-test-device-token';

    /** @var list<array{method: string, path: string, query: array<string, mixed>}> */
    private array $httpLog = [];

    private ?int $scheduleId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'sqlite') {
            $this->fail('Schedule sync tests must use the isolated sqlite memory database.');
        }

        config([
            'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => ':memory:',
            'services.blynk.base_url' => 'https://blynk.test',
            'services.blynk.auth_token' => self::TOKEN,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'user_id']);
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
            $table->unique(['id', 'medicine_id']);
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
            $table->foreign(['medicine_id', 'user_id'])->references(['id', 'user_id'])->on('medicines');
            $table->foreign(['schedule_id', 'medicine_id'])->references(['id', 'medicine_id'])->on('medicine_schedules');
        });

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[DataProvider('slots')]
    public function test_each_slot_writes_only_its_two_pins_and_reads_them_back(
        int $slot,
        string $hourPin,
        string $minutePin,
        string $time,
        int $hour,
        int $minute,
    ): void {
        $user = $this->user('owner@example.com');
        $this->assign($user, $slot, 'Medicine', $time, ['starts_on' => '2026-10-01', 'grace_period_minutes' => 15]);
        config(['logging.default' => 'null']);
        $logLines = [];
        Log::listen(function (object $event) use (&$logLines): void {
            $logLines[] = ($event->message ?? '').' '.json_encode($event->context ?? []);
        });
        $stored = ['v1' => 3, 'v2' => 10, 'v3' => 3, 'v4' => 10, 'v5' => 3, 'v6' => 10];
        $stored[$hourPin] = $hour;
        $stored[$minutePin] = $minute;
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10, 'v3' => 3, 'v4' => 10, 'v5' => 3, 'v6' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins($stored),
        ]);

        $response = $this->sync($slot);

        $response->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('schedule_id', $this->scheduleId)
            ->assertJsonPath('writes_performed', true)
            ->assertJsonPath('partial', false)
            ->assertJsonPath('hour', $hour)
            ->assertJsonPath('minute', $minute)
            ->assertJsonPath('verified_hour', $hour)
            ->assertJsonPath('verified_minute', $minute)
            ->assertJsonPath('read_back.verified_hour', $hour)
            ->assertJsonPath('read_back.verified_minute', $minute)
            ->assertJsonPath('hour_pin', strtoupper($hourPin))
            ->assertJsonPath('minute_pin', strtoupper($minutePin))
            ->assertJsonPath('read_back.hour', $hour)
            ->assertJsonPath('read_back.minute', $minute)
            ->assertJsonPath('safety.device_clock_known', false)
            ->assertJsonPath('safety.timezone', 'Asia/Kolkata')
            ->assertJsonPath('assumptions.0.accepted', true)
            ->assertJsonPath('laravel_only_rules.0.applied_by_firmware', false);

        $updates = $this->updates();
        $this->assertCount(2, $updates);
        $this->assertSame($response->json('safety.write_order'), [
            strtoupper((string) array_key_first($updates[0]['query'])),
            strtoupper((string) array_key_first($updates[1]['query'])),
        ]);
        $this->assertSame([
            $hourPin => (string) $hour,
            $minutePin => (string) $minute,
        ], [
            $hourPin => $updates[0]['query'][$hourPin] ?? $updates[1]['query'][$hourPin],
            $minutePin => $updates[0]['query'][$minutePin] ?? $updates[1]['query'][$minutePin],
        ]);
        $this->assertNoStatusPins();
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $response->json('timestamp'));
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logLines));
    }

    public function test_sync_requires_authentication_and_does_not_call_blynk(): void
    {
        $this->postJson('/api/device/schedule-slots/1/sync')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_another_user_cannot_sync_a_slot_and_no_blynk_write_is_sent(): void
    {
        $owner = $this->user('owner@example.com');
        $this->assign($owner, 1, 'Owner', '08:00');
        $this->user('other@example.com');

        $this->sync(1)
            ->assertNotFound()
            ->assertJsonPath('result', 'failed')
            ->assertJsonPath('code', 'slot_not_assigned')
            ->assertJsonPath('writes_performed', false);

        $this->assertSame(1, DeviceScheduleSlot::query()->where('user_id', $owner->id)->count());
        Http::assertNothingSent();
    }

    public function test_a_released_slot_is_not_written_as_midnight(): void
    {
        $this->user('owner@example.com');

        $this->postJson('/api/device/schedule-slots/2/sync', ['schedule_id' => 1])
            ->assertNotFound()
            ->assertJsonPath('code', 'slot_not_assigned')
            ->assertJsonPath('writes_performed', false);

        Http::assertNothingSent();
    }

    public function test_request_pin_values_are_rejected(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');

        $this->postJson('/api/device/schedule-slots/1/sync', ['pin' => 'V7', 'value' => 0])
            ->assertStatus(422)
            ->assertJsonPath('result', 'sync_input_not_accepted');

        Http::assertNothingSent();
    }

    public function test_missing_or_unassigned_schedule_selection_is_rejected(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');

        $this->postJson('/api/device/schedule-slots/1/sync')
            ->assertStatus(422)
            ->assertJsonPath('code', 'schedule_selection_required');

        $other = $user->medicines()->create(['name' => 'Other', 'is_active' => true]);
        $otherSchedule = $other->schedules()->create(['scheduled_time' => '09:00', 'is_active' => true]);

        $this->sync(1, ['schedule_id' => $otherSchedule->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'schedule_selection_mismatch')
            ->assertJsonPath('writes_performed', false);

        foreach ([0, 4, 9] as $slot) {
            $this->sync($slot)->assertStatus(422)->assertJsonPath('code', 'invalid_slot');
        }

        Http::assertNothingSent();
    }

    public function test_a_null_weekday_list_syncs_as_a_daily_schedule(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Daily', '08:00', [
            'starts_on' => '2026-10-22',
            'days_of_week' => null,
        ]);
        $this->fakeReadBack(8, 0);

        $this->sync(1)
            ->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('hour', 8)
            ->assertJsonPath('minute', 0)
            ->assertJsonPath('verified_hour', 8)
            ->assertJsonPath('verified_minute', 0)
            ->assertJsonPath('demo_mode', false)
            ->assertJsonPath('writes_performed', true);
        $this->assertNull(MedicineSchedule::query()->find($this->scheduleId)->days_of_week);
    }

    public function test_all_seven_iso_weekdays_sync_without_rewriting_the_stored_list(): void
    {
        $user = $this->user('owner@example.com');
        $storedDays = [7, 1, 2, 3, 4, 5, 6];
        $this->assign($user, 1, 'Every day listed', '08:00', [
            'starts_on' => '2026-10-22',
            'days_of_week' => $storedDays,
        ]);
        $this->fakeReadBack(8, 0);

        $this->sync(1)
            ->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('writes_performed', true)
            ->assertJsonPath('verified_hour', 8)
            ->assertJsonPath('verified_minute', 0);
        $this->assertSame($storedDays, MedicineSchedule::query()->find($this->scheduleId)->days_of_week);
    }

    public function test_a_start_date_that_is_today_in_asia_kolkata_can_sync(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:31:00', 'UTC'));
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Local today', '08:00', [
            'starts_on' => '2026-10-23',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        $this->fakeReadBack(8, 0);

        $this->sync(1)
            ->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('hour', 8)
            ->assertJsonPath('minute', 0)
            ->assertJsonPath('verified_hour', 8)
            ->assertJsonPath('verified_minute', 0)
            ->assertJsonPath('writes_performed', true);
    }

    public function test_a_start_date_after_the_asia_kolkata_date_does_not_call_blynk(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:31:00', 'UTC'));
        Http::preventStrayRequests();
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Local future', '08:00', [
            'starts_on' => '2026-10-24',
            'days_of_week' => null,
        ]);

        $this->sync(1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_schedule')
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules.0.rule', 'starts_on');

        Http::assertNothingSent();
    }

    public function test_weekday_subsets_end_dates_and_invalid_times_do_not_call_blynk(): void
    {
        Http::preventStrayRequests();
        $user = $this->user('owner@example.com');

        $this->assign($user, 1, 'Weekdays', '08:00', [
            'starts_on' => '2026-10-01',
            'days_of_week' => [1, 3, 5],
        ]);
        $this->sync(1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_schedule')
            ->assertJsonPath('writes_performed', false)
            ->assertJsonPath('unsupported_rules.0.rule', 'days_of_week');

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($user, 1, 'Ending', '08:00', [
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-12-01',
        ]);
        $this->sync(1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_schedule')
            ->assertJsonPath('unsupported_rules.0.rule', 'ends_on')
            ->assertJsonPath('writes_performed', false);

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($user, 1, 'Bad time', 'not-a-time', ['starts_on' => '2026-10-01']);
        $this->sync(1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_schedule_time')
            ->assertJsonPath('writes_performed', false);

        Http::assertNothingSent();
    }

    public function test_invalid_times_and_unsupported_rules_do_not_write(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Bad time', 'not-a-time');
        $this->sync(1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_schedule_time')
            ->assertJsonPath('schedule_id', $this->scheduleId)
            ->assertJsonPath('writes_performed', false);

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($user, 1, 'Weekdays', '08:00', ['days_of_week' => [1, 3, 5]]);
        $this->sync(1)
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_schedule');

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($user, 1, 'Ending', '08:00', ['ends_on' => '2026-12-01']);
        $this->sync(1)->assertStatus(422)->assertJsonPath('code', 'unsupported_schedule');

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($user, 1, 'Future', '08:00', ['starts_on' => '2026-11-01']);
        $this->sync(1)->assertStatus(422)->assertJsonPath('code', 'unsupported_schedule');

        Http::assertNothingSent();
    }

    public function test_times_near_the_current_ist_minute_are_deferred(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Soon', '17:30');
        $this->fakeSequence([$this->pins(['v1' => 3, 'v2' => 10])]);

        $this->sync(1)
            ->assertStatus(409)
            ->assertJsonPath('result', 'sync_deferred')
            ->assertJsonPath('code', 'sync_deferred')
            ->assertJsonPath('demo_mode', false)
            ->assertJsonPath('writes_performed', false);

        $this->assertSame([], $this->updates());
    }

    public function test_explicit_false_demo_mode_still_defers_near_the_current_minute(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Soon', '17:30');
        $this->fakeSequence([$this->pins(['v1' => 3, 'v2' => 10])]);

        $this->sync(1, ['demo_mode' => false])
            ->assertStatus(409)
            ->assertJsonPath('result', 'sync_deferred')
            ->assertJsonPath('demo_mode', false)
            ->assertJsonPath('safety.proximity_window', 'enforced')
            ->assertJsonPath('writes_performed', false);

        $this->assertSame([], $this->updates());
    }

    public function test_demo_mode_bypasses_only_the_proximity_deferral(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Soon', '17:30');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 17, 'v2' => 30]),
        ]);

        $this->sync(1, ['demo_mode' => true])
            ->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('safety.proximity_window', 'bypassed')
            ->assertJsonPath('hour', 17)
            ->assertJsonPath('minute', 30)
            ->assertJsonPath('verified_hour', 17)
            ->assertJsonPath('verified_minute', 30)
            ->assertJsonPath('writes_performed', true);

        $this->assertCount(2, $this->updates());
    }

    public function test_demo_mode_still_rejects_invalid_and_unsupported_schedules(): void
    {
        Http::preventStrayRequests();
        $owner = $this->user('owner@example.com');
        $this->assign($owner, 1, 'Owned', '17:30', ['starts_on' => '2026-10-01']);

        $this->user('other@example.com');
        $this->sync(1, ['demo_mode' => true])
            ->assertNotFound()
            ->assertJsonPath('code', 'slot_not_assigned')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('writes_performed', false);

        Sanctum::actingAs($owner);
        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($owner, 1, 'Weekdays', '17:30', [
            'starts_on' => '2026-10-01',
            'days_of_week' => [1, 3, 5],
        ]);
        $this->sync(1, ['demo_mode' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_schedule')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('writes_performed', false);

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($owner, 1, 'Bad time', 'not-a-time', ['starts_on' => '2026-10-01']);
        $this->sync(1, ['demo_mode' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_schedule_time')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('writes_performed', false);

        DeviceScheduleSlot::query()->delete();
        Medicine::query()->delete();
        $this->assign($owner, 1, 'Future', '17:30', ['starts_on' => '2026-11-01']);
        $this->sync(1, ['demo_mode' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_schedule')
            ->assertJsonPath('unsupported_rules.0.rule', 'starts_on')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('writes_performed', false);

        Http::assertNothingSent();
    }

    public function test_demo_mode_read_back_mismatch_stays_unconfirmed(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Soon', '17:30');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 1, 'v2' => 2]),
        ]);

        $this->sync(1, ['demo_mode' => true])
            ->assertStatus(409)
            ->assertJsonPath('result', 'unconfirmed')
            ->assertJsonPath('code', 'read_back_mismatch')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('read_back.hour', 1)
            ->assertJsonPath('read_back.minute', 2)
            ->assertJsonPath('writes_performed', true);

        $this->assertCount(2, $this->updates());
    }

    public function test_demo_mode_partial_write_is_not_retried(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Soon', '17:30');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response(['error' => ['message' => 'No datastream setup for that pin.']], 500),
            $this->pins(['v1' => 3, 'v2' => 30]),
        ]);

        $response = $this->sync(1, ['demo_mode' => true]);

        $response->assertStatus(502)
            ->assertJsonPath('result', 'partial_write')
            ->assertJsonPath('code', 'partial_write')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('partial', true)
            ->assertJsonPath('reconciliation_required', true)
            ->assertJsonPath('writes_performed', true);
        $this->assertCount(2, $this->updates());
        $this->assertNoStatusPins();
    }

    public function test_an_invalid_demo_mode_value_is_rejected_before_blynk(): void
    {
        Http::preventStrayRequests();
        $this->user('owner@example.com');

        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => 1,
            'demo_mode' => 'yes',
        ])->assertStatus(422)->assertJsonValidationErrors(['demo_mode']);

        Http::assertNothingSent();
    }

    #[DataProvider('blynkReadErrors')]
    public function test_blynk_errors_on_the_initial_read_do_not_write(int $status, string $code, int $http): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([Http::response(['error' => ['message' => 'Invalid token.']], $status)]);

        $response = $this->sync(1);

        $response->assertStatus($http)
            ->assertJsonPath('result', 'failed')
            ->assertJsonPath('code', $code)
            ->assertJsonPath('writes_performed', false);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString('Invalid token.', $response->getContent());
        $this->assertSame([], $this->updates());
    }

    public static function blynkReadErrors(): array
    {
        return [
            'unauthorized' => [400, 'blynk_unauthorized', 502],
            'rate limit' => [429, 'blynk_rate_limited', 429],
        ];
    }

    public function test_a_timeout_before_any_write_does_not_send_an_update(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([Http::failedConnection('timed out')]);

        $this->sync(1)
            ->assertStatus(504)
            ->assertJsonPath('code', 'blynk_connection_failed')
            ->assertJsonPath('writes_performed', false);

        $this->assertSame([], $this->updates());
    }

    public function test_a_second_write_failure_is_a_partial_failure_without_retry(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response(['error' => ['message' => 'No datastream setup for that pin.']], 500),
            $this->pins(['v1' => 3, 'v2' => 0]),
        ]);

        $response = $this->sync(1);

        $response->assertStatus(502)
            ->assertJsonPath('result', 'partial_write')
            ->assertJsonPath('code', 'partial_write')
            ->assertJsonPath('partial', true)
            ->assertJsonPath('reconciliation_required', true)
            ->assertJsonPath('writes_performed', true);
        $this->assertNotSame('synced', $response->json('result'));
        $this->assertStringContainsString('may already have the first value', (string) $response->json('message'));
        $this->assertCount(2, $this->updates());
        $this->assertNoStatusPins();
    }

    public function test_read_back_mismatch_is_not_reported_as_synced(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 1, 'v2' => 2]),
        ]);

        $this->sync(1)
            ->assertStatus(409)
            ->assertJsonPath('result', 'unconfirmed')
            ->assertJsonPath('code', 'read_back_mismatch')
            ->assertJsonPath('read_back.hour', 1)
            ->assertJsonPath('read_back.minute', 2)
            ->assertJsonPath('reconciliation_required', true);

        $this->assertCount(2, $this->updates());
    }

    public function test_a_malformed_read_and_a_failed_first_write_do_not_report_success(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([Http::response('not-json', 200)]);

        $this->sync(1)
            ->assertStatus(502)
            ->assertJsonPath('result', 'failed')
            ->assertJsonPath('code', 'blynk_invalid_response')
            ->assertJsonPath('writes_performed', false);
        $this->assertSame([], $this->updates());
    }

    public function test_a_malformed_write_body_is_not_success(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('not-json', 200),
        ]);

        $response = $this->sync(1);

        $response->assertStatus(502)
            ->assertJsonPath('result', 'failed')
            ->assertJsonPath('writes_performed', false);
        $this->assertNotSame('synced', $response->json('result'));
        $this->assertCount(1, $this->updates());
    }

    public function test_the_first_write_failure_does_not_send_the_second_write(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response(['error' => ['message' => 'Value does not match.']], 500),
        ]);

        $response = $this->sync(1);

        $response->assertStatus(502)
            ->assertJsonPath('result', 'failed')
            ->assertJsonPath('partial', false)
            ->assertJsonPath('writes_performed', false);
        $this->assertNotSame('synced', $response->json('result'));
        $this->assertNotSame('partial_write', $response->json('result'));
        $this->assertCount(1, $this->updates());
        $this->assertNoStatusPins();
    }

    public function test_read_back_failure_is_unconfirmed_and_does_not_retry(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        config(['logging.default' => 'null']);
        $logLines = [];
        Log::listen(function (object $event) use (&$logLines): void {
            $logLines[] = ($event->message ?? '').' '.json_encode($event->context ?? []);
        });
        $secret = 'https://blynk.test/external/api/update?token='.self::TOKEN.'&v2=0';
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            Http::failedConnection($secret),
        ]);

        $response = $this->sync(1);

        $response->assertStatus(502)
            ->assertJsonPath('result', 'unconfirmed')
            ->assertJsonPath('code', 'read_back_unavailable')
            ->assertJsonPath('reconciliation_required', true);
        $this->assertNotSame('synced', $response->json('result'));
        $this->assertCount(2, $this->updates());
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logLines));
    }

    public function test_blynk_error_bodies_that_contain_the_token_are_redacted(): void
    {
        $user = $this->user('owner@example.com');
        $this->assign($user, 1, 'Aspirin', '08:00');
        config(['logging.default' => 'null']);
        $logLines = [];
        Log::listen(function (object $event) use (&$logLines): void {
            $logLines[] = ($event->message ?? '').' '.json_encode($event->context ?? []);
        });
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response(['error' => ['message' => 'Invalid token '.self::TOKEN]], 400),
        ]);

        $response = $this->sync(1);

        $response->assertStatus(502)
            ->assertJsonPath('result', 'failed')
            ->assertJsonPath('writes_performed', false);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logLines));
        $this->assertCount(1, $this->updates());
    }

    public function test_device_status_still_uses_only_the_read_endpoint(): void
    {
        $this->user('owner@example.com');
        $this->fakeSequence([$this->pins(['v1' => 8, 'v2' => 30])]);

        $this->getJson('/api/device/status')
            ->assertOk()
            ->assertJsonPath('device_status', 'available')
            ->assertJsonPath('datastreams.v1', 8);

        $this->assertSame([], $this->updates());
        $this->assertSame('/external/api/getAll', $this->httpLog[0]['path']);
    }

    public static function slots(): array
    {
        return [
            'slot 1' => [1, 'v1', 'v2', '08:00', 8, 0],
            'slot 2' => [2, 'v3', 'v4', '09:15', 9, 15],
            'slot 3' => [3, 'v5', 'v6', '10:45', 10, 45],
        ];
    }

    private function user(string $email): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => 'password1234',
        ]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $schedule
     */
    private function assign(User $user, int $slot, string $name, string $time, array $schedule = []): void
    {
        $medicine = new Medicine;
        $medicine->forceFill([
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);
        $medicine->save();
        $row = $medicine->schedules()->create(array_merge([
            'scheduled_time' => $time,
            'is_active' => true,
        ], $schedule));
        DeviceScheduleSlot::query()->create([
            'user_id' => $user->id,
            'slot' => $slot,
            'medicine_id' => $medicine->id,
            'schedule_id' => $row->id,
            'assigned_at' => Carbon::now(),
        ]);
        $this->scheduleId = (int) $row->id;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sync(int $slot, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/device/schedule-slots/'.$slot.'/sync', array_merge([
            'schedule_id' => $this->scheduleId,
        ], $body));
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function fakeReadBack(int $hour, int $minute): void
    {
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10, 'v3' => 16, 'v4' => 26, 'v5' => 16, 'v6' => 50]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => $hour, 'v2' => $minute, 'v3' => 16, 'v4' => 26, 'v5' => 16, 'v6' => 50]),
        ]);
    }

    /**
     * @param  list<mixed>  $responses
     */
    private function fakeSequence(array $responses): void
    {
        $step = 0;
        Http::fake(function (Request $request) use (&$step, $responses) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            unset($query['token']);
            $this->httpLog[] = [
                'method' => $request->method(),
                'path' => (string) parse_url($request->url(), PHP_URL_PATH),
                'query' => $query,
            ];
            $response = $responses[$step] ?? Http::response(['error' => ['message' => 'unexpected']], 500);
            $step++;

            return $response;
        });
    }

    /**
     * @param  array<string, int>  $values
     */
    private function pins(array $values): mixed
    {
        return Http::response($values, 200);
    }

    /**
     * @return list<array{method: string, path: string, query: array<string, mixed>}>
     */
    private function updates(): array
    {
        return array_values(array_filter(
            $this->httpLog,
            fn (array $call) => str_ends_with($call['path'], '/update')
        ));
    }

    private function assertNoStatusPins(): void
    {
        foreach ($this->httpLog as $call) {
            foreach (array_keys($call['query']) as $pin) {
                $this->assertNotContains(strtolower((string) $pin), ['v7', 'v8', 'v9']);
            }
        }
    }
}
