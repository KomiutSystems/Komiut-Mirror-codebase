# Loyalty points — the mobile contract

Captured from the running backend, not the Scribe `/docs`. **Ignore the flat
`{points, redeemed_points}` shape in the live `/docs` — it is stale** (it pre-dates
the per-SACCO rewrite).

Base: `https://api.komiut.com/api/v1/auth` · `Authorization: Bearer` + `X-App-Key`.

---

## The model, in three sentences

**Points are per-SACCO, and a point is worth shillings.** One balance per
`(passenger, SACCO)`. Each SACCO sets its own `divisor` (KES of fare per point
*earned*), its own **`point_value`** (KES of fare one point *pays for*), and a
`redemption_threshold` (the points a standard ride takes — the goal on the card).
So the per-SACCO card UI is correct — keep it.

**A ride costs what it costs.** Changed 2026-09-12. A points payment is priced
off the fare: a booking costs `(fare × seats) / point_value` points; a scanned
ride costs `fare / point_value`, where *you* send the fare the conductor asked
for. It used to be the flat threshold whatever the distance or seat count, which
meant "enough for one ride" was enough for any ride, ten times over. `points_spent`
is therefore **variable** — never hard-code it, never infer it from the threshold.

**Points are a payment method with two rails, and you never send a `sacco_id`.**
Either you settle a BOOKING (`loyalty/redeem`, SACCO derived from
`booking → queue → vehicle → sacco`) or you pay a BUS by scanning it
(`qrcode/redeem_points`, SACCO taken straight off the vehicle). Both spend from
*that* SACCO's balance at *that* SACCO's point value.

**A booking's `amount` is the whole fare.** Also 2026-09-12. `bookings.amount`
is per-seat fare × seats (the `booking/add` response now also carries
`fare_per_seat` and `passengers`). It used to be one seat's fare while
`passengers` counted the seats, so a four-seat booking would have been charged
one fare by M-Pesa and one flat ride by points. Both rails now price off the
same number. **A booking holds at most 5 seats** — the booker and four others;
six is `422 "You can book up to 5 seats at a time: yourself and 4 others."`.

**Not boarded means refunded.** A passenger the crew marks `no_show` gets back
what they paid — as points. That changed on 2026-09-11; see *Refunds* below.

---

## Rail 1 — a booking. Two calls, and there is a clock

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

This rail needs the bus to be in a queue (`bookings.queue_id` is NOT NULL). A bus
that has already left the stage cannot be booked, so it cannot be paid this way —
scan it instead (Rail 2).

---

## `POST book_a_ride/loyalty/redeem`

```jsonc
// request
{ "booking_id": 41 }

// 200
{ "success": "Ride paid with points.", "booking_id": 41, "points_spent": 50 }
```

Sets `paid = true` and `payment_method = "loyalty_points"` on the booking.
`points_spent` is `booking.amount / point_value`, to two decimals — KES 150 at
KES 3 a point is 50; KES 600 (four seats) is 200. Show the passenger the price
before they tap: `booking.amount / card.point_value`.

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
| `422` | `{"error":"Point redemption is not set up for this SACCO yet."}` | the SACCO has not set a `point_value`; the card shows `point_value: null` — do not offer the button |
| `422` | `{"error":"This booking has no fare to pay with points."}` | `amount` is 0 |
| `422` | `{"error":"This ride costs 100 points and you have 50.","points_needed":100}` | **the one refusal with a number in it**: `points_needed` is what this booking costs. Offer M-Pesa |

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

## Rail 2 — pay by scanning the bus. No queue, no booking, no clock

Shipped 2026-09-10. The passenger scans the printed QR (or types the till), the
app shows the bus and a *pay with points* button, one tap pays. Nothing is
reserved, nothing expires, and the bus does not have to be in a queue — it can be
on the road.

```
1. POST qrcode/vehicle        {till_number}                  -> vehicle + the loyalty card for its SACCO
2. POST qrcode/redeem_points  {vehicle_id, amount, seat_id?} -> paid
```

`amount` is **required** since 2026-09-12: the fare in KES, as the conductor asked
for it — the same field, same meaning, as `qrcode/stk/push`. A scan identifies a
bus, not a journey, so there is nothing server-side to price from. The points cost
is `amount / point_value`; show it before the tap.

Until 2026-09-10 `qrcode/redeem_points` was **dead**: it read a legacy `points`
table that has been empty since the per-SACCO rewrite and told every passenger
"You do not have enough points to proceed!" whatever their card said. `qrcode/vehicle`
read the same dead table, so the scan screen said "no points" while the card said 50.
Both now read the real balance. If you had hidden the button because it never
worked, un-hide it.

### `POST qrcode/vehicle`

```jsonc
// request
{ "till_number": "7100466", "seat_id": 12 }    // seat_id optional

// 200
{
  "vehicle": { ... },        // unchanged: the vehicle, with seat.seat_arrangements and sacco
  "seat":    { ... } | null, // the seat_arrangement you asked for, or null
  "loyalty": {               // the caller's card for THIS bus's SACCO
    "sacco_id": 4, "balance": 50, "redemption_threshold": 5,
    "points_to_reward": 0, "eligible_to_redeem": true, "is_active": true,
    "point_value": 30, "balance_value": 1500   // KES one point buys; KES the balance buys
  },
  "points": null             // legacy key. ALWAYS null. Do not read it.
}
```

- `loyalty` is one `summary` row for the vehicle's SACCO, minus the `sacco` name
  (you already have it on `vehicle.sacco`). Same fields, same meaning, same
  decimals. Use `eligible_to_redeem` to decide whether to show the button.
- `loyalty` is **`null`** when the vehicle has no `sacco_id`. Treat null as "no
  program here" and show M-Pesa only.
- `points` is kept only so an older client reading the key does not crash on its
  absence. It carried null in practice anyway. New code reads `loyalty`.

| status | body | meaning |
|---|---|---|
| `200` | above | |
| **`401`** | `{"errors":{"till_number":["..."]}}` | **validation failure** — missing or non-numeric `till_number` |
| `401` | `{"message":"Unauthenticated."}` | no/expired token |
| `404` | `{"message":"No matatu is registered to that till number. ...","error":"<same>"}` | typo in the till |

**The validation 401 is a trap.** A missing or non-numeric `till_number` comes
back as **401 with an `errors` key**, not 400. If your HTTP layer signs the user
out on any 401, a typo in the till box logs the passenger out. Check for `errors`
before treating a 401 from this endpoint as an expired session.

### `POST qrcode/redeem_points`

```jsonc
// request
{ "vehicle_id": 750, "amount": 60, "seat_id": 12 }    // seat_id optional; vehicle_id is vehicle.id from the call above

// 200
{ "success": "Ride paid with points.", "points_spent": 20, "fare": 60, "balance": 30,
  "payment_id": 9, "replay": false }
```

- `points_spent` — `amount / point_value` at the time of payment, read from the
  ledger. Decimal. On a replay it is what the *first* payment cost.
- `fare` — the `amount` you sent, echoed.
- `balance` — the balance **after**, for this SACCO. Redraw the card from this;
  no second fetch needed.
- `payment_id` — the `qrcode_payments` receipt id. It is the same kind of row an
  M-Pesa QR payment writes, with `amount: 0` (zero shillings changed hands; the
  cost is on the points ledger). This is the reference to quote in a dispute.
- `replay` — `true` means *this call spent nothing*: it returned an earlier
  redemption. See the window below.

#### Every response you can get

| status | body | meaning |
|---|---|---|
| `200` | `{"success","points_spent","balance","payment_id","replay"}` | paid — or a replay of a payment inside the window |
| `400` | `{"errors":{"vehicle_id":["..."]}}` / `{"errors":{"amount":["..."]}}` / `{"errors":{"seat_id":["..."]}}` | missing `amount`, or a vehicle / seat that does not exist. **Different shape** |
| `401` | `{"message":"Unauthenticated."}` | no/expired token |
| `404` | `{"error":"Vehicle not found"}` | in practice unreachable — validation already checked the id exists |
| `422` | `{"error":"This vehicle does not belong to a SACCO."}` | `loyalty` was null on the vehicle call; you should not have offered the button |
| `422` | `{"error":"This SACCO has no active loyalty program."}` | |
| `422` | `{"error":"Point redemption is not set up for this SACCO yet."}` | no `point_value` on the program; the card shows `point_value: null` |
| `422` | `{"error":"Enter the fare to pay with points."}` | `amount` was 0 |
| `422` | `{"error":"This ride costs 20 points and you have 12.","points_needed":20}` | nothing written, balance untouched |

Same rules as the booking rail: the `400` is `{"errors": {...}}`, everything else
is `{"error": "<sentence>"}`, and the sentences are not stable codes.

Sending a `user_id` or `phone` does nothing. The endpoint spends the **caller's**
points, always. (An earlier version took a client-supplied phone, which let anyone
drain another number's balance. That is gone.)

#### The 10-minute replay window, and why it exists

**A repeat call for the same vehicle by the same passenger within ten minutes of a
points payment returns the FIRST payment and spends nothing.** You get `200` with
`replay: true`, the original `payment_id`, what that payment cost, and the current
balance. No socket event fires, because no balance moved.

Why it is time-based and not token-based: the QR is **printed and stuck inside the
matatu**. It never expires and every passenger on that bus scans the same string
forever, so *nothing in the request* can tell a retry from a new ride. On a moving
matatu the lost response is the common case, so the backend has to pick a side,
and it picks "same bus inside ten minutes is the same boarding". So:

- **Retry freely.** A timeout on this call cannot double-charge inside the window.
- **Two genuine rides on the same bus inside ten minutes cost one ride.** That is
  accepted and deliberate. Do not try to work around it with a second button.
- **Past ten minutes the same bus costs again.** That is a new boarding, and correct.
- The window only counts *points* receipts. An M-Pesa QR payment on the same bus a
  minute ago is not a replay; the passenger can pay with points on top of it.

#### What it writes, and where it shows up

One `qrcode_payments` receipt (`amount: 0`) and one ledger row: `type: "redeemed"`,
negative `value`, **`booking_id: null`**, keyed instead on
`source_type: "qrcode_payment"`, `source_id: <payment_id>`. In `history` you will
see those two columns; in `/activity` the row is `scheme: "loyalty"`,
`type: "redeemed"`, and `bookingId` is **null**. Do not assume every redemption
has a booking behind it any more.

A ride paid this way **never earns** (`amount` is 0, and a points-paid ride does not
earn back — same rule as the booking rail).

Realtime: `balance.changed` with `reason: "redeemed"` and a negative `delta`, after
the transaction commits. Nothing on a replay.

---

## `GET book_a_ride/loyalty/summary`

Per-SACCO cards, redeemable first, then closest to a reward.

```jsonc
{ "loyalty": [
  { "sacco_id": 4, "sacco": "NICCO MOVERS LIMITED", "balance": 50,
    "redemption_threshold": 5, "points_to_reward": 0,
    "eligible_to_redeem": true, "is_active": true,
    "point_value": 30, "balance_value": 1500 }
] }
```

- `point_value` — KES of fare one point pays for. **`null` means the SACCO has not
  set one**: show the card, hide "pay with points".
- `balance_value` — `balance × point_value`, the balance in shillings of travel.
  Put this on the card; it is the number a passenger actually understands.
- `points_to_reward` = `max(0, threshold − balance)` → the progress bar towards a
  *standard* ride. It is a goal, not a price: what a given ride costs is its fare
  over `point_value`.
- `eligible_to_redeem` = active program **and** threshold > 0 **and** balance ≥ threshold.
  Still "can afford a standard ride". A passenger below it can still pay a cheaper
  fare; one above it can still be short for a long one — the redeem call decides.
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
// a booking redemption
{ "id": 1, "sacco_id": 4, "value": -5, "type": "redeemed",
  "booking_id": 41, "source_type": null, "source_id": null,
  "created_at": "2026-09-09T06:23:22Z",
  "sacco": { "id": 4, "name": "NICCO MOVERS LIMITED" } }

// a scan redemption — no booking; keyed on the receipt instead
{ "id": 2, "sacco_id": 4, "value": -5, "type": "redeemed",
  "booking_id": null, "source_type": "qrcode_payment", "source_id": 9, ... }

// a refund — positive, keyed on the booking that was no-showed
{ "id": 3, "sacco_id": 4, "value": 5, "type": "refunded",
  "booking_id": 41, "source_type": null, "source_id": null, ... }
```

`type` is `earned` or `refunded` (value > 0), or `redeemed` (value < 0).
`reversed` is in the enum and is still never written. `?sacco_id` filters.

For the **Activity screen** prefer `GET /api/auth/book_a_ride/activity`, which
merges loyalty and carbon credits into one chronological stream — see
`docs/notifications-api.md`. A redemption appears there as
`scheme: "loyalty"`, `type: "redeemed"`, negative `value`; a refund as
`scheme: "loyalty"`, `type: "refunded"`, **positive** `value`, `isCredit: true`,
`label: "Returned to your balance"`, `bookingId` set.

---

## Refunds — not boarded means refunded

Shipped 2026-09-11. When the crew marks a passenger **not boarded**
(`driver/bookings/{id}/mark {action: "no_show"}`, or the trip-end fast path below),
the booking is cancelled, the seat is released, **and what the passenger paid comes
back**. Before this a no-show took the seat *and* the money from one tap, and there
was no path anywhere to give it back.

What comes back depends on what went in:

| how the booking was paid | what the passenger gets back |
|---|---|
| **points** | the exact points spent — read from the ledger, not recomputed from a rate that may have changed since |
| **money** (M-Pesa, cash, any till) | **the fare's worth in points** — `booking.amount / point_value`, exactly what redeem would have charged for this booking. KES 600 for four seats at KES 30 a point comes back as 20 points, which buys KES 600 of travel |
| **not paid** | nothing. The seat is released as before |

**Money comes back as points, NOT as KES.** Say this plainly in the UI. KES 150 by
M-Pesa comes back as KES 150 of travel on *this* SACCO, not as KES 150 on the phone.
A B2C M-Pesa refund is a separate Safaricom integration that does not exist yet;
this is the interim the product owner accepted.

Details you can rely on:

- The refund is **idempotent at the ledger**. The row is keyed `(booking_id, "refunded")`
  under a unique index, so a double tap or a retried request cannot refund twice.
- A money refund is worked out at the SACCO's `point_value` **even if the program
  has since been switched off**. If the SACCO has no program at all, or no
  `point_value`, there is no rate to convert at and **nothing is credited**.
- The earn from paying **is reversed** on a money refund. Paying KES 150 earned
  1.5 points; a no-show credits the fare's worth *and* writes a `reversed` row for
  that 1.5, so the passenger nets `refund − earned`, not both. Nothing was
  earned on a points-paid ride, so nothing is reversed there. Both rows appear in
  `/activity` (`type: "reversed"` negative, `type: "refunded"` positive).
- **Only a passenger is refunded.** A booking created by crew for a walk-in
  carries the crew member's `user_id`; those are never credited. The backend
  checks the user's type, returns nothing, and logs it.
- The points-vs-money decision is "is there a `redeemed` ledger row for this
  booking", not `payment_method`. A `paid` booking without one is treated as
  money-paid and gets the ride credit; an unpaid one gets nothing whatever its
  `payment_method` column says.

### What the passenger sees

An in-app notification (database + socket + FCM push, no SMS), `type: "trip"`,
`referenceId: "<booking id>"`:

```
title:   Not boarded
message: You were not boarded on booking #41. 5 points have been returned to your balance.
```

When **nothing** was refunded — unpaid, a SACCO with no program, a walk-in — the
message says so instead, and never claims a return that did not happen:

```
message: You were not boarded on booking #41 and your seat has been released.
```

The deep-link rule in `docs/notifications-api.md` (`type == "trip"` opens
`/passenger/ticket/{referenceId}`) will open a **cancelled** booking. Render that
state; do not assume a ticket a notification points at is live.

**The message text is fixed and is sent for every no-show, including ones that
refunded nothing** — an unpaid reservation, or a money-paid booking on a SACCO with
no threshold. The backend does not vary the sentence. If you want to show "you got
N points back", read it from the ledger (`/activity`, `type: "refunded"`) or from
the socket event, not from the notification.

Realtime: `balance.changed` with `reason: "refunded"`, positive `delta`, the new
`balance`, after the refund commits. Same channel and shape as below.

---

## The crew can see a points fare — `GET trips/bookings`

Added 2026-09-12. Every row in the crew's trip list now carries how the seat was
actually settled, so "I paid with points" can be checked against something:

```jsonc
{ "bookingId": 8, "amount": 150, "status": "PAID",
  "paidWith": "points",        // "points" | "mpesa" | "cash" | ... | null (not paid)
  "pointsSpent": 5,            // from the ledger; null unless paidWith is "points"
  "amountCollected": 0 }       // KES that reached the SACCO for this seat: 0 for points
```

`paidWith` comes from the loyalty ledger, not from `payment_method` — that column
is stamped from the reserve request (what the app *intended*) and a booking
reserved "with points" can still be settled by STK. Render `paidWith` next to
PAID; sum `amountCollected`, never `amount`, for takings. The SACCO dashboard's
bookings list carries the same three as `paid_with` / `points_spent` /
`amount_collected`.

---

## Trip end — for the crew app

Shipped 2026-09-11. `POST driver/trip/end` **refuses to end a trip while any paid
passenger is neither boarded nor no-showed**. Those are the bookings whose
`status_label` is `"confirmed"` (`status = true, paid = true, boarded = false`),
and `GET driver/bookings?status=confirmed` lists exactly that set, so the app can
pre-empt the refusal.

```jsonc
// POST driver/trip/end   (empty body)

// 409 — unresolved paid passengers. The trip has NOT ended. Nothing was refunded.
{
  "error": "2 paid passengers have not been marked as boarded or not boarded. Mark each one, or send unmarked: \"no_show\" to treat them all as not boarded.",
  "unmarked": [
    { "id": 41, "name": "Jane W", "passengers": 2, "from_id": 7, "payment_method": "loyalty_points" },
    { "id": 42, "name": "Peter K", "passengers": 1, "from_id": 7, "payment_method": "mpesa" }
  ]
}
```

- `unmarked[].id` — the booking id, which is what `driver/bookings/{id}/mark` takes.
- `unmarked[].passengers` — seats on the booking.
- `unmarked[].from_id` — pickup place id.
- `unmarked[].payment_method` — `cash | mpesa | ncba_till | coop_till | wallet | loyalty_points | null`.
- The `error` sentence is singular for one (`"1 paid passenger has ..."`). Do not
  parse it; the count is `unmarked.length`.

Put each one in front of the conductor: **board** or **no-show**. Then call end
again.

### The fast path

```jsonc
// POST driver/trip/end
{ "unmarked": "no_show" }

// 200
{ "success": "Trip ended.", "no_shows": 2,
  "trip": { "queue_id": 88, "queue_number": "...", "status": "Completed", "route": "...",
            "from": "...", "to": "...", "terminus": "...", "fare": 150,
            "started_at": "...", "departed_at": "...", "ended_at": "2026-09-11T14:02:11+00:00" } }
```

`unmarked: "no_show"` (that exact string; anything else is ignored) no-shows every
`confirmed` booking — refund, release, notify, each through the **same** code the
per-passenger tap uses — and then ends the trip. `no_shows` is the number of
**bookings** it no-showed (not seats); it is `0` when nothing was left unmarked,
including when every one was already tapped individually. It is idempotent with
the tap: a passenger already no-showed by hand is not refunded again.

**This is a real act, not a formality.** Each no-show refunds a passenger. The
backend refuses to do it silently at trip end *on purpose*: an unmarked passenger
is either a no-show the conductor forgot to record **or a passenger who rode and
was never tapped boarded**, and refunding the second kind hands a conductor a
collusion move (never tap board; the friend rides *and* gets the fare back). Put a
confirmation in front of the fast path that says how many passengers will be
refunded. Do not wire it to the "End trip" button directly.

### Every response from `driver/trip/end`

| status | body | meaning |
|---|---|---|
| `200` | `{"success","no_shows","trip"}` | ended |
| `403` | `{"error":"You have no active vehicle assignment."}` | caller is not on a bus |
| `404` | `{"error":"You are not currently on a trip."}` | no Active/Pending queue for the bus |
| `409` | `{"error":"You have not departed yet. Depart first, or cancel the queue."}` | queue is Pending, not Active. **No `unmarked` key** |
| `409` | `{"error":"...","unmarked":[...]}` | paid passengers unresolved. Branch on the presence of `unmarked`, not on the status alone |
| `422` | `{"error":"No completed status configured."}` | server misconfiguration |

### `driver/bookings/{id}/mark {action: "no_show"}` — what changed

The response did not: `200 {"success":"Marked as a no-show.","booking":{"id":41,"status":"failed"}}`.
What happens behind it did: the passenger is refunded as above and told. **The
response does not say whether or what was refunded.** If the crew app wants to
show it, it cannot get it from this call.

**The backend now enforces the state machine** (since 2026-09-11, the same
afternoon the paragraph above was true). Every transition that could move money
the wrong way is refused, and the crew app should expect these:

| you send | on a booking that is | you get |
|---|---|---|
| `no_show` | already **boarded** | `409 {"error":"This passenger is already boarded."}` — no refund, no cancel |
| `no_show` | already **cancelled** | `200 {"success":"Already marked."}` — idempotent; no second notification |
| `board` | **cancelled** (no-showed / expired) | `409 {"error":"This booking was cancelled; the passenger must book again."}` |
| `board` | **unpaid** | `409 {"error":"Take the fare first."}` — confirm cash or wait for M-Pesa |
| `board` | already **boarded** | `200 {"success":"Already boarded."}` |

Both writes are guarded UPDATEs (`WHERE status = true AND boarded = false`, etc.),
so a tap racing a trip-end or another conductor's tap cannot flip a row twice.
You no longer need a UI confirmation on *no-show* for a boarded row — but keep the
409 handling, because the row you're looking at can be stale.

`POST driver/queues/exit` gets the same guard as trip end: an Active queue with
any `confirmed` passenger answers **409** naming the count. Board or no-show them,
or end the trip with `unmarked: "no_show"`.

---

## Earning — no endpoint, and one rule that surprises people

Points are credited server-side. The app calls nothing; refresh `summary` after a
trip, or listen for the socket event below.

| how the fare was paid | earns points? |
|---|---|
| booking paid in-app (STK) | **yes** — `booking.amount ÷ divisor`, i.e. on the whole fare, all seats |
| QR scan on the bus, paid by M-Pesa | **yes** |
| **direct till payment** (passenger pays the till themselves) | **no** |
| a free ride bought with points — booking or scan | **no** — never earns back |

The direct-till exclusion is a deliberate product decision, not an oversight: it
exists to push passengers into the app. If a passenger insists they paid and got no
points, that is almost certainly why.

---

## Realtime

After any balance movement the backend fires `balance.changed` on the passenger's
private channel `App.Models.User.{id}`:

```jsonc
{ "scheme": "loyalty", "saccoId": 4, "balance": 45, "delta": -5,
  "reason": "redeemed", "progressCents": null, "at": "2026-09-09T17:04:11+03:00" }
```

`reason` is the ledger type: `earned` (+), `redeemed` (−), `refunded` (+). Same
vocabulary as `history` and `/activity`, so one switch serves all three.

**Treat it as an accelerant, not a source of truth.** It is fired after the
transaction commits, so a socket failure can never roll back a settled ride — which
also means a lost event leaves the balance correct on the server and stale on the
handset. Always re-fetch on screen open and pull-to-refresh.

A replayed redeem — booking or scan — fires **nothing**, because no balance moved.
A replayed refund likewise.

---

## Three things to know before you build

**Only NICCO can complete the BOOKING rail today.** Five SACCOs run an active
loyalty program, but four of them own no routes — so no queue, so no booking, so
nothing to redeem against with `loyalty/redeem`. **The scan rail has no such
limit**: it needs a bus with a till and a SACCO with an active program, not a
route. So a card for one of those four SACCOs *can* be spent — on their bus, by
scanning it. Offer "free ride" on a card only where the passenger can actually
spend it: the booking flow for NICCO, the scan flow for everyone.

**Refunds exist now, and they are points, and only the crew can trigger one.**
`refunded` is written when the crew marks a passenger not boarded — exact points
back if they paid in points, the fare's worth in points if they paid money, nothing
if they had not paid. There is **no passenger-side refund**: the self-service
cancel (`POST bookings/passengers/cancel/{id}`) refuses a paid booking outright
with `422 "A paid booking cannot be cancelled here. Contact support for a refund."`
— it only releases unpaid holds. So a passenger who paid with points and changes
their mind cannot get them back from the app; only the crew's no-show does that.
Do not build a cancel button that implies otherwise. And there is still no KES
refund of any kind.

**Reserving with `payment_method: "loyalty_points"` does nothing on its own.** It
stamps a column; it does not pay. Only `paid === true` means paid. Call redeem.
