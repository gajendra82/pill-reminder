<?php

namespace Tests\Feature;

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

class DoseEventTest extends TestCase
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
            throw new RuntimeException('Dose event tests must use the isolated sqlite memory database.');
        }
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => 1,
            'status' => 'taken',
        ])->assertUnauthorized();

        $this->getJson('/api/dose-events')->assertUnauthorized();
        $this->getJson('/api/dose-events/1')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_user_can_record_and_retrieve_a_manual_dose(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ]);

        $created->assertCreated()
            ->assertJsonPath('message', 'Dose event recorded.')
            ->assertJsonPath('dose_event.status', 'taken')
            ->assertJsonPath('dose_event.confirmation_method', 'user')
            ->assertJsonPath('dose_event.medicine.name', 'Aspirin')
            ->assertJsonPath('dose_event.schedule.id', $schedule->id)
            ->assertJsonPath('dose_event.sensor_detected_at', null)
            ->assertJsonPath('dose_event.reminder_triggered_at', null);

        $this->assertStringStartsWith('2026-10-22T02:30:00', (string) $created->json('dose_event.scheduled_for'));
        $this->assertStringStartsWith('2026-10-22T12:00:00', (string) $created->json('dose_event.confirmed_taken_at'));

        $id = $created->json('dose_event.id');

        $this->getJson('/api/dose-events')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('per_page', 20);

        $this->getJson('/api/dose-events/'.$id)
            ->assertOk()
            ->assertJsonPath('dose_event.id', $id)
            ->assertJsonPath('dose_event.medicine.name', 'Aspirin');

        Http::assertNothingSent();
    }

    public function test_another_users_schedule_and_events_are_hidden(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->schedule($owner, 'Owner medicine', '09:00:00');
        Sanctum::actingAs($owner);
        $eventId = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertCreated()->json('dose_event.id');

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'missed',
        ])->assertNotFound()->assertJsonPath('message', 'Not found.');

        $this->getJson('/api/dose-events/'.$eventId)
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        $this->getJson('/api/dose-events?medicine_schedule_id='.$schedule->id)
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);

        $this->assertSame('taken', DoseEvent::query()->find($eventId)->status);
    }

    public function test_invalid_statuses_filters_and_client_timestamps_are_rejected(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'eaten',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'sensor_detected',
        ])->assertStatus(422)->assertJsonPath('errors.status.0', 'The selected status is not a manual dose action.');

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'reminded',
        ])->assertStatus(422)->assertJsonPath('errors.status.0', 'The selected status is not a manual dose action.');

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
            'scheduled_for' => '2020-01-01 00:00:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_for']);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
            'user_id' => $user->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->getJson('/api/dose-events?from=2026-10-23&to=2026-10-22')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $this->getJson('/api/dose-events?from=22-10-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from']);

        $this->getJson('/api/dose-events?status=eaten')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/dose-events?per_page=51')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->assertSame(0, DoseEvent::query()->count());
    }

    public function test_history_is_paginated_newest_first_and_page_size_is_bounded(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);

        foreach (['2026-10-20 08:00:00', '2026-10-21 08:00:00', '2026-10-22 08:00:00'] as $when) {
            DoseEvent::query()->create([
                'medicine_schedule_id' => $schedule->id,
                'scheduled_for' => $when,
                'status' => 'taken',
                'confirmation_method' => 'user',
                'confirmed_taken_at' => $when,
            ]);
        }

        $page = $this->getJson('/api/dose-events?per_page=2&from=2026-10-20&to=2026-10-22');

        $page->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.medicine.name', 'Aspirin')
            ->assertJsonPath('data.0.schedule.id', $schedule->id)
            ->assertJsonPath('data.0.schedule.scheduled_time', '08:00:00')
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonPath('data.0.confirmation_method', 'user')
            ->assertJsonPath('data.1.medicine.name', 'Aspirin')
            ->assertJsonPath('data.1.schedule.id', $schedule->id)
            ->assertJsonPath('data.1.schedule.scheduled_time', '08:00:00')
            ->assertJsonPath('data.1.status', 'taken')
            ->assertJsonPath('data.1.confirmation_method', 'user');

        $this->assertNotNull($page->json('next_page_url'));
        $this->assertStringStartsWith('2026-10-22T08:00:00', (string) $page->json('data.0.scheduled_for'));
        $this->assertStringStartsWith('2026-10-21T08:00:00', (string) $page->json('data.1.scheduled_for'));

        $last = $this->getJson('/api/dose-events?per_page=2&page=2');

        $last->assertOk()
            ->assertJsonPath('current_page', 2)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('next_page_url', null)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.medicine.name', 'Aspirin')
            ->assertJsonPath('data.0.schedule.id', $schedule->id)
            ->assertJsonPath('data.0.schedule.scheduled_time', '08:00:00')
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonPath('data.0.confirmation_method', 'user');

        $this->assertStringStartsWith('2026-10-20T08:00:00', (string) $last->json('data.0.scheduled_for'));
    }

    public function test_history_filters_return_only_the_owners_matching_events(): void
    {
        $owner = User::factory()->create();
        $morning = $this->schedule($owner, 'Aspirin', '08:00:00');
        $evening = $this->schedule($owner, 'Evening pill', '21:00:00');
        $morningEarly = $this->event($morning, '2026-10-20 08:00:00', 'taken');
        $morningMiddle = $this->event($morning, '2026-10-21 08:00:00', 'taken');
        $morningLate = $this->event($morning, '2026-10-22 08:00:00', 'missed');
        $eveningMiddle = $this->event($evening, '2026-10-21 21:00:00', 'missed');
        $eveningLate = $this->event($evening, '2026-10-22 21:00:00', 'taken');

        $stranger = User::factory()->create();
        $secret = $this->schedule($stranger, 'Secret', '08:00:00');
        $secretEvent = $this->event($secret, '2026-10-21 08:00:00', 'taken');

        Sanctum::actingAs($owner);

        $taken = $this->getJson('/api/dose-events?status=taken&per_page=50');
        $taken->assertOk()->assertJsonPath('total', 3);
        $takenIds = collect($taken->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            $morningEarly->id,
            $morningMiddle->id,
            $eveningLate->id,
        ], $takenIds);
        foreach ($taken->json('data') as $row) {
            $this->assertSame('taken', $row['status']);
            $this->assertSame('user', $row['confirmation_method']);
        }

        $bySchedule = $this->getJson('/api/dose-events?medicine_schedule_id='.$morning->id.'&per_page=50');
        $bySchedule->assertOk()->assertJsonPath('total', 3);
        $this->assertEqualsCanonicalizing([
            $morningEarly->id,
            $morningMiddle->id,
            $morningLate->id,
        ], collect($bySchedule->json('data'))->pluck('id')->all());
        foreach ($bySchedule->json('data') as $row) {
            $this->assertSame($morning->id, $row['schedule']['id']);
            $this->assertSame('08:00:00', $row['schedule']['scheduled_time']);
            $this->assertSame('Aspirin', $row['medicine']['name']);
        }

        $inside = $this->getJson('/api/dose-events?from=2026-10-20&to=2026-10-21&per_page=50');
        $inside->assertOk()->assertJsonPath('total', 3);
        $insideIds = collect($inside->json('data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([
            $morningEarly->id,
            $morningMiddle->id,
            $eveningMiddle->id,
        ], $insideIds);
        $this->assertNotContains($morningLate->id, $insideIds);
        $this->assertNotContains($eveningLate->id, $insideIds);
        $this->assertNotContains($secretEvent->id, $insideIds);

        $combined = $this->getJson(
            '/api/dose-events?status=taken&medicine_schedule_id='.$morning->id.'&from=2026-10-21&to=2026-10-21'
        );
        $combined->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $morningMiddle->id)
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonPath('data.0.confirmation_method', 'user')
            ->assertJsonPath('data.0.medicine.name', 'Aspirin')
            ->assertJsonPath('data.0.schedule.id', $morning->id)
            ->assertJsonPath('data.0.schedule.scheduled_time', '08:00:00');
        $this->assertStringStartsWith('2026-10-21T08:00:00', (string) $combined->json('data.0.scheduled_for'));

        Http::assertNothingSent();
    }

    public function test_a_missing_dose_event_is_not_found_and_hides_another_users_event(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->schedule($owner, 'Owner medicine', '09:00:00');
        Sanctum::actingAs($owner);
        $eventId = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertCreated()->json('dose_event.id');

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $missing = $this->getJson('/api/dose-events/999999');
        $missing->assertNotFound();
        $missing->assertJsonMissingPath('dose_event');
        $this->assertStringNotContainsString('Owner medicine', $missing->getContent());

        $hidden = $this->getJson('/api/dose-events/'.$eventId);
        $hidden->assertNotFound();
        $hidden->assertJsonMissingPath('dose_event');
        $this->assertStringNotContainsString('Owner medicine', $hidden->getContent());

        $this->assertSame('taken', DoseEvent::query()->find($eventId)->status);
        Http::assertNothingSent();
    }

    public function test_an_invalid_stored_schedule_time_does_not_create_an_event(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00');
        MedicineSchedule::query()->whereKey($schedule->id)->update([
            'scheduled_time' => '99:99:99',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'The stored schedule time is not a valid clock time.')
            ->assertJsonPath('errors.medicine_schedule_id.0', 'The stored schedule time is not a valid clock time.');

        $this->assertSame(0, DoseEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_taken_missed_and_skipped_keep_their_status_and_timestamps(): void
    {
        $user = User::factory()->create();
        $takenSchedule = $this->schedule($user, 'Taken', '08:00:00');
        $missedSchedule = $this->schedule($user, 'Missed', '09:00:00');
        $skippedSchedule = $this->schedule($user, 'Skipped', '10:00:00');
        Sanctum::actingAs($user);

        $taken = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $takenSchedule->id,
            'status' => 'taken',
        ])->assertCreated();
        $taken->assertJsonPath('dose_event.status', 'taken')
            ->assertJsonPath('dose_event.confirmation_method', 'user')
            ->assertJsonPath('dose_event.missed_at', null)
            ->assertJsonPath('dose_event.sensor_detected_at', null)
            ->assertJsonPath('dose_event.reminder_triggered_at', null);
        $this->assertStringStartsWith('2026-10-22T02:30:00', (string) $taken->json('dose_event.scheduled_for'));
        $this->assertStringStartsWith('2026-10-22T12:00:00', (string) $taken->json('dose_event.confirmed_taken_at'));

        $missed = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $missedSchedule->id,
            'status' => 'missed',
        ])->assertCreated();
        $missedAt = $missed->json('dose_event.missed_at');
        $missed->assertJsonPath('dose_event.status', 'missed')
            ->assertJsonPath('dose_event.confirmation_method', 'user')
            ->assertJsonPath('dose_event.confirmed_taken_at', null)
            ->assertJsonPath('dose_event.sensor_detected_at', null)
            ->assertJsonPath('dose_event.reminder_triggered_at', null);
        $this->assertStringStartsWith('2026-10-22T03:30:00', (string) $missed->json('dose_event.scheduled_for'));
        $this->assertStringStartsWith('2026-10-22T12:00:00', (string) $missedAt);

        $skipped = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $skippedSchedule->id,
            'status' => 'skipped',
        ])->assertCreated();
        $skipped->assertJsonPath('dose_event.status', 'skipped')
            ->assertJsonPath('dose_event.confirmation_method', 'user')
            ->assertJsonPath('dose_event.confirmed_taken_at', null)
            ->assertJsonPath('dose_event.missed_at', null)
            ->assertJsonPath('dose_event.sensor_detected_at', null)
            ->assertJsonPath('dose_event.reminder_triggered_at', null);
        $this->assertStringStartsWith('2026-10-22T04:30:00', (string) $skipped->json('dose_event.scheduled_for'));

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $skippedSchedule->id,
            'status' => 'skipped',
        ])->assertStatus(409)->assertJsonPath('result', 'duplicate_dose_event');

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $missedSchedule->id,
            'status' => 'taken',
        ])->assertStatus(409)->assertJsonPath('result', 'status_transition_not_allowed');

        $storedMissed = DoseEvent::query()->find($missed->json('dose_event.id'));
        $storedSkipped = DoseEvent::query()->find($skipped->json('dose_event.id'));
        $this->assertSame('missed', $storedMissed->status);
        $this->assertNull($storedMissed->confirmed_taken_at);
        $this->assertSame($missedAt, $storedMissed->missed_at?->toIso8601String());
        $this->assertSame('skipped', $storedSkipped->status);
        $this->assertNull($storedSkipped->confirmed_taken_at);
        $this->assertNull($storedSkipped->missed_at);
        $this->assertSame(3, DoseEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_duplicate_events_and_terminal_statuses_are_not_overwritten(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertCreated();

        $takenAt = $first->json('dose_event.confirmed_taken_at');

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertStatus(409)->assertJsonPath('result', 'duplicate_dose_event');

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'missed',
        ])->assertStatus(409)->assertJsonPath('result', 'status_transition_not_allowed');

        $stored = DoseEvent::query()->find($first->json('dose_event.id'));
        $this->assertSame('taken', $stored->status);
        $this->assertSame($takenAt, $stored->confirmed_taken_at?->toIso8601String());
        $this->assertNull($stored->missed_at);
        $this->assertSame(1, DoseEvent::query()->count());
    }

    public function test_an_open_event_can_move_to_a_manual_outcome(): void
    {
        $user = User::factory()->create();
        $schedule = $this->schedule($user, 'Aspirin', '08:00:00');
        $event = DoseEvent::query()->create([
            'medicine_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-10-22 02:30:00',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'skipped',
        ])->assertOk()
            ->assertJsonPath('message', 'Dose event updated.')
            ->assertJsonPath('dose_event.id', $event->id)
            ->assertJsonPath('dose_event.status', 'skipped')
            ->assertJsonPath('dose_event.confirmation_method', 'user')
            ->assertJsonPath('dose_event.confirmed_taken_at', null);

        $this->assertSame(1, DoseEvent::query()->count());
    }

    private function schedule(User $user, string $name, string $time): MedicineSchedule
    {
        $medicine = Medicine::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);

        return $medicine->schedules()->create([
            'scheduled_time' => $time,
            'starts_on' => '2026-10-01',
            'is_active' => true,
        ]);
    }

    private function event(MedicineSchedule $schedule, string $when, string $status): DoseEvent
    {
        return DoseEvent::query()->create([
            'medicine_schedule_id' => $schedule->id,
            'scheduled_for' => $when,
            'status' => $status,
            'confirmation_method' => 'user',
            'confirmed_taken_at' => $status === 'taken' ? $when : null,
            'missed_at' => $status === 'missed' ? $when : null,
        ]);
    }
}
