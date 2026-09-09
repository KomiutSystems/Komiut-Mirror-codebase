# Loyalty points — the mobile contract

Captured from the running backend, not the Scribe `/docs`. **Ignore the flat
`{points, redeemed_points}` shape in the live `/docs` — it is stale** (it pre-dates
the per-SACCO rewrite).

Base: `https://api.komiut.com/api/v1/auth` · `Authorization: Bearer` + `X-App-Key`.

---

## The model, in two sentences

**Points are per-SACCO.** One balance per `(passenger, SACCO)`. Each SACCO sets its
own `divisor` (KES of fare per point earned) and `redemption_threshold` (points for
a free ride). So the per-SACCO card UI is correct — keep it.

**Redeeming targets a BOOKING, not a SACCO.** You never send a `sacco_id`. You send
a `booking_id`, and the backend derives the SACCO from
`booking → queue → vehicle → sacco`, then spends *that* SACCO's threshold from
*that* SACCO's balance.

---

## Paying with points is TWO calls, and there is a clock

Redeem does not create anything. It settles a reservation that already exists:

```
1. POST book_a_ride/booking/add     -> a booking, paid = false
2. POST book_a_ride/loyalty/redeem  -> that booking, paid = true
```

**Between them you have `booking.hold_minutes` — 10 minutes — and then the
reservation is cancelled and its seats go back on sale.** A sweep
(`app:check-passenger-payments`) runs every two minutes and takes anything unpaid
past the hold.

Until 2026-09-09 that sweep used a hardcoded **two** minutes while the config said
ten, and both bookings that had ever existed in production died three minutes after
creation. It reads the config now, so ten minutes is real — but it is still a
clock, and the passenger is reading a payment sheet inside it. Do not park the user
on a confirmation screen indefinitely.

If you miss it, redeem returns `422 "This reservation has expired. Please book
again."` and **no points are spent**.

---

## `POST book_a_ride/loyalty/redeem`

```jsonc
// request
{ "booking_id": 41 }

// 200
{ "success": "Free ride redeemed!", "booking_id": 41, "points_spent": 500 }
```

Sets `paid = true` and `payment_method = "loyalty_points"` on the booking.

### Every response you can get

| status | body | meaning |
|---|---|---|
| `200` | `{"success","booking_id","points_spent"}` | settled — **or already settled by points, see retries** |
| `400` | `{"errors":{"booking_id":["..."]}}` | missing or unknown `booking_id`. **Different shape** — see below |
| `401` | `{"message":"Unauthenticated."}` | no/expired token |
| `403` | `{"error":"This booking is not yours."}` | someone else's booking |
| `422` | `{"error":"This reservation has expired. Please book again."}` | the sweep already cancelled it; **no points spent** |
| `422` | `{"error":"This booking is already paid."}` | settled by another rail (M-Pesa, cash, QR); **points kept** |
| `422` | `{"error":"This SACCO has no active loyalty program."}` | |
| `422` | `{"error":"Point redemption is not available for this SACCO."}` | threshold is 0 |
| `422` | `{"error":"You do not have enough points for a free ride."}` | |

**The `400` is the odd one out.** Every other error is `{"error": "<sentence>"}`;
validation failures are `{"errors": {"booking_id": [...]}}`. Read
`errors.booking_id[0] ?? error ?? message` and you will always get something.

**Do not branch on the error sentence.** They are English strings with no stable
code. Branch on the status, and re-fetch `summary` to redraw the card.

### Retries are safe — and now report the truth

A retry of a redeem that already succeeded returns **`200`** with the amount
actually spent, read back from the ledger. It does not debit twice.

This changed on 2026-09-09. It used to return `422 "This booking is already paid."`
— the identical string a genuine conflict returns — so a lost response looked like
a failure for something that had worked. On a handset on a moving matatu, the
request whose response is lost is the *common* case, so retry freely.

The distinction the backend now makes: **paid by points → 200 (your redemption);
paid by anything else → 422 (a real conflict, points untouched).**

---

## `GET book_a_ride/loyalty/summary`

Per-SACCO cards, redeemable first, then closest to a reward.

```jsonc
{ "loyalty": [
  { "sacco_id": 4, "sacco": "NICCO MOVERS LIMITED", "balance": 50,
    "redemption_threshold": 5, "points_to_reward": 0,
    "eligible_to_redeem": true, "is_active": true }
] }
```

- `points_to_reward` = `max(0, threshold − balance)` → the progress bar.
- `eligible_to_redeem` = active program **and** threshold > 0 **and** balance ≥ threshold.
- A card appears for every active program, **balance 0 included** — that is
  deliberate, so a passenger can see a scheme exists before they have earned in it.
- Parse `balance`, `points_to_reward` and `points_spent` as **decimals, not ints**.
  A 150 KES fare at divisor 100 earns `1.5`.

**`eligible_to_redeem` is a snapshot.** It can be stale by the time you POST — the
threshold can change, or the points can be spent on another handset. Treat the
redeem response as the authority, not the card.

---

## `GET book_a_ride/loyalty/history?sacco_id=&page=`

Standard Laravel paginator. Each row:

```jsonc
{ "id": 1, "sacco_id": 4, "value": -5, "type": "redeemed",
  "booking_id": 41, "created_at": "2026-09-09T06:23:22Z",
  "sacco": { "id": 4, "name": "NICCO MOVERS LIMITED" } }
```

`type` is `earned` (value > 0) or `redeemed` (value < 0). `?sacco_id` filters.

For the **Activity screen** prefer `GET /api/auth/book_a_ride/activity`, which
merges loyalty and carbon credits into one chronological stream — see
`docs/notifications-api.md`. A redemption appears there as
`scheme: "loyalty"`, `type: "redeemed"`, negative `value`.

---

## Earning — no endpoint, and one rule that surprises people

Points are credited server-side. The app calls nothing; refresh `summary` after a
trip, or listen for the socket event below.

| how the fare was paid | earns points? |
|---|---|
| booking paid in-app (STK) | **yes** — `fare ÷ divisor` |
| QR scan on the bus | **yes** |
| **direct till payment** (passenger pays the till themselves) | **no** |
| a free ride bought with points | **no** — never earns back |

The direct-till exclusion is a deliberate product decision, not an oversight: it
exists to push passengers into the app. If a passenger insists they paid and got no
points, that is almost certainly why.

---

## Realtime

After a redemption the backend fires `balance.changed` on the passenger's private
channel `App.Models.User.{id}`:

```jsonc
{ "scheme": "loyalty", "saccoId": 4, "balance": 45, "delta": -5,
  "reason": "redeemed", "at": "2026-09-09T17:04:11+03:00" }
```

**Treat it as an accelerant, not a source of truth.** It is fired after the
transaction commits, so a socket failure can never roll back a settled ride — which
also means a lost event leaves the balance correct on the server and stale on the
handset. Always re-fetch on screen open and pull-to-refresh.

A replayed redeem fires **nothing**, because no balance moved.

---

## Three things to know before you build

**Only NICCO can complete this flow today.** Five SACCOs run an active loyalty
program, but four of them own no routes — so no queue, so no booking, so nothing to
redeem against. Their cards will still show `eligible_to_redeem: true` once a
passenger reaches the threshold. Consider not offering "free ride" for a SACCO the
passenger cannot actually book.

**There is no refund path.** `Reversed` and `Refunded` exist as ledger types and are
never written — the backend has exactly two writes, `Earned` and `Redeemed`. If a
points-paid booking is cancelled, the points are gone. Do not build a cancel button
that implies otherwise until that lands.

**Reserving with `payment_method: "loyalty_points"` does nothing on its own.** It
stamps a column; it does not pay. Only `paid === true` means paid. Call redeem.
