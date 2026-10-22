# Read-only Blynk device status

`GET /api/device/status` reads the current datastream values for one dedicated Blynk test device. It does not update Blynk, write schedules, or record dose history.

The virtual pin values are returned as Blynk stored them. This read endpoint does not interpret them. The verified firmware mapping, and the dry-run schedule proposal, are documented in `docs/blynk-schedule-dry-run.md`.

## Official endpoint

[Get All Datastreams values](https://docs.blynk.io/en/blynk.cloud/device-https-api/get-multiple-datastream-values) on the Device HTTPS API:

`GET https://{server_address}/external/api/getAll?token={device_auth_token}`

A successful response is a JSON object. Keys are the raw pin names Blynk returns, such as `v1` or `a0`. Pins with no stored value are omitted by Blynk. Documented failures include HTTP 400 with `{"error":{"message":"Invalid token."}}`.

`{server_address}` is the server origin shown at the bottom right of the Blynk console. Regional hosts documented by Blynk include:

- `https://blynk.cloud`
- `https://fra1.blynk.cloud`
- `https://lon1.blynk.cloud`
- `https://ny3.blynk.cloud`
- `https://sgp1.blynk.cloud`
- `https://blr1.blynk.cloud`

A wrong region can return HTTP 308. That `Location` header contains the device auth token. This client does not follow redirects and does not return that header.

The template ID is not part of this request. Do not add `BLYNK_DEVICE_TEMPLATE_ID`.

## Identifiers

These values are not interchangeable:

| Value | Where it comes from | Used by this endpoint |
|---|---|---|
| Server origin | Bottom right of the Blynk console | `BLYNK_BASE_URL` |
| Device auth token | Device info for one device | `BLYNK_AUTH_TOKEN` |
| Template ID | Device template / product | No |
| Device name | Name shown in the console | No |
| Numeric device ID | Platform API `deviceId` | No |

`BLYNK_AUTH_TOKEN` must be the auth token of the single dedicated test device. It is sent only as the `token` query parameter on the GET above.

## Configuration

Set these in the server environment. Do not commit them.

```
BLYNK_BASE_URL=https://ny3.blynk.cloud
BLYNK_AUTH_TOKEN=
```

`BLYNK_BASE_URL` is the origin only: `https`, a host, and an optional port. No path, query, user info, or token.

Leave `BLYNK_AUTH_TOKEN` empty in `.env.example`. Put the real device auth token only in the deployed environment, by hand.

Every authenticated API user who calls this route sees that same device. Use a token for a dedicated test device, not a patient's live device, until per-device ownership exists.

The client waits 5 seconds to connect and 10 seconds for the response. It does not retry.

## API

`GET /api/device/status`

Authentication is the existing Sanctum bearer token. The route does not accept a device id, token, URL, or pin list. Query parameters are ignored.

```json
{
  "device_status": "available",
  "data_source": "blynk_live",
  "integration_scope": "temporary_single_device",
  "retrieved_at": "2026-10-22T16:45:00Z",
  "datastreams": {
    "v1": "08:30",
    "v2": 15,
    "v3": null
  },
  "notice": "Temporary single-device integration. Datastream values are uninterpreted Blynk readings and are not a medication record."
}
```

`datastreams` keeps every pin Blynk returned, using Blynk's pin name and value. `v1` through `v9` are always present. A missing pin is JSON `null`. Null means Blynk did not return a stored value. It does not mean a dose was missed or taken.

`integration_scope` is `temporary_single_device` until a device record owns the token.

Errors use this shape and do not include the token, upstream URL, or Blynk response body:

```json
{
  "message": "Device status could not be retrieved.",
  "error": "blynk_unavailable"
}
```

| Condition | HTTP status | `error` |
|---|---|---|
| Missing or invalid `BLYNK_BASE_URL` or `BLYNK_AUTH_TOKEN` | 503 | `blynk_not_configured` |
| Blynk HTTP 400, 401, or 403 | 502 | `blynk_unauthorized` |
| Blynk HTTP 429 | 429 | `blynk_rate_limited` |
| Connection or timeout failure | 504 | `blynk_connection_failed` |
| HTTP 200 body is not the documented JSON object | 502 | `blynk_invalid_response` |
| Any other Blynk HTTP status, including 308 and 5xx | 502 | `blynk_unavailable` |
| No Sanctum token | 401 | Laravel's existing `Unauthenticated.` response |

## Limitations

- One server-side token is shared by every authenticated caller.
- Nothing checks `devices.user_id`. The `devices` table has no Blynk token column.
- The client only sends `GET /external/api/getAll`. It never calls `/external/api/update` or any other write.
- Pin values are not schedules and are not dose events.
- No medication history is created from this response.

## Later per-device credentials

1. Store each device auth token with the owning user, in a column that is hidden from API responses.
2. Resolve the device from the authenticated user. Return 404 for a device the user does not own, the same way medicines do.
3. Stop reading `BLYNK_AUTH_TOKEN` for that request.
4. Keep using `GET /external/api/getAll` with that device's own token.
5. Do not accept a token, device id, or pin list from the request body or query string.
