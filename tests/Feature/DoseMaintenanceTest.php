<?php

namespace Tests\Feature;

use App\Models\DoseEvent;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use App\Services\DoseOccurrenceCalculator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DoseMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Http::assertNothingSent();
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Dose maintenance tests must use the isolated sqlite memory database.');
        }
    }

    public function test_clock_times_convert_from_asia_kolkata_to_utc_across_midnight(): void
    {
        $calculator = app(DoseOccurrenceCalculator::class);

        $this->assertSame('2026-10-22', $calculator->localDate());
        $this->assertSame(4, $calculator->isoWeekday('2026-10-22'));

        $morning = $calculator->scheduledInstant('2026-10-22', '08:00:00');
        $this->assertNotNull($morning);
        $this->assertSame('2026-10-22 02:30:00', $morning->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $morning->timezoneName);

        $midnight = $calculator->scheduledInstant('2026-10-23', '00:00:00');
        $this->assertNotNull($midnight);
        $this->assertSame('2026-10-22 18:30:00', $midnight->format('Y-m-d H:i:s'));

        $this->assertNull($calculator->scheduledInstant('2026-10-22', '24:00:00'));
        $this->assertNull($calculator->scheduledInstant('2026-10-22', '08:60:00'));
        $this->assertNull($calculator->scheduledInstant('2026-10-22', '08:00:60'));
        $this->assertNull($calculator->scheduledInstant('2026-10-22', '99:99:99'));

        Carbon::setTestNow(Carbon::parse('2026-10-22 18:31:00', 'UTC'));

        $this->assertSame('2026-10-23', $calculator->localDate());
        $this->assertSame(5, $calculator->isoWeekday($calculator->localDate()));

        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Boundary', '08:00:00');
        Sanctum::actingAs($user);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertCreated();

        $this->assertSame(
            '2026-10-23 02:30:00',
            DoseEvent::query()->sole()->scheduled_for?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_manual_recording_and_generation_use_the_same_occurrence(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Evening', '18:00:00', ['grace_period_minutes' => 60]);
        Sanctum::actingAs($user);

        $this->artisan('doses:maintain')->assertSuccessful();

        $event = DoseEvent::query()->sole();
        $this->assertSame('pending', $event->status);
        $this->assertSame('2026-10-22 12:30:00', $event->scheduled_for?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($event->confirmation_method);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertOk()
            ->assertJsonPath('dose_event.id', $event->id)
            ->assertJsonPath('dose_event.status', 'taken')
            ->assertJsonPath('dose_event.confirmation_method', 'user');

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('New dose events created: 0')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();

        $event->refresh();
        $this->assertSame('taken', $event->status);
        $this->assertSame('user', $event->confirmation_method);
        $this->assertSame('2026-10-22 12:30:00', $event->scheduled_for?->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($event->confirmed_taken_at);
        $this->assertNull($event->missed_at);
        $this->assertSame(1, DoseEvent::query()->count());
    }

    public function test_generation_respects_weekdays_dates_activity_and_does_not_backfill(): void
    {
        $user = User::factory()->create();
        $daily = $this->schedule($user, 'Daily', '20:00:00', ['days_of_week' => null]);
        $thursday = $this->schedule($user, 'Thursday', '20:10:00', ['days_of_week' => [4]]);
        $this->schedule($user, 'Other days', '20:20:00', ['days_of_week' => [1, 2, 3, 5, 6, 7]]);
        $this->schedule($user, 'No days', '20:30:00', ['days_of_week' => []]);
        $startsToday = $this->schedule($user, 'Starts today', '20:40:00', ['starts_on' => '2026-10-22']);
        $this->schedule($user, 'Starts tomorrow', '20:50:00', ['starts_on' => '2026-10-23']);
        $endsToday = $this->schedule($user, 'Ends today', '21:00:00', ['ends_on' => '2026-10-22']);
        $this->schedule($user, 'Ended yesterday', '21:10:00', ['ends_on' => '2026-10-21']);
        $inactiveMedicine = User::factory()->create();
        $this->schedule($inactiveMedicine, 'Inactive medicine', '21:20:00', ['medicine_active' => false]);
        $this->schedule($user, 'Inactive schedule', '21:30:00', ['schedule_active' => false]);
        $backfill = $this->schedule($user, 'Long running', '21:40:00', ['starts_on' => '2026-09-01']);

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Eligible schedules examined: 5')
            ->expectsOutputToContain('New dose events created: 5')
            ->expectsOutputToContain('Invalid schedules skipped: 0')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();

        $this->assertSame(5, DoseEvent::query()->count());
        $this->assertSame(
            ['2026-10-22 14:30:00'],
            $this->instants($daily->id),
        );
        $this->assertSame(['2026-10-22 14:40:00'], $this->instants($thursday->id));
        $this->assertSame(['2026-10-22 15:10:00'], $this->instants($startsToday->id));
        $this->assertSame(['2026-10-22 15:30:00'], $this->instants($endsToday->id));
        $this->assertSame(['2026-10-22 16:10:00'], $this->instants($backfill->id));
        $this->assertTrue(DoseEvent::query()->where('status', 'pending')->count() === 5);

        Carbon::setTestNow(Carbon::parse('2026-10-22 18:31:00', 'UTC'));
        $fridayOnly = $this->schedule($user, 'Friday only', '00:30:00', [
            'days_of_week' => [5],
            'starts_on' => '2026-10-23',
        ]);
        $thursdayOnly = $this->schedule($user, 'Thursday only', '00:40:00', [
            'days_of_week' => [4],
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-22',
        ]);

        $this->artisan('doses:maintain')->assertSuccessful();

        $this->assertSame(['2026-10-22 19:00:00'], $this->instants($fridayOnly->id));
        $this->assertSame([], $this->instants($thursdayOnly->id));
    }

    public function test_invalid_times_are_skipped_without_personal_data_or_stopping_the_run(): void
    {
        $entries = [];
        Log::listen(function (MessageLogged $event) use (&$entries): void {
            $entries[] = $event;
        });

        $user = User::factory()->create();
        $valid = $this->schedule($user, 'SecretName', '20:00:00');
        $invalid = $this->schedule($user, 'SecretName', '08:00:00');
        MedicineSchedule::query()->whereKey($invalid->id)->update(['scheduled_time' => '99:99:99']);

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Eligible schedules examined: 1')
            ->expectsOutputToContain('New dose events created: 1')
            ->expectsOutputToContain('Invalid schedules skipped: 1')
            ->doesntExpectOutputToContain('SecretName')
            ->doesntExpectOutputToContain('99:99:99')
            ->assertSuccessful();

        $this->assertSame(['2026-10-22 14:30:00'], $this->instants($valid->id));
        $this->assertSame([], $this->instants($invalid->id));
        $this->assertCount(1, $entries);
        $this->assertSame('warning', $entries[0]->level);
        $this->assertSame('dose_maintenance_invalid_schedule_time', $entries[0]->message);
        $this->assertSame([
            'schedule_id' => $invalid->id,
            'reason' => 'invalid_schedule_time',
        ], $entries[0]->context);
    }

    public function test_repeated_runs_and_the_unique_index_keep_one_row(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Daily', '20:00:00');

        $this->artisan('doses:maintain')->assertSuccessful();
        $first = DoseEvent::query()->sole();

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('New dose events created: 0')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();

        $first->refresh();
        $this->assertSame('pending', $first->status);
        $this->assertSame(1, DoseEvent::query()->count());

        try {
            DoseEvent::query()->create([
                'medicine_schedule_id' => $schedule->id,
                'scheduled_for' => $first->scheduled_for,
                'status' => 'pending',
            ]);
            $this->fail('The unique schedule and time index accepted a second row.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, DoseEvent::query()->count());
        }
    }

    public function test_grace_marks_pending_and_reminded_doses_at_the_threshold(): void
    {
        $user = User::factory()->create();
        $pendingSchedule = $this->schedule($user, 'Pending', '17:00:00', ['grace_period_minutes' => 60]);
        $remindedSchedule = $this->schedule($user, 'Reminded', '17:00:00', ['grace_period_minutes' => 60]);

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();

        $pending = DoseEvent::query()->where('medicine_schedule_id', $pendingSchedule->id)->sole();
        $reminded = DoseEvent::query()->where('medicine_schedule_id', $remindedSchedule->id)->sole();
        $reminded->forceFill(['status' => 'reminded'])->save();
        $this->assertSame('2026-10-22 11:30:00', $pending->scheduled_for?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($pending->missed_at);

        Carbon::setTestNow(Carbon::parse('2026-10-22 12:29:00', 'UTC'));
        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();
        $this->assertSame('pending', $pending->refresh()->status);
        $this->assertSame('reminded', $reminded->refresh()->status);

        Carbon::setTestNow(Carbon::parse('2026-10-22 12:30:00', 'UTC'));
        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 2')
            ->assertSuccessful();

        $pending->refresh();
        $reminded->refresh();
        $this->assertSame('missed', $pending->status);
        $this->assertSame('scheduler', $pending->confirmation_method);
        $this->assertSame('2026-10-22 12:30:00', $pending->missed_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('missed', $reminded->status);
        $this->assertSame('scheduler', $reminded->confirmation_method);
        $this->assertSame('2026-10-22 12:30:00', $reminded->missed_at?->utc()->format('Y-m-d H:i:s'));

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();
        $this->assertSame(
            '2026-10-22 12:30:00',
            $pending->refresh()->missed_at?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_grace_period_can_cross_local_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 16:00:00', 'UTC'));
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Late', '23:30:00', ['grace_period_minutes' => 60]);

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();

        $event = DoseEvent::query()->sole();
        $this->assertSame('2026-10-22 18:00:00', $event->scheduled_for?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('pending', $event->status);

        Carbon::setTestNow(Carbon::parse('2026-10-22 18:59:00', 'UTC'));
        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();
        $this->assertSame('pending', $event->refresh()->status);
        $this->assertSame(2, DoseEvent::query()->count());

        Carbon::setTestNow(Carbon::parse('2026-10-22 19:00:00', 'UTC'));
        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Events marked missed: 1')
            ->assertSuccessful();

        $event->refresh();
        $this->assertSame('missed', $event->status);
        $this->assertSame('scheduler', $event->confirmation_method);
        $this->assertSame('2026-10-22 19:00:00', $event->missed_at?->utc()->format('Y-m-d H:i:s'));

        $next = DoseEvent::query()->whereKeyNot($event->id)->sole();
        $this->assertSame($schedule->id, $next->medicine_schedule_id);
        $this->assertSame('pending', $next->status);
        $this->assertSame('2026-10-23 18:00:00', $next->scheduled_for?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($next->missed_at);
    }

    public function test_terminal_manual_and_sensor_events_stay_unchanged_past_grace(): void
    {
        $user = User::factory()->create();
        $cases = [
            'taken' => ['status' => 'taken', 'confirmation_method' => 'user', 'confirmed_taken_at' => '2026-10-22 04:00:00'],
            'skipped' => ['status' => 'skipped', 'confirmation_method' => 'user'],
            'missed' => ['status' => 'missed', 'confirmation_method' => 'user', 'missed_at' => '2026-10-22 04:05:00'],
            'sensor_detected' => ['status' => 'sensor_detected', 'sensor_detected_at' => '2026-10-22 04:10:00'],
        ];

        $originals = [];

        foreach ($cases as $name => $attributes) {
            $schedule = $this->schedule($user, $name, '08:00:00', ['grace_period_minutes' => 60]);
            $instant = app(DoseOccurrenceCalculator::class)->forScheduleToday($schedule);
            $originals[$name] = DoseEvent::query()->create(array_merge([
                'medicine_schedule_id' => $schedule->id,
                'scheduled_for' => $instant,
            ], $attributes));
        }

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Eligible schedules examined: 4')
            ->expectsOutputToContain('New dose events created: 0')
            ->expectsOutputToContain('Events marked missed: 0')
            ->assertSuccessful();

        foreach ($originals as $name => $event) {
            $fresh = $event->refresh();
            $this->assertSame($cases[$name]['status'], $fresh->status, $name);
            $this->assertSame($cases[$name]['confirmation_method'] ?? null, $fresh->confirmation_method, $name);
            $this->assertSame(
                $cases[$name]['confirmed_taken_at'] ?? null,
                $fresh->confirmed_taken_at?->utc()->format('Y-m-d H:i:s'),
                $name,
            );
            $this->assertSame(
                $cases[$name]['missed_at'] ?? null,
                $fresh->missed_at?->utc()->format('Y-m-d H:i:s'),
                $name,
            );
            $this->assertSame(
                $cases[$name]['sensor_detected_at'] ?? null,
                $fresh->sensor_detected_at?->utc()->format('Y-m-d H:i:s'),
                $name,
            );
        }

        $this->assertSame(4, DoseEvent::query()->count());
    }

    public function test_dry_run_reports_planned_work_without_writing(): void
    {
        $user = User::factory()->create();
        $existingSchedule = $this->schedule($user, 'SecretName', '08:00:00', ['grace_period_minutes' => 60]);
        $this->schedule($user, 'SecretName', '20:00:00', ['grace_period_minutes' => 60]);
        $existing = DoseEvent::query()->create([
            'medicine_schedule_id' => $existingSchedule->id,
            'scheduled_for' => '2026-10-22 02:30:00',
            'status' => 'pending',
        ]);

        $this->artisan('doses:maintain', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: no records were changed.')
            ->expectsOutputToContain('Eligible schedules examined: 2')
            ->expectsOutputToContain('New dose events created: 1')
            ->expectsOutputToContain('Invalid schedules skipped: 0')
            ->expectsOutputToContain('Events marked missed: 1')
            ->doesntExpectOutputToContain('SecretName')
            ->assertSuccessful();

        $this->assertSame(1, DoseEvent::query()->count());
        $existing->refresh();
        $this->assertSame('pending', $existing->status);
        $this->assertNull($existing->missed_at);
        $this->assertNull($existing->confirmation_method);
    }

    public function test_the_command_is_not_scheduled(): void
    {
        $console = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('doses:maintain', $console);
        $this->assertStringNotContainsString('Schedule::', $console);

        $this->artisan('schedule:list')
            ->doesntExpectOutputToContain('doses:maintain')
            ->assertSuccessful();

        $this->artisan('doses:maintain', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: no records were changed.')
            ->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function schedule(User $user, string $name, string $time, array $overrides = []): MedicineSchedule
    {
        $medicine = Medicine::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => $overrides['medicine_active'] ?? true,
        ]);

        return $medicine->schedules()->create([
            'scheduled_time' => $time,
            'days_of_week' => $overrides['days_of_week'] ?? null,
            'starts_on' => $overrides['starts_on'] ?? '2026-10-01',
            'ends_on' => $overrides['ends_on'] ?? null,
            'grace_period_minutes' => $overrides['grace_period_minutes'] ?? 60,
            'is_active' => $overrides['schedule_active'] ?? true,
        ]);
    }

    /**
     * @return list<string>
     */
    private function instants(int $scheduleId): array
    {
        return DoseEvent::query()
            ->where('medicine_schedule_id', $scheduleId)
            ->orderBy('scheduled_for')
            ->get()
            ->map(fn (DoseEvent $event) => $event->scheduled_for?->utc()->format('Y-m-d H:i:s'))
            ->all();
    }
}
