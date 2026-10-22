<?php

namespace Tests\Feature;

use App\Models\DemoActivityEvent;
use App\Models\DeviceScheduleSlot;
use App\Models\DoseEvent;
use App\Models\Medicine;
use App\Models\MedicineSchedule;
use App\Models\User;
use App\Services\DemoActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class DemoActivityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'dedicated-test-device-token';

    /** @var list<array{path: string, query: array<string, mixed>}> */
    private array $httpLog = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        config([
            'services.blynk.base_url' => 'https://blynk.test',
            'services.blynk.auth_token' => self::TOKEN,
        ]);
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
            throw new RuntimeException('Demo activity tests must use the isolated sqlite memory database.');
        }
    }

    public function test_unauthenticated_demo_requests_are_rejected(): void
    {
        $this->getJson('/api/dose-events?activity=demo')->assertUnauthorized();
        $this->getJson('/api/analytics')->assertUnauthorized();
        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => 1,
            'demo_mode' => true,
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_verified_demo_sync_is_recorded_once_and_is_not_a_dose(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10, 'v3' => 16, 'v4' => 26, 'v5' => 16, 'v6' => 50]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 8, 'v2' => 0, 'v3' => 16, 'v4' => 26, 'v5' => 16, 'v6' => 50]),
            $this->pins(['v1' => 8, 'v2' => 0, 'v3' => 16, 'v4' => 26, 'v5' => 16, 'v6' => 50]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 8, 'v2' => 0, 'v3' => 16, 'v4' => 26, 'v5' => 16, 'v6' => 50]),
        ]);

        $response = $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('demo_event_recorded', true)
            ->assertJsonPath('demo_event.demo', true)
            ->assertJsonPath('demo_event.demo_label', 'DEMO')
            ->assertJsonPath('demo_event.event_type', 'demo_schedule_synced')
            ->assertJsonPath('demo_event.status', 'demo_schedule_synced')
            ->assertJsonPath('demo_event.buzzer_confirmed', false)
            ->assertJsonPath('demo_event.confirmation_method', 'server_read_back')
            ->assertJsonPath('demo_event.medicine.name', 'Aspirin')
            ->assertJsonPath('demo_event.schedule.scheduled_time', '08:00:00')
            ->assertJsonPath('demo_event.scheduled_date', '2026-10-22');

        $this->assertStringStartsWith('2026-10-22T02:30:00', (string) $response->json('demo_event.scheduled_for'));
        $this->assertStringStartsWith('2026-10-22T12:00:00', (string) $response->json('demo_event.occurred_at'));
        $this->assertStringContainsString('does not mean the buzzer rang', (string) $response->json('demo_event.description'));
        $this->assertSame(1, DemoActivityEvent::query()->count());
        $this->assertSame(0, DoseEvent::query()->count());
        $this->assertNoStatusPins();

        $again = $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ]);

        $again->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('demo_event_recorded', false)
            ->assertJsonPath('demo_event_duplicate', true)
            ->assertJsonPath('demo_event.event_type', 'demo_schedule_synced');
        $this->assertSame(1, DemoActivityEvent::query()->count());
        $this->assertSame(0, DoseEvent::query()->count());
    }

    public function test_normal_failed_partial_and_unconfirmed_syncs_are_not_recorded(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSynced(8, 0);

        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
        ])->assertOk()
            ->assertJsonPath('result', 'synced')
            ->assertJsonPath('demo_mode', false)
            ->assertJsonMissingPath('demo_event');

        $this->assertSame(0, DemoActivityEvent::query()->count());
    }

    public function test_a_partial_demo_sync_is_not_recorded(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response(['error' => ['message' => 'No datastream setup for that pin.']], 500),
        ]);

        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertStatus(502)->assertJsonPath('result', 'partial_write');

        $this->assertSame(0, DemoActivityEvent::query()->count());
        $this->assertSame(0, DoseEvent::query()->count());
    }

    public function test_an_unconfirmed_demo_sync_is_not_recorded(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 1, 'v2' => 2]),
        ]);

        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertStatus(409)->assertJsonPath('result', 'unconfirmed');

        $this->assertSame(0, DemoActivityEvent::query()->count());
    }

    public function test_a_failed_demo_sync_is_not_recorded(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSequence([
            Http::response(['error' => ['message' => 'Invalid token.']], 400),
        ]);

        $failed = $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ]);

        $failed->assertStatus(502)->assertJsonPath('result', 'failed');
        $this->assertStringNotContainsString(self::TOKEN, $failed->getContent());
        $this->assertSame(0, DemoActivityEvent::query()->count());
    }

    public function test_a_deferred_sync_is_not_recorded(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '17:30:00');
        Sanctum::actingAs($user);
        $this->fakeSequence([$this->pins(['v1' => 3, 'v2' => 10])]);

        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => false,
        ])->assertStatus(409)->assertJsonPath('result', 'sync_deferred');

        $this->assertSame(0, DemoActivityEvent::query()->count());
    }

    public function test_editing_a_time_does_not_sync_or_record_demo_activity(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);

        $this->patchJson('/api/medicine-schedules/'.$schedule->id, [
            'scheduled_time' => '09:15',
        ])->assertOk()->assertJsonPath('schedule.scheduled_time', '09:15:00');

        $this->assertSame(0, DemoActivityEvent::query()->count());
        $this->assertSame(0, DoseEvent::query()->count());
        $this->assertSame(1, (int) DeviceScheduleSlot::query()->value('schedule_id'));
        Http::assertNothingSent();
    }

    public function test_demo_history_is_labeled_and_filtered_apart_from_real_doses(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSynced(8, 0);
        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertOk();

        $real = $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertCreated();
        $real->assertJsonPath('dose_event.status', 'taken')
            ->assertJsonPath('dose_event.demo', false)
            ->assertJsonPath('dose_event.demo_label', null);

        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'missed',
        ])->assertStatus(409)->assertJsonPath('result', 'status_transition_not_allowed');
        $this->assertSame('taken', DoseEvent::query()->first()->status);

        $history = $this->getJson('/api/dose-events');
        $history->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonPath('data.0.demo', false);

        $demo = $this->getJson('/api/dose-events?activity=demo');
        $demo->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.demo_label', 'DEMO')
            ->assertJsonPath('data.0.status', 'demo_schedule_synced')
            ->assertJsonPath('data.0.event_type', 'demo_schedule_synced')
            ->assertJsonPath('data.0.medicine.name', 'Aspirin')
            ->assertJsonPath('data.0.schedule.scheduled_time', '08:00:00')
            ->assertJsonPath('data.0.confirmation_method', 'server_read_back')
            ->assertJsonPath('data.0.buzzer_confirmed', false);
        $this->assertNotSame('taken', $demo->json('data.0.status'));

        $this->getJson('/api/dose-events?activity=demo&status=taken')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);

        $this->getJson('/api/dose-events?activity=demo&status=demo_schedule_synced&from=2026-10-22&to=2026-10-22')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/dose-events?status=demo_schedule_synced')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $all = $this->getJson('/api/dose-events?activity=all&per_page=1');
        $all->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->assertNotNull($all->json('next_page_url'));
    }

    public function test_explicit_demo_taken_confirmation_is_separate_and_not_duplicated(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->assigned($owner, 'Aspirin', '08:00:00');
        Sanctum::actingAs($owner);

        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertStatus(422)->assertJsonPath('result', 'demo_sync_required');

        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => false,
        ])->assertStatus(422)->assertJsonValidationErrors(['demo_mode']);

        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => true,
            'event_type' => 'demo_reminder_triggered',
        ])->assertStatus(422)->assertJsonValidationErrors(['event_type']);

        $this->assertSame(0, DemoActivityEvent::query()->count());

        $this->fakeSynced(8, 0);
        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertOk();

        $created = $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => true,
        ]);
        $created->assertCreated()
            ->assertJsonPath('demo_event.event_type', 'demo_taken')
            ->assertJsonPath('demo_event.status', 'demo_taken')
            ->assertJsonPath('demo_event.demo_label', 'DEMO')
            ->assertJsonPath('demo_event.confirmation_method', 'user')
            ->assertJsonPath('demo_event.buzzer_confirmed', false);
        $this->assertStringContainsString('user confirmed', (string) $created->json('demo_event.description'));

        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertStatus(409)->assertJsonPath('result', 'duplicate_demo_event');

        $this->assertSame(1, DemoActivityEvent::query()->where('event_type', 'demo_taken')->count());
        $this->assertSame(0, DoseEvent::query()->count());

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertNotFound()->assertJsonPath('message', 'Not found.');
        $this->getJson('/api/dose-events?activity=demo')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/demo-activity/'.$created->json('demo_event.id'))
            ->assertNotFound()
            ->assertJsonPath('message', 'Not found.');

        Sanctum::actingAs($owner);
        $this->getJson('/api/demo-activity/'.$created->json('demo_event.id'))
            ->assertOk()
            ->assertJsonPath('demo_event.medicine.name', 'Aspirin');
    }

    public function test_a_device_trigger_is_not_accepted_from_the_client_and_can_be_recorded_once_by_the_service(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        Sanctum::actingAs($user);
        $this->fakeSynced(8, 0);
        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertOk();

        $this->postJson('/api/demo-activity/triggered', [
            'medicine_schedule_id' => $schedule->id,
        ])->assertNotFound();
        $this->assertSame(0, DemoActivityEvent::query()->where('event_type', 'demo_reminder_triggered')->count());

        $service = app(DemoActivityService::class);
        $first = $service->recordDeviceTrigger($schedule, Carbon::now());
        $second = $service->recordDeviceTrigger($schedule, Carbon::now());

        $this->assertTrue($first['demo_event_recorded']);
        $this->assertTrue($second['demo_event_duplicate']);
        $this->assertSame('demo_reminder_triggered', $first['demo_event']['status']);
        $this->assertTrue($first['demo_event']['buzzer_confirmed']);
        $this->assertSame(1, DemoActivityEvent::query()->where('event_type', 'demo_reminder_triggered')->count());
        $this->assertSame(0, DoseEvent::query()->count());

        $this->getJson('/api/dose-events?activity=demo&status=demo_reminder_triggered')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'demo_reminder_triggered')
            ->assertJsonPath('data.0.demo_label', 'DEMO');
    }

    public function test_demo_misses_require_a_confirmed_trigger_and_stay_out_of_adherence(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00', 60);
        Sanctum::actingAs($user);
        Carbon::setTestNow(Carbon::parse('2026-10-22 02:30:00', 'UTC'));
        $this->fakeSynced(8, 0);
        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertOk();

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Demo missed events: 0')
            ->assertSuccessful();
        $this->assertSame(0, DemoActivityEvent::query()->where('event_type', 'demo_missed')->count());

        app(DemoActivityService::class)->recordDeviceTrigger($schedule, Carbon::now());
        $this->artisan('doses:maintain', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: no records were changed.')
            ->expectsOutputToContain('Demo missed events: 0')
            ->assertSuccessful();
        $this->assertSame(0, DemoActivityEvent::query()->where('event_type', 'demo_missed')->count());

        Carbon::setTestNow(Carbon::parse('2026-10-22 03:30:00', 'UTC'));
        $this->artisan('doses:maintain', ['--dry-run' => true])
            ->expectsOutputToContain('Demo missed events: 1')
            ->assertSuccessful();
        $this->assertSame(0, DemoActivityEvent::query()->where('event_type', 'demo_missed')->count());

        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Demo missed events: 1')
            ->assertSuccessful();
        $this->artisan('doses:maintain')
            ->expectsOutputToContain('Demo missed events: 0')
            ->assertSuccessful();

        $this->assertSame(1, DemoActivityEvent::query()->where('event_type', 'demo_missed')->count());
        $this->assertSame('scheduler', DemoActivityEvent::query()->where('event_type', 'demo_missed')->value('confirmation_method'));
        $this->assertSame(1, DoseEvent::query()->where('status', 'missed')->count());

        $analytics = $this->getJson('/api/analytics');
        $analytics->assertOk()
            ->assertJsonPath('adherence.missed', 1)
            ->assertJsonPath('adherence.taken', 0)
            ->assertJsonPath('adherence.adherence_percent', 0)
            ->assertJsonPath('adherence.includes_demo_activity', false)
            ->assertJsonPath('demo_activity.schedules_synced', 1)
            ->assertJsonPath('demo_activity.reminder_triggers', 1)
            ->assertJsonPath('demo_activity.taken', 0)
            ->assertJsonPath('demo_activity.missed', 1)
            ->assertJsonPath('demo_activity.included_in_adherence', false);
        $this->assertSame(1, $analytics->json('adherence.missed'));
        $this->assertSame(1, $analytics->json('demo_activity.missed'));
    }

    public function test_analytics_keep_demo_confirmations_out_of_real_adherence_and_other_users(): void
    {
        $owner = User::factory()->create();
        $schedule = $this->assigned($owner, 'Aspirin', '08:00:00');
        $other = User::factory()->create();
        $otherSchedule = $this->assigned($other, 'Secret', '09:00:00');
        DoseEvent::query()->create([
            'medicine_schedule_id' => $otherSchedule->id,
            'scheduled_for' => '2026-10-22 03:30:00',
            'status' => 'taken',
            'confirmation_method' => 'user',
            'confirmed_taken_at' => '2026-10-22 03:30:00',
        ]);

        Sanctum::actingAs($owner);
        $this->fakeSynced(8, 0);
        $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertOk();
        $this->postJson('/api/demo-activity/taken', [
            'medicine_schedule_id' => $schedule->id,
            'demo_mode' => true,
        ])->assertCreated();
        $this->postJson('/api/dose-events', [
            'medicine_schedule_id' => $schedule->id,
            'status' => 'taken',
        ])->assertCreated();

        $this->getJson('/api/analytics')
            ->assertOk()
            ->assertJsonPath('adherence.taken', 1)
            ->assertJsonPath('adherence.missed', 0)
            ->assertJsonPath('adherence.adherence_percent', 100)
            ->assertJsonPath('demo_activity.schedules_synced', 1)
            ->assertJsonPath('demo_activity.taken', 1)
            ->assertJsonPath('demo_activity.reminder_triggers', 0)
            ->assertJsonPath('demo_activity.missed', 0);

        $this->getJson('/api/analytics?from=2026-10-23&to=2026-10-23')
            ->assertOk()
            ->assertJsonPath('adherence.taken', 0)
            ->assertJsonPath('adherence.adherence_percent', null)
            ->assertJsonPath('demo_activity.schedules_synced', 0);

        Sanctum::actingAs($other);
        $this->getJson('/api/analytics')
            ->assertOk()
            ->assertJsonPath('adherence.taken', 1)
            ->assertJsonPath('demo_activity.schedules_synced', 0)
            ->assertJsonPath('demo_activity.taken', 0);
    }

    public function test_a_demo_sync_near_midnight_uses_the_india_standard_time_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 18:31:00', 'UTC'));
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '00:30:00');
        $schedule->starts_on = '2026-10-23';
        $schedule->save();
        Sanctum::actingAs($user);
        $this->fakeSequence([
            $this->pins(['v1' => 3, 'v2' => 10]),
            Http::response('', 200),
            Http::response('', 200),
            $this->pins(['v1' => 0, 'v2' => 30]),
        ]);

        $response = $this->postJson('/api/device/schedule-slots/1/sync', [
            'schedule_id' => $schedule->id,
            'demo_mode' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('demo_event.scheduled_date', '2026-10-23')
            ->assertJsonPath('demo_event.schedule.scheduled_time', '00:30:00');
        $this->assertStringStartsWith('2026-10-22T19:00:00', (string) $response->json('demo_event.scheduled_for'));

        $this->getJson('/api/dose-events?activity=demo&from=2026-10-23&to=2026-10-23')
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson('/api/dose-events?activity=demo&from=2026-10-22&to=2026-10-22')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.scheduled_date', '2026-10-23');
    }

    public function test_an_empty_demo_table_returns_filtered_pages_without_real_doses(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        DoseEvent::query()->create([
            'medicine_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-10-22 02:30:00',
            'status' => 'taken',
            'confirmation_method' => 'user',
            'confirmed_taken_at' => '2026-10-22 12:00:00',
        ]);
        Sanctum::actingAs($user);

        $demo = $this->getJson('/api/dose-events?activity=demo&page=1&per_page=20&status=demo_taken&medicine_schedule_id='.$schedule->id.'&from=2026-10-22&to=2026-10-23');

        $demo->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('last_page', 1)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', [])
            ->assertJsonPath('demo_activity_available', true);

        $all = $this->getJson('/api/dose-events?activity=all&page=1&per_page=1&from=2026-10-22&to=2026-10-22');
        $all->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonPath('data.0.demo', false)
            ->assertJsonPath('demo_activity_available', true);

        $this->getJson('/api/dose-events?page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonMissingPath('demo_activity_available');

        $this->getJson('/api/analytics')
            ->assertOk()
            ->assertJsonPath('adherence.taken', 1)
            ->assertJsonPath('adherence.includes_demo_activity', false)
            ->assertJsonPath('demo_activity.schedules_synced', 0)
            ->assertJsonPath('demo_activity.taken', 0);
    }

    public function test_missing_demo_storage_returns_an_empty_filtered_page_instead_of_failing(): void
    {
        $user = User::factory()->create();
        $schedule = $this->assigned($user, 'Aspirin', '08:00:00');
        DoseEvent::query()->create([
            'medicine_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-10-22 02:30:00',
            'status' => 'taken',
            'confirmation_method' => 'user',
            'confirmed_taken_at' => '2026-10-22 12:00:00',
        ]);
        Schema::dropIfExists('demo_activity_events');
        Sanctum::actingAs($user);

        $demo = $this->getJson('/api/dose-events?activity=demo&page=1&per_page=20&status=demo_reminder_triggered&from=2026-10-23&to=2026-10-24');
        $demo->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 20)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', [])
            ->assertJsonPath('demo_activity_available', false);

        $this->getJson('/api/dose-events?activity=all&page=1&per_page=20&status=demo_reminder_triggered')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', [])
            ->assertJsonPath('demo_activity_available', false);

        $this->getJson('/api/dose-events?activity=all&page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'taken')
            ->assertJsonPath('data.0.demo', false)
            ->assertJsonPath('demo_activity_available', false);

        $this->getJson('/api/dose-events?activity=real&page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.status', 'taken');

        $this->assertSame(1, DoseEvent::query()->count());
        $this->assertSame('taken', DoseEvent::query()->value('status'));
    }

    public function test_demo_maintenance_is_not_scheduled(): void
    {
        $this->assertStringNotContainsString('doses:maintain', (string) file_get_contents(base_path('routes/console.php')));
        $this->artisan('schedule:list')
            ->doesntExpectOutputToContain('doses:maintain')
            ->assertSuccessful();
    }

    private function assigned(User $user, string $name, string $time, int $grace = 60): MedicineSchedule
    {
        $medicine = Medicine::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'is_active' => true,
        ]);
        $schedule = $medicine->schedules()->create([
            'scheduled_time' => $time,
            'starts_on' => '2026-10-01',
            'grace_period_minutes' => $grace,
            'is_active' => true,
        ]);
        DeviceScheduleSlot::query()->create([
            'user_id' => $user->id,
            'slot' => DeviceScheduleSlot::query()->where('user_id', $user->id)->count() + 1,
            'medicine_id' => $medicine->id,
            'schedule_id' => $schedule->id,
            'assigned_at' => '2026-10-22 20:38:58',
        ]);

        return $schedule;
    }

    private function fakeSynced(int $hour, int $minute): void
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
        $this->httpLog = [];
        Http::fake(function (Request $request) use (&$step, $responses) {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            unset($query['token']);
            $this->httpLog[] = [
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

    private function assertNoStatusPins(): void
    {
        foreach ($this->httpLog as $call) {
            foreach (array_keys($call['query']) as $pin) {
                $this->assertNotContains(strtolower((string) $pin), ['v7', 'v8', 'v9']);
            }
        }
    }
}
