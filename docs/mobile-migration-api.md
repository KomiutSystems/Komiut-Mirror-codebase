# Komiut v2 — Mobile Migration API Reference

The single contract for moving the Flutter app off the C# gateway onto the Laravel
backend. Everything here is grounded in the actual backend code, not aspirational.

**Companion docs:** [flutter-auth-migration.md](flutter-auth-migration.md) (auth deep-dive),
[notifications-api.md](notifications-api.md) (notification REST contract).
**Live, always-current endpoint docs (Scribe):** https://api.komiut.com/docs

---

## 0. The essentials (read first)

| Thing | Value |
|---|---|
| **REST base** | `https://api.komiut.com/api/v1/auth` (legacy alias `/api/auth` also works) |
| **WebSocket host** | `komiut.co.ke` — connect `wss://komiut.co.ke/app/<REVERB_APP_KEY>` |
| **Broadcast auth endpoint** | `https://api.komiut.com/broadcasting/auth` (guarded by `auth:sanctum`) |
| **Brand header (every REST call)** | `X-App-Key: komiut` or `X-App-Key: safiri` |
| **Auth header** | `Authorization: Bearer <access_token>` (Sanctum token, e.g. `1|abc…` — **not a JWT**, don't decode it) |
| **Content / accept** | `Content-Type: application/json`, `Accept: application/json` |

**Conventions & gotchas:**
- Bodies are **snake_case and flat** (no `{value}` wrappers, no `_domain` header).
- **Missing/unknown `X-App-Key` → 404** (not 401). A "route not found" on a path you can see almost always means the header is missing.
- Missing Bearer on a JSON request → 401. Missing Bearer **without** `Accept: application/json` → 500 (Laravel tries to HTML-redirect to a login page that doesn't exist). **Always send `Accept: application/json`.**
- Many validators return **`{"errors": {...}}` with HTTP 400** (not Laravel's default 422). A few return 401/422 — noted per endpoint.

---

## 1. Realtime eventing (Reverb / Pusher protocol) ⭐

The backend broadcasts over **Laravel Reverb**, which speaks the **Pusher protocol** — so on
Flutter use `laravel_echo` + `pusher_channels_flutter` (or `pusher_client`). The app's old
**SignalR** client is dead against this backend and must be replaced.

### 1.1 Client connection settings

| Setting | Value | Notes |
|---|---|---|
| key | `<REVERB_APP_KEY>` | ask ops for the prod value (it's an SSM secret) |
| wsHost / wssHost | `komiut.co.ke` | **not** `api.komiut.com` |
| wsPort / wssPort | `443` | |
| forceTLS / encrypted | `true` | scheme is `wss` |
| cluster | *(none)* | Reverb ignores it; pass any placeholder the SDK requires |
| authEndpoint | `https://api.komiut.com/broadcasting/auth` | POST with the Bearer token |

### 1.2 Flutter Echo setup (copy-paste starting point)

```dart
final echo = Echo(
  broadcaster: EchoBroadcasterType.Pusher,
  client: PusherClient(
    REVERB_APP_KEY,
    PusherOptions(
      host: 'komiut.co.ke',
      wsPort: 443, wssPort: 443,
      encrypted: true,
      cluster: 'mt1', // placeholder, ignored by Reverb
      auth: PusherAuth(
        'https://api.komiut.com/broadcasting/auth',
        headers: {
          'Authorization': 'Bearer $accessToken',
          'X-App-Key': 'komiut',
          'Accept': 'application/json',
        },
      ),
    ),
    autoConnect: false,
  ),
);
```

### 1.3 Channels

Private channels — the client subscribes with the `private-` prefix; `laravel_echo`'s
`echo.private('...')` adds it for you.

| Channel | Who may subscribe | Use it for |
|---|---|---|
| `App.Models.User.{id}` | only the user whose id matches | that user's notifications |
| `trip.{queueId}` | the passenger who booked that queue **or** the crew driving its vehicle | live vehicle position during a trip |

### 1.4 Events to bind

| Event name (bind exactly this) | Channel | Payload |
|---|---|---|
| `vehicle.moved` | `trip.{queueId}` | `{ vehicle_id, queue_id, plate, latitude, longitude, heading, recorded_at }` |
| `.Illuminate\Notifications\Events\BroadcastNotificationCreated` *(leading dot required)* | `App.Models.User.{id}` | `{ type, title, message, referenceId, organizationId }` (camelCase — same shape as the REST notification list item) |

```dart
// live vehicle marker during a trip
echo.private('trip.$queueId').listen('vehicle.moved', (e) {
  moveMarker(e['latitude'], e['longitude'], e['heading']);
});

// in-app notifications (note the leading dot on the event name)
echo.private('App.Models.User.$userId')
    .listen('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', (e) {
  pushIntoNotificationList(e); // {type,title,message,referenceId,organizationId}
});
```

### 1.5 What does **not** broadcast (so don't wait on an event)

- **`BookingPaid` is not broadcast** — it's an in-process domain event only. To learn a
  payment succeeded, **poll the STK status endpoint** (§4.3). The passenger also gets a
  notification (`Booking confirmed`) over the user channel once paid.
- **No live seat map.** There is no `SeatsChanged` event and no `queue.{id}` channel yet.
  For the seat picker, **poll `book_a_ride/seats` every ~3–5s** while it's open (§3.1). The
  10-min unpaid hold + authoritative confirm-side check mean this is safe.

---

## 2. Auth (full mapping in [flutter-auth-migration.md](flutter-auth-migration.md))

| Purpose | Endpoint |
|---|---|
| Login | `POST /login` — `{ email, password }` |
| Register | `POST /register` — `{ firstname, lastname, email, phone, password, password_confirmation }` |
| **Google sign-in** | `POST /social/google` — `{ id_token }` |
| Who am I | `POST /user` |
| Update profile | `POST /profile/update` — any of `{ firstname, lastname, phone }` |
| Change password | `POST /profile/change_password` |
| Refresh | `POST /refresh` |

**Token/response shape** (login + Google both return this):
```json
{ "access_token": "1|xxxx", "token_type": "bearer",
  "user": { "id": 42, "firstname": "Jane", "lastname": "Wanjiru",
            "email": "…", "phone": "…", "type": "passenger", "last_active_at": "…" },
  "permissions": [], "sacco": null, "vehicle_users": [], "termini": [] }
```
- Read the token from **`access_token`** (not `accessToken`/`token`).
- Build display name from `firstname` + `lastname` (there is no `fullName`).
- `user.type` ∈ `passenger | driver | admin | superadmin`.

**Google sign-in:** configure `google_sign_in` with the **Web** client id as `serverClientId`
(komiut: `1028247623841-7tshoiv54l29aa2rtva1qlglqs5f0vvv…`, 2Safiri:
`750939898589-d9ktqpvuc44f66vco11if4lute37nrae…`), send the resulting `idToken` as
`{ "id_token": … }`. One button — backend logs in or creates the passenger automatically.
Google passengers arrive with **email but no phone**; they can browse but **can't pay** until
they add a phone via `profile/update` (it's the number M-Pesa charges).

---

## 3. Booking flow (passenger)

### 3.1 Seat availability — `GET book_a_ride/seats`  *(Bearer)*
Query: `bus_id` (vehicle, req), `id` (queue, req), `from_id`, `to_id` (segment, optional),
`booking_id` (optional, exclude own held seats when amending).
```json
{ "seats": { /* Vehicle + seat.seat_arrangements layout */ },
  "booked": [ { "seatId": 4 } ] }   // disable these in the UI
```
Poll this every ~3–5s while the picker is open (no realtime seat event yet).

### 3.2 Create booking — `POST book_a_ride/booking/add`  *(Bearer)*
```json
// request
{ "id": 7, "seats": "[3,4]", "name": "Jane Doe", "phone": "0712345678",
  "fromId": 12, "toId": 18, "payment_method": "mpesa" }
// 200
{ "success": "Booking successful!", "booking_id": 41, "amount": 120 }
```
- `seats` = comma-separated seat-arrangement ids (brackets tolerated).
- **Fare is server-authoritative** — any client amount is ignored; use the returned `amount`.
- Booking starts **unpaid**; `booking_id` feeds the STK push.
- `payment_method` ∈ `mpesa | ncba_till | coop_till | wallet | loyalty_points`.

### 3.3 Pick a trip (lists)
- `GET book_a_ride/queues?from_id&to_id&sacco&search&page` → `{ "queues": [...] }`
- `GET book_a_ride/routes?from_place_id&to_place_id&page` → `{ "routes": [...] }`
- `GET book_a_ride/nearby?latitude&longitude&radius&route_id` → `{ "vehicles": [...] }` (live map)

---

## 4. Payments (M-Pesa STK)

### 4.1 Initiate STK push — `POST mpesa/stk`  *(X-App-Key only — NO Bearer)*
```json
// request  (amount is ignored; server charges booking.amount)
{ "phone": "0712345678", "booking_id": 41 }
// 200 — raw Daraja response; PERSIST CheckoutRequestID
{ "MerchantRequestID": "29115-34620561-1",
  "CheckoutRequestID": "ws_CO_191220191020363925",
  "ResponseCode": "0", "ResponseDescription": "Success…", "CustomerMessage": "…" }
```
`422 {"error":"This booking has no fare to charge."}` if the fare is 0.

### 4.2 Poll status — `GET mpesa/stk/status/{checkout}`  *(Bearer, own payment only)*
`{checkout}` = the `CheckoutRequestID`. Reads local state only (never calls Safaricom).
```json
{ "status": "completed", "resultCode": 0, "mpesaReceiptNumber": "SFT12ABC34" }
```
**State machine** — poll every ~3–5s until terminal:

| status | resultCode | receipt | meaning |
|---|---|---|---|
| `processing` | `null` | `null` | in flight — keep polling |
| `completed` | `0` | string | **paid** (definitive) — show receipt |
| `failed` | `1` | `null` | callback came back unpaid — let user retry |
| `cancelled` | `1` | `null` | user/you cancelled it |
| `not_found` | `null` | `null` | no record or not yours — treat as expired/error |

A lost callback is auto-recovered by a backend reconciler, so keep polling `processing`; it will flip to a terminal state.

### 4.3 Cancel — `POST mpesa/stk/cancel/{checkout}`  *(Bearer, own payment only)*
No body. `200 {"status":"cancelled"}` · `404` unknown · `409 {"error":"This payment is already settled."}` (paid always wins).

**Recommended flow:** pick trip (3.3) → seats (3.1) → add booking (3.2) → `mpesa/stk` (4.1),
store `CheckoutRequestID` → poll status (4.2) → cancel if user backs out (4.3).

---

## 5. Driver flow

All driver endpoints need `X-App-Key` + `Bearer`, except login. Join/exit/start require the
`Edit Queues` permission (the driver role has it).

### 5.1 Driver login — `POST driver/login`  *(no Bearer)*
```json
// request — no password, no OTP
{ "phone": "0712345678", "plate": "KDQ 446R" }
// 200
{ "user": { /* full driver */ }, "vehicle": { /* full vehicle */ },
  "access_token": "1|…", "token_type": "bearer",
  "expires_at": "2026-08-02T23:59:59Z" }   // end-of-day if SACCO rotates drivers, else null
```
Plate is normalized (case/space-insensitive). **Login *is* the vehicle assignment** — it opens
the driver's `VehicleUser` for that vehicle and, as the morning handover, **releases the
vehicle's previous driver and cancels any queue they left open**, so a plate always maps to
exactly one current driver and the incoming driver starts a clean shift (never inherits the
previous driver's trip). Read `vehicle` for the assignment, `access_token` for the token.

### 5.2 Join queue — `POST queues/join`
```json
// request — ONLY these two; vehicle + fare derived server-side
{ "terminus_id": 3, "route_id": 5 }
// 201 (new) or 200 (idempotent re-join)
{ "queue": { "id": 12, "queue_number": "QN-1", "queue_status_id": 1,
             "amount": 120, "route_id": 5, "vehicle": {…}, "route": {…}, … } }
```
- Send **`terminus_id` + `route_id`** (both required). Vehicle comes from the token's active
  assignment; **fare is server-authoritative** (SACCO's route price) — no `amount` accepted.
- `terminus_id` must be the route's origin, else 422. Same vehicle already queued on another
  route → 409.

### 5.3 Start trip — `POST trips/start`
**No body.** Transitions the driver's current queue Pending → **Active**, stamps `start_time`.
Idempotent. Returns `{ "queue": {…} }` (same shape as join, now Active).

### 5.4 Exit queue — `POST queues/exit`
**No body.** Cancels the driver's current Pending/Active queue. `200 {"success":"Left the queue."}`.

### 5.5 In-trip
- **Manifest** — `GET book_a_ride/manifest/{queueId}` → `{ queue_id, bookings:[{id,name,phone,passengers,paid,boarded,seats:[…],pickup,dropoff}], pickups:[…], dropoffs:[…] }`. Gated to the vehicle's crew/owner.
- **Board one passenger** — `GET bookings/passenger/pick/{bookingId}` (mutates; sets boarded) → `{"success":"Passenger Picked Successfully!"}`.
- **Board a batch** — `POST bookings/passengers/pick` `{ queueId, pickupId }`; auto-completes the queue when the pickup equals the route destination.

---

## 6. Notifications (full contract in [notifications-api.md](notifications-api.md))

| Purpose | Endpoint |
|---|---|
| List | `GET notifications?page&per_page&unread_only&type` → `{message:{items,count,unreadCount,…}}` |
| Unread count | `GET notifications/unread-count` → `{message:{count}}` |
| Mark one / all read | `POST notifications/{id}/read` · `POST notifications/read-all` |
| Register / unregister device | `POST notifications/devices` `{token,platform}` · `DELETE notifications/devices/{token}` |

Delivery is three-way: **in-app** (these endpoints) + **push** (FCM, register the device token
on sign-in) + **realtime** (the user-channel broadcast in §1.4). Notification item shape is
camelCase `{ id, title, message, type, referenceId, organizationId, isRead, createdAt }` —
identical to the realtime payload, so one model handles both.

---

## 7. Suggested wiring order

1. Base URL → `api.komiut.com/api/v1/auth`, add `X-App-Key`, drop `_domain`. Make login/register/who-am-i work (§2).
2. Add Google sign-in (§2).
3. **Stand up eventing** (§1): Echo/Pusher client, `/broadcasting/auth`, subscribe the user channel for notifications + `trip.{queueId}` for live vehicle. Rip out SignalR.
4. Booking + payment against Frankfurt (§3, §4) — including the STK poll loop.
5. Driver flow (§5).
6. Full regression, both brands.
