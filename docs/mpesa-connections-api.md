# M-Pesa connections: dashboard spec and API contract

**For:** KomiutWebApp, the M-Pesa page, used by SACCO admins (e.g. Lydia at NICCO)
**Backend:** `feat/mpesa-connections` (live once merged to `staging`)

## What a connection is

A **connection** is one Daraja app: the API credentials for one head-office short code that a SACCO collects through.

- NICCO has about 25, for example "KDQ's" 5142602, "Moses" 5339502 and "Mr Mburu-Coop" 3020809.
- Each bus's till sits under one of them.
- Until now a SACCO admin could see only one connection: the existing "M-Pesa connection" sheet (`mpesa/settings`). That sheet still works and edits the SACCO's **default** connection.

A bus's connection is what both of these use:
- **Registering its till** with Safaricom (`mpesa/tills/{id}/register`).
- **In-app payments** (STK push). A connection with no passkey can register tills but **cannot** take in-app payments; `can_take_app_payments` says which.

**Secrets are write-only.** No response ever contains a consumer key, consumer secret or passkey. A response says only whether each one is set. A field sent blank on edit keeps its stored value.

The legacy payments page sent every key to the browser in hidden fields. The new page must not do that, and the API makes it impossible.

## Permissions

| Action | Permission |
|---|---|
| Read (`GET`) | `View Payment Settings` |
| Write (`POST`, `PATCH`, `PUT`, `DELETE`) | `Add Payment Settings` or `Edit Payment Settings` |

- A SACCO user only ever sees their own SACCO's connections. Another SACCO's connection returns `404`.
- A super admin passes `?sacco_id=` to `GET mpesa/connections`.

## Endpoints

### `GET mpesa/connections`: the list

```json
{
  "connections": [
    {
      "id": 31,
      "name": "Mr Mburu-Coop",
      "business_short_code": "3020809",
      "paybill": null,
      "payment_mode": "buygoods",
      "environment": "live",
      "enabled": true,
      "is_default": false,
      "credentials": { "consumer_key_set": true, "consumer_secret_set": true, "pass_key_set": true },
      "can_register_tills": true,
      "can_take_app_payments": true,
      "vehicles_count": 1,
      "registered_tills_count": 1,
      "payments_7d": 6421,
      "last_payment_at": "2026-10-08T16:20:11+03:00",
      "created_at": "2026-09-24T10:00:00+00:00",
      "updated_at": "2026-10-08T12:33:01+00:00"
    }
  ],
  "count": 26
}
```

- **Order:** the default first, then by name.
- **`payment_mode`:** `buygoods` or `paybill`.
- **`environment`:** `live` or `sandbox`.
- **`vehicles_count`:** buses linked to this connection.
- **`registered_tills_count`:** how many of those were registered from Komiut.
- **`payments_7d`, `last_payment_at`:** confirmations Safaricom delivered through this connection.
- **`name`:** may be `null` on connections imported from the old system. Show the short code instead and invite the admin to name it.

### `GET mpesa/connections/{id}`: one connection and its buses

```json
{
  "connection": { "...": "same shape as a list row" },
  "vehicles": [
    {
      "id": 883,
      "plate": "KDY 599G",
      "till_number": "1747747",
      "merchant_short_code": "1277149",
      "till_registered_at": "2026-10-08T12:33:01+00:00",
      "till_registered_url": "https://api.komiut.com/api/confirmation/31",
      "status": true
    }
  ]
}
```

### `POST mpesa/connections`: add one (returns `201`)

| Field | | |
|---|---|---|
| `name` | required | How the SACCO knows the account, e.g. "Mr Mburu-Coop". |
| `business_short_code` | required | 4–10 digits: the head-office short code the Daraja app is live for. |
| `payment_mode` | required | `buygoods` or `paybill`. |
| `consumer_key` | required | Write-only. |
| `consumer_secret` | required | Write-only. |
| `pass_key` | optional | Write-only. Also accepted as `api_key`, the label the current form uses. Needed only for in-app payments. |
| `paybill` | optional | Digits. |
| `environment` | optional | `live` (default) or `sandbox`. |
| `enabled` | optional | Default `true`. |
| `make_default` | optional | Make this the SACCO's default. |

Responses:
- `409` when the short code is already connected. The message tells the admin where:
  - *"Short code 3020809 is already connected for this SACCO (connection #31)."*
  - *"…already connected on Komiut under another account. Contact Komiut support."*
- `400` with `errors` on validation failure.

### `PATCH mpesa/connections/{id}`: edit

- Any field from `POST` may be sent; omitted fields stay as they are.
- A blank or omitted secret keeps its stored value.
- `make_default: true` makes this connection the default and un-defaults the previous one.
- `make_default: false` on the current default returns `422`. Make another connection the default instead, because a SACCO always has one.

### `DELETE mpesa/connections/{id}`: remove (only if unused)

Returns `409` with the reason when:
- it is the default;
- buses are linked to it;
- payments arrived through it in the last 90 days.

Otherwise it returns `200`. For a connection that's still in use, offer **Disable** instead: `PATCH {"enabled": false}`.

### `PUT mpesa/tills/{vehicle}/connection`: link a bus to a connection

```json
{ "connection_id": 31 }
```

Send `null` to unlink, so the bus falls back to the SACCO's default.

```json
{
  "success": "Bus linked to Mr Mburu-Coop.",
  "vehicle": { "id": 883, "plate": "KDY 599G", "connection_id": 31 },
  "registration_needed": false,
  "can_take_app_payments": true
}
```

- **`registration_needed`:** `true` when the till was registered under a different connection. Prompt for **Register till** again, because Safaricom keeps delivering to the old one until re-registered.
- **`can_take_app_payments`:** `false` means warn that in-app payments for this bus won't work until a passkey is added.
- **Errors:** a connection from another SACCO returns `422`; a vehicle from another SACCO returns `404`.

### `vehicles/add` (existing): now takes `mpesa_connection_id`

The **Add vehicle** form can link the bus in the same step: add a **"M-Pesa connection"** select next to *Till number* and *Merchant short code*, filled from `GET mpesa/connections`.
- Users without payment-settings permission: on create the field is ignored (the bus is still created); on edit, changing it returns `403`.
- A connection from another SACCO returns `422` with `errors.mpesa_connection_id`.

### `GET mpesa/tills` (existing): each row now also has

```json
"connection":        { "id": 1,  "name": null,            "business_short_code": "7071220" },
"connection_source": "sacco_default",
"receiving_via":     { "id": 31, "name": "Mr Mburu-Coop", "business_short_code": "3020809", "last_payment_at": "2026-10-08T16:20:11+03:00" }
```

- **`connection`:** what the bus registers and takes app payments with.
- **`connection_source`:**
  - `bus`: linked explicitly;
  - `sacco_default`: not linked, so it falls back;
  - `null`: the SACCO has no default.
- **`receiving_via`:** the connection its payments actually arrived through in the last 30 days. `null` if none arrived.

When `receiving_via.id` differs from `connection.id`, show a hint such as *"Payments arrive via Mr Mburu-Coop: link this bus to it"*, with a one-click link.

## The page

1. **Connections list.** One card or row per connection:
   - name (or the short code), short code, Live/Sandbox badge, Default badge, Enabled toggle;
   - three "Configured ✓ / Missing" chips for the credentials;
   - buses linked, payments in 7 days, last payment;
   - **Add connection** at the top.
   - Clicking a row opens a sheet like the current M-Pesa connection sheet: name, short code, paybill, mode, environment, and three credential fields with *"Leave blank to keep current value"*. It also lists the buses linked to it.
2. **Vehicle tills table.** Already built. Add a **Connection** column with a select (`PUT …/connection`) and the `receiving_via` hint.

## How a SACCO onboards a new till

1. Look up the till in the M-Pesa Org Portal: its **till number**, **store number** ("Organization Short Code") and the **head office** it's under.
2. If that head office isn't in the connections list, use **Add connection**, with its Daraja keys from developer.safaricom.co.ke → My Apps.
3. **Add vehicle** (or edit it): till number, merchant short code = the store number, and the connection = that head office.
4. **Register till** on the M-Pesa page.
5. Pay **KES 10** to the till. Within seconds it shows on the bus, and `receiving_via` shows the connection.
