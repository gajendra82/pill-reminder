<?php

namespace Tests\Feature;

use App\Models\DeviceScheduleSlot;
use App\Models\DoseEvent;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class MedicineScheduleUpdateTest extends TestCase
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
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Schedule update tests must use the isolated sqlite memory database.');
        }
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->schedule($owner, 'Aspirin', '08:00:00');

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '09:15',
        ])->assertUnauthorized();

        $this->assertSame('08:00:00', $schedule->fresh()->getRawOriginal('scheduled_time'));
        Http::assertNothingSent();
    }

    public function test_the_owner_can_update_only_the_reminder_time(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00', [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'starts_on' => '2026-10-23',
            'ends_on' => '2026-12-31',
            'grace_period_minutes' => 45,
        ]);
        $other = $schedule->medicine->schedules()->create([
            'scheduled_time' => '21:00:00',
            'days_of_week' => [1],
            'starts_on' => '2026-10-23',
            'grace_period_minutes' => 60,
            'is_active' => true,
        ]);
        $slot = DeviceScheduleSlot::query()->create([
            'user_id' => $user->id,
            'slot' => 1,
            'medicine_id' => $schedule->medicine_id,
            'schedule_id' => $schedule->id,
            'assigned_at' => '2026-10-22 20:38:58',
        ]);
        $event = DoseEvent::query()->create([
            'medicine_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-10-22 02:30:00',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '14:05',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Schedule timing updated.')
            ->assertJsonPath('schedule.id', $schedule->id)
            ->assertJsonPath('schedule.medicine_id', $schedule->medicine_id)
            ->assertJsonPath('schedule.scheduled_time', '14:05:00')
            ->assertJsonPath('schedule.days_of_week', [1, 2, 3, 4, 5, 6, 7])
            ->assertJsonPath('schedule.starts_on', '2026-10-23')
            ->assertJsonPath('schedule.ends_on', '2026-12-31')
            ->assertJsonPath('schedule.grace_period_minutes', 45)
            ->assertJsonPath('schedule.is_active', true);

        $fresh = $schedule->fresh();
        $this->assertSame('14:05:00', $fresh->getRawOriginal('scheduled_time'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $fresh->days_of_week);
        $this->assertSame('2026-10-23', $fresh->starts_on?->toDateString());
        $this->assertSame('2026-12-31', $fresh->ends_on?->toDateString());
        $this->assertSame(45, (int) $fresh->grace_period_minutes);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertSame('Aspirin', $fresh->medicine->name);
        $this->assertSame(1, Medicine::query()->count());
        $this->assertSame(2, MedicineSchedule::query()->count());
        $this->assertSame('21:00:00', $other->fresh()->getRawOriginal('scheduled_time'));

        $freshSlot = $slot->fresh();
        $this->assertSame(1, (int) $freshSlot->slot);
        $this->assertSame((int) $schedule->medicine_id, (int) $freshSlot->medicine_id);
        $this->assertSame((int) $schedule->id, (int) $freshSlot->schedule_id);
        $this->assertSame('2026-10-22 20:38:58', $freshSlot->assigned_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, DeviceScheduleSlot::query()->count());
        $this->assertSame('2026-10-22 02:30:00', $event->fresh()->scheduled_for?->format('Y-m-d H:i:s'));
        Http::assertNothingSent();
    }

    public function test_an_explicit_zero_second_time_is_stored_without_changing_other_fields(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00', [
            'days_of_week' => null,
            'grace_period_minutes' => 60,
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '09:07:00',
        ])->assertOk()
            ->assertJsonPath('schedule.scheduled_time', '09:07:00')
            ->assertJsonPath('schedule.days_of_week', null)
            ->assertJsonPath('schedule.grace_period_minutes', 60);

        $this->assertSame('09:07:00', $schedule->fresh()->getRawOriginal('scheduled_time'));
        Http::assertNothingSent();
    }

    public function test_invalid_times_and_disallowed_fields_do_not_change_the_schedule(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00', [
            'days_of_week' => [1, 2, 3, 4, 5],
            'grace_period_minutes' => 60,
        ]);
        Sanctum::actingAs($user);

        foreach (['24:00', '23:60', '08:00:60', '08:00:30', '8:30', '08:00:00.5', '25:01:00'] as $time) {
            $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
                'scheduled_time' => $time,
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['scheduled_time'])
                ->assertJsonPath('errors.scheduled_time.0', 'The reminder time must be HH:mm or HH:mm:00.');
        }

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.scheduled_time.0', 'A reminder time is required.');

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '10:00',
            'user_id' => $user->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '10:00',
            'medicine_id' => 99,
        ])->assertStatus(422)->assertJsonValidationErrors(['medicine_id']);

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '10:00',
            'days_of_week' => [1],
        ])->assertStatus(422)->assertJsonValidationErrors(['days_of_week']);

        $fresh = $schedule->fresh();
        $this->assertSame('08:00:00', $fresh->getRawOriginal('scheduled_time'));
        $this->assertSame([1, 2, 3, 4, 5], $fresh->days_of_week);
        $this->assertSame(60, (int) $fresh->grace_period_minutes);
        $this->assertSame(1, MedicineSchedule::query()->count());
        Http::assertNothingSent();
    }

    public function test_another_users_schedule_is_not_found_and_is_not_changed(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->schedule($owner, 'Owner medicine', '01:40:00', [
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        $slot = DeviceScheduleSlot::query()->create([
            'user_id' => $owner->id,
            'slot' => 1,
            'medicine_id' => $schedule->medicine_id,
            'schedule_id' => $schedule->id,
            'assigned_at' => '2026-10-22 20:38:58',
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '02:15',
        ])->assertNotFound()
            ->assertJsonPath('message', 'Not found.')
            ->assertJsonMissingPath('schedule');

        $this->patchJson('/api/medicine-schedules/999999', [
            'scheduled_time' => '02:15',
        ])->assertNotFound()->assertJsonPath('message', 'Not found.');

        $this->assertSame('01:40:00', $schedule->fresh()->getRawOriginal('scheduled_time'));
        $this->assertSame((int) $schedule->id, (int) $slot->fresh()->schedule_id);
        $this->assertSame(1, (int) $slot->fresh()->slot);
        Http::assertNothingSent();
    }

    private function schedule(User $user, string $name, string $time, array $overrides = []): MedicineSchedule
    {
        $medicine = Medicine::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);

        return $medicine->schedules()->create(array_merge([
            'scheduled_time' => $time,
            'starts_on' => '2026-10-01',
            'is_active' => true,
        ], $overrides));
    }
}
