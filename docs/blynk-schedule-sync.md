# Explicit schedule synchronization

`POST /api/device/schedule-slots/{slot}/sync` is the only operation that writes schedule pins. The body must include `schedule_id`, and that id must be the schedule already stored on the authenticated user's slot. Assign, replace, release, medicine create, the slot list, and `GET /api/device/status` do not write to Blynk. A successful response includes `schedule_id`, `hour`, `minute`, `result`, and `timestamp`. Two pin updates are not one transaction: if the second write fails, `result` is `partial_write`.

## Official write endpoint

Verified from the Blynk Device HTTPS API documentation (`update-datastream-value`):

`GET https://{server}/external/api/update?token={token}&{pin}={value}`

Example shape: `https://blynk.cloud/external/api/update?token=...&v1=100`.

- One pin per request. The batch update endpoint is not used.
- The pin query key is lowercase and must start with `v`.
- Documented success is HTTP 200. The documentation does not define a response body, so HTTP 200 is not proof that the stored value changed.
- Documented HTTP 400 bodies include invalid token, wrong pin format, a value that does not match the datastream type, and no datastream for that pin.
- Redirects are not followed. A redirect location can contain the token.

After both writes return HTTP 200, the API reads `/external/api/getAll` again. `result` is `synced` only when those two values match the intended hour and minute. A synced response includes `verified_hour` and `verified_minute`, and the same values inside `read_back`.

`result` is `sync_deferred` when the safety window refuses the write, `partial_write` when only the first pin write is accepted, and `unconfirmed` when the stored values cannot be verified. Other validation and Blynk failures keep `result` as `failed` and put the specific reason in `code`.

Every sync response includes `demo_mode`. It is false when the field is omitted. `demo_mode: true` skips only the India Standard Time proximity deferral. Authentication, the stored schedule id, active medicine and schedule checks, invalid times, weekday and date restrictions, write acceptance, and read-back stay in force. The two pin writes are still not retried. A presenter must turn demo mode on explicitly. Near-time sync may be missed, and demo mode does not guarantee the buzzer will sound.

A verified demo sync records `demo_schedule_synced` only after read-back matches. That row is demo activity, not a dose, and it does not mean the buzzer rang. Failed, deferred, partial, and unconfirmed syncs do not record it. A normal sync does not record it. V7–V9 are not read as a buzzer signal. See `docs/demo-activity.md`.

## Firmware source

`sketch_oct21a.ino` was inspected. It uses V1/V2, V3/V4, and V5/V6 as the three daily hour/minute groups. Hours are 0–23 and minutes are 0–59. V7–V9 are status outputs. Each accepted write updates RAM and EEPROM immediately through the existing `BLYNK_WRITE` handlers, without a restart. The sketch cannot disable a slot and cannot apply weekdays or date ranges.

That source file does not prove the same sketch is flashed on the physical device. Confirm the flashed firmware before any live synchronization.

## Pins

| Slot | Hour | Minute |
| --- | --- | --- |
| 1 | V1 (0–23) | V2 (0–59) |
| 2 | V3 (0–23) | V4 (0–59) |
| 3 | V5 (0–23) | V6 (0–59) |

V7, V8, and V9 are never written. The request cannot supply a pin, an hour, or a minute. A write body is accepted only when it is empty or a JSON object without an error. Any other body is a malformed response and is not success. `synced` still requires the following read-back to match.

## Safety window

The ESP8266 applies each pin immediately and does not publish its RTC time. Laravel uses the current minute in Asia/Kolkata and a two-minute window. If the stored time, the new time, or both possible intermediate pairs are inside that window, the request is deferred and nothing is written.

This does not make pin ordering collision-free. India Standard Time is not the DS1307 clock.

If the first write is accepted and the second is not, the result is `partial_write`. The current pins are read once. There is no automatic retry and no rollback write.

## Empty slots

A released slot is not written as `00:00`. The ESP8266 cannot disable a slot, so the device can keep its last stored time until firmware can represent an unused slot.

A null `days_of_week` and a list that contains each ISO weekday 1 through 7 are daily schedules. The stored array is left unchanged. Any other weekday list, an end date, a `starts_on` after the current Asia/Kolkata date, multiple active schedules, and invalid times are rejected and are not written. The application timezone remains UTC.

## Deployment

Do not run this against a device until the code has been reviewed. Deploying the code does not write to Blynk by itself. Do not run a migration for this feature. Configure `BLYNK_BASE_URL` as an HTTPS origin and `BLYNK_AUTH_TOKEN` on the server. Do not put either value in logs, responses, or this document.
