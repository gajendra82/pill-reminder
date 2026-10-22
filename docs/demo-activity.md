# Demo activity

Demo activity is stored in `demo_activity_events`, not in `dose_events`. Real adherence counts never include these rows. The migration is additive and must not be run against production until it is explicitly approved. Until that table exists, a verified demo sync still returns `synced` and sets `demo_activity_available` to false. It does not fail the device write. `GET /api/dose-events?activity=demo` and `activity=all` also return HTTP 200. Demo rows are an empty page, `demo_activity_available` is false, and real dose rows in `activity=all` are unchanged.

`scheduled_for` is the UTC instant of the schedule clock on the current Asia/Kolkata date. `scheduled_date` is that Asia/Kolkata calendar date. `occurred_at` is the UTC instant the event was recorded.

## Event types

| Event | When it is recorded | What it does not mean |
| --- | --- | --- |
| `demo_schedule_synced` | `POST /api/device/schedule-slots/{slot}/sync` with `demo_mode: true` returns `synced` after read-back | The buzzer rang, or medicine was taken |
| `demo_reminder_triggered` | A trusted device report for an occurrence that already has `demo_schedule_synced` | Medicine was taken. The current firmware does not send this report, and the API does not accept it from the client |
| `demo_taken` | `POST /api/demo-activity/taken` with `demo_mode: true` after a verified demo sync for today's occurrence | A device sensor saw the dose |
| `demo_missed` | `doses:maintain` sees a `demo_reminder_triggered` whose grace period has ended and no `demo_taken` exists | A demo sync was missed. A sync alone never becomes missed |

The same schedule, event type, and `scheduled_for` are stored once.

## Dose history

`GET /api/dose-events` defaults to real dose events. Each real row has `demo: false`.

`activity=demo` returns only demo rows. Each has `demo: true`, `demo_label: "DEMO"`, `event_type`, `status` equal to that event type, `description`, `occurred_at`, `confirmation_method`, the medicine name, and the schedule time. `activity=all` returns both. `status=taken` does not return `demo_taken` or `demo_reminder_triggered`.

## Analytics

`GET /api/analytics` returns `adherence` from `dose_events` and `demo_activity` from demo rows. `adherence_percent` is taken divided by taken plus missed. It is null when that denominator is zero. Both objects say that demo activity is not included in adherence.

## Firmware still required

`sketch_oct21a.ino` publishes schedule hours and minutes on V1–V6. V7–V9 are status outputs, and this API does not interpret them as a buzzer. A trustworthy trigger needs a new device event that identifies the slot and the RTC instant when the buzzer actually starts, without putting the Blynk token in the payload. Until that event exists, reminder-trigger and demo-missed counts stay at the number of events actually received.
