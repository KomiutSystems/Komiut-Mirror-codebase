# Notifications API — mobile contract

The Laravel notification system, in the shape the komiut-v2 app already reads.
Migrating the app is a **path change only** — the JSON shapes below match the
existing `NotificationModel.fromJson`, so its model doesn't change.

Base: `https://api.komiut.com/api/v1/auth/...` · every call needs
`Authorization: Bearer <token>` and `X-App-Key: komiut|safiri`.

## Endpoints

| Purpose | Method | Path |
|---|---|---|
| List | GET | `notifications?page=1&per_page=20&unread_only=false&type=trip` |
| Unread count | GET | `notifications/unread-count` |
| Mark one read | POST | `notifications/{id}/read` |
| Mark all read | POST | `notifications/read-all` |
| Register device | POST | `notifications/devices` — body `{ "token": "...", "platform": "ANDROID|IOS|WEB" }` |
| Unregister device | DELETE | `notifications/devices/{token}` |

Old C#/gateway paths (`/api/fleet/Notifications`, `/api/fleet/Devices`) map 1:1
to these; query params were PascalCase (`PageNumber`, `UnreadOnly`) and are now
snake_case (`page`, `unread_only`).

## Shapes (camelCase, `{message:{...}}` envelope — unchanged from the app model)

**List** →
```json
{ "message": {
  "items": [
    { "id": "uuid", "title": "Booking confirmed", "message": "Your booking is confirmed and paid.",
      "type": "trip", "referenceId": "42", "organizationId": null,
      "isRead": false, "createdAt": "2026-07-31T10:00:00+00:00" }
  ],
  "count": 1, "unreadCount": 1, "pageNumber": 1, "pageSize": 20,
  "totalCount": 1, "totalPages": 1, "hasNextPage": false } }
```
**Unread count** → `{ "message": { "count": 3 } }`
**Mark read / read-all / device register/unregister** → `2xx` (`{ "success": true }`).

## Types the backend emits
`trip`, `payment`, `assignment`, `promo`, `system`. Deep-link rule (already in
the app): `type == "trip"` with a non-empty `referenceId` opens
`/passenger/ticket/{referenceId}`; everything else opens the list.

## Push (FCM)
Each push carries a `notification` block (title/body → OS banner) and
`data: { type, referenceId }` — the app deep-links off those. Register the FCM
token via `POST notifications/devices` on sign-in; the backend fans a push out
to every registered device.

## Realtime — the one thing that needs app-side work
The backend broadcasts each notification over **Reverb (Pusher protocol)** on
the private channel `App.Models.User.{id}`, event
`Illuminate\Notifications\Events\BroadcastNotificationCreated`, payload = the
same camelCase object as the list item. The app currently listens over
**SignalR** (`/hubs/notifications`, `OnNotificationReceived`) — that path is
**dead against this backend**. Until the app swaps to a Pusher/Echo client, live
in-app updates come from FCM pushes + the unread-count poll; the REST list is
always correct on refresh.

## Realtime — balances (the Activity screen)

Points and carbon credits also move over the socket, on the **same private
channel** the app already opens for notifications: `App.Models.User.{id}`.
Different event, so bind it separately.

- **Event name to bind:** `balance.changed` (a broadcast *event*, not a
  notification — so nothing overwrites the payload the way
  `BroadcastNotificationCreated` overwrites `type`).
- **Payload** (camelCase, exactly these keys):

```jsonc
{
  "scheme":        "loyalty" | "carbon", // which balance moved
  "saccoId":       12,                   // loyalty only — WHICH card; null for carbon
  "balance":       14.5,                 // the balance AFTER the move
  "delta":         2.0,                  // signed: + earned/refunded, − spent
  "reason":        "earned",             // earned | redeemed | reversed | refunded | adjusted
  "progressCents": 45000,                // carbon only — toward the next credit; null for loyalty
  "at":            "2026-09-08T09:14:22+03:00"
}
```

- `loyalty` balances are **decimal** and per-SACCO (a 40-bob ride is 0.4 points);
  `carbon` is **whole credits** and platform-wide, one balance across every SACCO
  and brand — read both as `num`.
- **`delta` can be 0 on `carbon`, and that is not a bug.** A credit is 1,000 KSh
  of travel and a matatu fare is 30–150, so most paid rides mint nothing and only
  move `progressCents` — which is what redraws "X KSh to your next credit".
- **No push, no badge, no stored row.** This is a quiet "your number moved,
  refetch" signal. Anything genuinely worth a notification (a carbon milestone, a
  reward shipping) still comes through the notification channel above.

**THIS IS AN ACCELERANT, NOT A SOURCE OF TRUTH — keep the fetch.** Passengers are
on matatus and lose signal constantly. Fetch balances on screen open and on
pull-to-refresh, and treat this event as an optimisation on top: patch the number
now, let the next fetch be right. Nothing here is ever the only way the passenger
learns their balance changed.

Fires on: a fare earning points or credits (booking STK **and** QR), a free ride
redeemed with points, a carbon reward claimed or cancelled, and a hand-granted
credit adjustment. Deliberately **not** on a reward being marked delivered — the
credits left the balance when it was claimed.

## What fires today (catalog)
- **Booking confirmed** (paid) → passenger, `type=trip`, ref=bookingId — in-app + push + realtime.
- **New booking** → the assigned driver, `type=assignment`, ref=bookingId.

Adding more (trip started, cancelled, reservation expiring, loyalty earned, etc.)
is a one-line `NotificationService::dispatch(...)` at the relevant event — the
plumbing is done.

## Operator notes
- **Email channel** exists (`mail`) but **SES is still in sandbox** — email only
  reaches verified recipients until production access is granted.
- **Push is per-brand.** Only komiut's Firebase project/credentials are
  configured; **2Safiri push needs their own Firebase project + service-account
  file** (`SAFIRI_FCM_PROJECT_ID` / `SAFIRI_FCM_CREDENTIALS`). In-app + realtime
  work for both brands regardless.
