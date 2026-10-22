# Blynk schedule sync dry run

`GET /api/device/schedule-sync/dry-run` proposes one medicine per ESP8266 slot. It does not call Blynk and does not change datastreams.

Every response has `sync_mode: dry_run` and `writes_performed: false`. `can_apply` is true only when the proposal has no blocking validation error and no unsupported rule.

## Pins

| Slot | Medicine | Hour pin | Minute pin | Valid value |
|---|---|---|---|---|
| 1 | One medicine, one schedule | V1 | V2 | Hour 0–23, minute 0–59 |
| 2 | One medicine, one schedule | V3 | V4 | Hour 0–23, minute 0–59 |
| 3 | One medicine, one schedule | V5 | V6 | Hour 0–23, minute 0–59 |

V7, V8, and V9 are status pins. They are never schedule values and are not included in `proposed_datastreams`.

An unused slot has `status: unused` and null pin values. Null is not the same as `0`. `0` is a valid hour and a valid minute.

## What a daily reminder can represent

The firmware stores three hour/minute pairs in EEPROM and rings them from the DS1307. It has no start date, end date, weekday list, seconds, or grace window.

These fields do not change the clock time, so they are not written to V1–V6:

| Field | Dry-run treatment |
|---|---|
| Medicine name, dosage, instructions | Shown on the slot only. Not a timing rule. |
| `grace_period_minutes`, including the API default of 60 | Listed in `laravel_only_rules` with `applied_by_firmware: false`. Laravel can use it later. The device will not. |
| `starts_on` on or before the evaluation date | Accepted only as the assumption `start_date_daily_reminder`. The medicine must be active. The evaluation date uses `app.timezone` (UTC). The DS1307 local date is not verified. |
| No `starts_on` and no `ends_on` | A daily time with no date window. |

These fields change when a dose is due. They are not ignored:

| Field | Result |
|---|---|
| `days_of_week` | `unsupported_rules`. The device would ring every day. |
| `ends_on` | `unsupported_rules`. The device would keep ringing after the end date. |
| `starts_on` after the evaluation date | `unsupported_rules`. The device would ring before the medicine should start. |
| More than one active schedule on one medicine | `unsupported_multiple_schedules_for_medicine`. No schedule is chosen for that medicine. |
| Invalid hour or minute | `invalid_schedule_time`. That medicine is not placed in a slot. |
| The same clock time on two proposed slots | `duplicate_scheduled_time`. `can_apply` is false. |
| Non-zero seconds | `unsupported_rules`. The device stores hour and minute only. |
| Inactive medicine or inactive schedule | Listed in `unmapped_schedules`. It does not take a slot. |

`can_apply` is false when `validation_errors` or `unsupported_rules` is non-empty. Accepted start-date assumptions and Laravel-only grace rules do not by themselves block `can_apply`. They remain visible so they are not silent.

## Persistent slot assignment

A slot is not a schedule id and it is not a medicine id.

The migration `database/migrations/2026_10_22_180000_create_device_schedule_slots_table.php` creates `device_schedule_slots`. It has not been applied. Do not run it until the deployment steps below are approved.

Until that table exists, the dry run returns `slot_assignment_status: slot_assignment_persistence_unavailable`, `slot_assignment_persistent: false`, and `slot_stability: unavailable`. Every proposed pin is null. Medicines are not ranked by id, so this response cannot be used to move a physical reminder.

After the table exists, `slot_assignment_persistent` is true and `slot_stability` is `persistent`. The dry run reads stored rows and does not insert, move, or delete them. A medicine that has no row stays off the device. An occupied slot stays on that slot number when another medicine is added or when a different medicine is deleted.

Assignment is explicit:

- `GET /api/device/schedule-slots` lists the three physical slots for the authenticated user.
- `POST /api/device/schedule-slots` assigns an eligible medicine to the lowest free slot.
- `POST /api/device/schedule-slots/{slot}/release` clears that slot only.
- `POST /api/device/schedule-slots/{slot}/replace` changes the medicine or schedule in that slot only when `confirmed` is true.

The client cannot send `user_id`. A medicine with more than one active schedule is rejected. Assigning a medicine that already has a slot does not change its schedule. Deactivating a medicine leaves the row in place. Deleting an assigned medicine returns `slot_release_required` until that slot is released. Occupied slots are never overwritten by a new assignment. When all three slots are full, the result is `no_free_slot`.

`medicine_id` and `schedule_id` use `ON DELETE RESTRICT`. `user_id` uses `ON DELETE CASCADE`, matching `medicines` and `devices`. The slot column is an integer with a MySQL `CHECK (slot >= 1 AND slot <= 3)`. Unique keys are `(user_id, slot)`, `(user_id, medicine_id)`, and `schedule_id`.

### Approval required before production migration

1. Do not run `php artisan migrate` against the deployed database as part of this change.
2. Review `2026_10_22_180000_create_device_schedule_slots_table.php`. It creates an empty table. It does not copy or reorder existing medicines.
3. Back up the database and approve the migration explicitly.
4. Apply only that file after approval: `php artisan migrate --path=database/migrations/2026_10_22_180000_create_device_schedule_slots_table.php --force`
5. Existing medicines remain unassigned. Put each one on a slot with `POST /api/device/schedule-slots` before any later sync. A lower medicine id does not take a slot that is already occupied.
6. After a slot is occupied, `DELETE /api/medicines/{medicine}` returns HTTP 409 `slot_release_required` and includes the slot number. Release that slot before deleting the medicine. The foreign keys still reject a direct database delete.
7. Do not enable Blynk writes in the same step. The dry run still returns `sync_mode: dry_run` and `writes_performed: false`.

## Blocking results

| Code | Where | Meaning |
|---|---|---|
| `slot_assignment_persistence_unavailable` | `validation_errors` | The slot table is absent. No pins are proposed. |
| `missing_schedules` | `validation_errors` | No active schedule exists. |
| `no_slot_assignments` | `validation_errors` | Active schedules exist, but none is stored on a slot. |
| `unsupported_multiple_schedules_for_medicine` | `validation_errors` | That medicine has more than one active schedule. None of them is chosen. |
| `no_free_slot` | assignment API | All three slots are occupied. |
| `invalid_schedule_time` | `validation_errors` | The stored schedule time is not `HH:MM` or `HH:MM:SS` within 0–23 and 0–59. The slot stays held and its pins stay null. |
| `duplicate_scheduled_time` | `validation_errors` | Two proposed slots have the same clock time. |
| `days_of_week`, future `starts_on`, `ends_on`, `seconds` | `unsupported_rules` | The firmware would not match this schedule. |
| `grace_period_minutes` | `laravel_only_rules` | Not sent to the device. |

## Example

Two stored assignments. Medicine 9 is in slot 1 because it was assigned first. Medicine 4 stays in slot 2 even if its id is lower.

```json
{
  "sync_mode": "dry_run",
  "writes_performed": false,
  "can_apply": true,
  "slot_assignment_persistent": true,
  "slot_stability": "persistent",
  "slots": [
    {
      "slot": 1,
      "status": "proposed",
      "medicine_id": 9,
      "medicine_name": "Vitamin",
      "schedule_id": 10,
      "scheduled_time": "22:00",
      "hour_pin": "V1",
      "minute_pin": "V2"
    },
    {
      "slot": 2,
      "status": "proposed",
      "medicine_id": 4,
      "medicine_name": "Aspirin",
      "schedule_id": 11,
      "scheduled_time": "07:15",
      "hour_pin": "V3",
      "minute_pin": "V4"
    },
    {
      "slot": 3,
      "status": "unused",
      "medicine_id": null,
      "medicine_name": null,
      "schedule_id": null,
      "scheduled_time": null,
      "hour_pin": "V5",
      "minute_pin": "V6"
    }
  ],
  "proposed_datastreams": {
    "V1": 22,
    "V2": 0,
    "V3": 7,
    "V4": 15,
    "V5": null,
    "V6": null
  },
  "validation_errors": [],
  "unsupported_rules": []
}
```

The route is under `auth:sanctum`. It ignores query parameters. It does not accept a device id, token, or pin list. No Blynk write is performed.
