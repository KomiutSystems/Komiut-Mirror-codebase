# My Bookings → Payments — the mobile contract

Added 2026-11-27. Captured from the running backend (tests:
`tests/Feature/Payments/PassengerPaymentFeedTest.php`,
`tests/Feature/Payments/PaymentUpdatedTest.php`).

Base: `https://api.komiut.com/api/v1/auth` (the legacy `/api/auth` prefix works
too) · `Authorization: Bearer` + `X-App-Key`.

Every payment the passenger started in the app, on their own account, in every
status, the moment it starts — and a socket event each time one moves, so the
row goes "waiting for your PIN" → "paid · UJ6BJ9BXGZ" without a pull-to-refresh.

- **In:** bookings (`kind: "booking"`) and QR-sticker fares (`kind: "qr_fare"`),
  paid by M-Pesa prompt (Safaricom's own, or through NCBA / Co-operative Bank —
  the passenger enters an M-Pesa PIN either way), by points, or from the wallet.
- **Out:** money sent from the M-Pesa menu straight to a till. Those carry only
  a phone number and are never matched to an account by it.
- `GET payments/history` is unchanged and still answers exactly as before
  (paid bookings only). New screens should read this endpoint instead.

---

## GET passenger/payments

Newest first by `created_at`, ties by `id` (then `qr_fare` before `booking`),
20 per page, cursor paging.

| query | |
|---|---|
| `status` | optional. One of `pending`, `paid`, `failed`, `cancelled`, `refunded`, or `active` (= `pending`, for the pinned "settling" section). Anything else → `400`. |
| `from`, `to` | optional. `Y-m-d`, **Nairobi** days, both inclusive. Either alone is fine. Bad format → `400`. |
| `cursor` | the `next_cursor` of the previous page. Opaque. A garbled one is read as page one, not an error. |

```json
{
  "payments": [
    {
      "id": "qr_fare:4812",
      "kind": "qr_fare",
      "status": "paid",
      "method": "mpesa",
      "amount": 100,
      "currency": "KES",
      "points_spent": null,
      "mpesa_receipt": "UJ6BJ9BXGZ",
      "status_message": null,
      "plate": "KDN 458N",
      "sacco": "NICCO Movers",
      "route": "Nairobi CBD - Thika",
      "pickup": null,
      "dropoff": null,
      "passengers": null,
      "booking_id": null,
      "checkout_request_id": null,
      "created_at": "2026-11-26T10:30:12+03:00",
      "settled_at": "2026-11-26T10:30:41+03:00",
      "updated_at": "2026-11-26T10:30:41+03:00"
    }
  ],
  "next_cursor": "MjAyNi0xMS0yNiAwNzozMDoxMnw0ODEyfHFyX2ZhcmU"
}
```

`next_cursor` is `null` on the last page, and only then. Paging is a keyset, so
a payment that lands while the passenger scrolls appears at the top of the next
first-page read — never twice, and nothing is skipped.

Scoping: always the signed-in caller (`user_id = auth()->id()`). There is no
permission branch and no parameter that names a user. **Passenger accounts
only:** a driver, conductor, office or staff account gets
`{"payments": [], "next_cursor": null}` (and `404` on the single read), and so
does a number-plate (crew) session on any account — the route is open to it,
so My Bookings → Payments shows an empty list rather than an error. A booking
records who keyed it, and a conductor's walk-in bookings carry the
conductor's id; they are never shown to, or announced on, the conductor's
account. Throttled at 60 requests a minute per user, shared between this and
the single read below.

## GET passenger/payments/{id}

One payment — the same object as a list row, not wrapped. `{id}` is the row's
`id` (`booking:123`, `qr_fare:4812`). Another passenger's payment, a missing
one and a malformed id are all `404` (never `403`).

This is what the app re-reads when `payment.updated` arrives, and what the
receipt sheet opens.

---

## Fields

| field | |
|---|---|
| `id` | `"booking:{id}"` / `"qr_fare:{id}"`. Stable, unique across both kinds. Use it as the list key. |
| `kind` | `booking` \| `qr_fare` |
| `status` | exactly one of the five below |
| `method` | `mpesa` \| `points` \| `wallet` (see *Differences from the brief*). A booking a conductor took cash for reads `cash`. An unpaid booking that has had an M-Pesa prompt reads `mpesa`, whatever was chosen when it was reserved. |
| `amount` | KES the fare was worth. For a points or wallet fare, the fare the points/wallet covered — not the 0 KES that reached the till. |
| `currency` | always `KES` |
| `points_spent` | points fares only: the points it cost. `null` otherwise. |
| `mpesa_receipt` | the M-Pesa code once paid (kept on a refunded booking), else `null`. `null` too when the payment was confirmed without learning its code — by STK Push Query rather than Safaricom's callback, or by NCBA's query before NCBA's own confirmation arrived. A stand-in (`ws_CO_…`, `recon-…`, `NCBA-…`) is never shown, here or in the push. |
| `status_message` | short sentence for `failed`, `cancelled` and `refunded`; `null` for `paid` and `pending`. Always one of the sentences listed below — never Safaricom's or the bank's own words. |
| `plate`, `sacco`, `route` | `null` when unknown, never `""`. For a QR fare, `route` is the route of the bus's most recent live trip started in the 24 h before the payment; `null` if the bus was not live in the app. |
| `pickup`, `dropoff`, `passengers`, `booking_id` | bookings only; `null` on QR fares |
| `checkout_request_id` | while `pending` only (the id `mpesa/stk/status/{id}` polls by); `null` otherwise, and `null` while a bank has not yet acknowledged the prompt |
| `created_at` | ISO-8601, Nairobi time (`+03:00`) |
| `settled_at` | when it became paid, failed or cancelled; `null` while pending. A refunded payment keeps its paid time. Paid time comes from the receipt, the points redemption or the wallet's fare entry; a **cash** booking has none of those, so its `settled_at` is the booking's last change and is approximate. |
| `updated_at` | latest change to the payment; matches the `updated_at` on `payment.updated` |

No phone numbers, not even masked.

## Statuses

| status | means |
|---|---|
| `pending` | a prompt is out and nothing is decided: waiting for the PIN or for Safaricom/the bank to answer. Also a booking whose seat is held but not yet paid. |
| `paid` | the money, points or wallet arrived |
| `failed` | Safaricom or the bank said no (wrong PIN, not enough money, cancelled on the phone, timed out…), the prompt could not be sent, or nobody answered within 10 minutes |
| `cancelled` | the passenger backed out in the app (cancelled the prompt, or the booking) |
| `refunded` | paid, then given back: not boarded (no-show) or the trip ended first — those go back as points — or a wallet fare support refunded to the wallet. |

Nothing stays `pending` forever. `payments:reconcile` (every two minutes) asks
about every open prompt — bookings and QR fares, Safaricom's own (STK Push
Query) and the banks' (NCBA, Co-op enquiry) — and settles it as `paid` or
`failed` from the answer, firing `payment.updated`. Only a decided answer
fails it from the query (Daraja 1 not enough money, 1032 cancelled, 2001 wrong
PIN); anything else — 4999 "still under processing", 1037 and the rest — is
asked again. Ten minutes after a prompt was raised with no decisive answer the
payment reads `failed` with the "did not confirm" sentence. The prompt is
never closed by that: Safaricom is asked for the next 20 minutes, and a
callback that arrives at any time later still counts — if it proves the money
moved, the payment flips to `paid` and `payment.updated` fires again.

A QR fare reads `refunded` only when support gave a wallet fare back; nothing
refunds an M-Pesa or points QR fare.

### `status_message` — every sentence

| sentence | when |
|---|---|
| `You cancelled the M-Pesa prompt.` | Daraja 1032, or the bank says cancelled by the user/customer |
| `Not enough money in M-Pesa.` | Daraja 1, or "insufficient" from the bank |
| `The M-Pesa PIN was wrong.` | Daraja 2001, or wrong/invalid PIN from the bank |
| `The M-Pesa prompt timed out before a PIN was entered.` | Daraja 1037, or a timeout from the bank |
| `Your phone was busy with another M-Pesa request.` | Daraja 1001 |
| `The M-Pesa request expired.` | Daraja 1019, or "expired" from the bank |
| `The M-Pesa prompt could not reach your phone.` | Daraja 1025, 1026 |
| `M-Pesa did not complete this payment.` | any other refusal |
| `The M-Pesa prompt could not be sent.` | Safaricom or the bank refused the push itself; no prompt went out and no money moved |
| `M-Pesa did not confirm this payment. If money left your account, contact support with the time and amount.` | no answer within 10 minutes |
| `You cancelled this payment in the app.` | cancelled with `mpesa/stk/cancel` |
| `This booking was cancelled before it was paid.` | the booking was cancelled (by the passenger or the office) before any payment |
| `The booking expired before it was paid.` | the unpaid hold ran out |
| `The trip ended before this booking was paid.` | the trip ended with the booking unpaid |
| `You were not marked as boarded, so the fare was refunded as points.` | refunded, no-show |
| `The trip ended before you boarded, so the fare was refunded as points.` | refunded, trip over |
| `The fare was refunded as points.` | refunded, any other reason |
| `The fare was refunded to your wallet.` | a wallet fare refunded by support |

The constants live in `App\Services\Payments\PassengerPayments\PaymentStatus`
(`MSG_*`); the status mapping lives there and nowhere else.

---

## Real time: `payment.updated`

- Channel: `private-App.Models.User.{user_id}` — the one the app already holds
  for `balance.changed` and notifications.
- Event name: `payment.updated`
- Payload — a pointer, not the data:

```json
{ "id": "qr_fare:4812", "kind": "qr_fare", "status": "paid", "updated_at": "2026-11-26T10:30:41+03:00" }
```

Fires once per status change of an in-app payment, both kinds, every method:
prompt raised (`pending`), `paid`, `failed`, `cancelled`, `refunded` (a
support refund of a wallet fare included), and only to a passenger account's
channel. Two settlers racing on one payment are announced in order: the
payment is read under a lock on its row, so a stale "failed" is never sent
after "paid". A points or
wallet fare is created already paid and fires once, as `paid`. A booking fires
`pending` when it is created and again when its prompt goes out. Sent after the
change is committed; a resent Safaricom callback, or a second poller reaching
the same answer, fires nothing.

An M-Pesa payment fires `paid` once its receipt is recorded, so the re-read
already carries `mpesa_receipt`.

It is an accelerant, not the source of truth. The app should:

- on `payment.updated`, re-read `GET passenger/payments/{id}`;
- re-fetch the first page on resume and on socket reconnect;
- poll a pending payment every 5 s if no event arrived within ~20 s.

## Push

For an M-Pesa QR fare that settles after the passenger left the prompt:

| | title | body (en) | body (sw) |
|---|---|---|---|
| paid | Fare paid / Nauli imelipwa | `Paid KES 100 to KDN 458N · UJ6BJ9BXGZ` | `Umelipa KES 100 kwa KDN 458N · UJ6BJ9BXGZ` |
| paid, no code | Fare paid | `Paid KES 100 to KDN 458N` | `Umelipa KES 100 kwa KDN 458N` |
| failed | Payment failed / Malipo hayakufaulu | `KES 100 to KDN 458N was not paid. <status_message>` | `Malipo ya KES 100 kwa KDN 458N hayakukamilika.` |

`type: "payment"`, `referenceId` = the payment id (`"qr_fare:4812"`) — open the
receipt sheet with it. One push per change; a resent callback sends none.
Bookings keep their existing "Booking confirmed" push (`type: "trip"`).

---

## Differences from the brief

- **`method` can be `wallet`.** Wallet fares and wallet-paid bookings are
  in-app payments on the passenger's own account, so they are listed rather
  than hidden; the brief named only `mpesa` and `points`. A conductor-entered
  cash booking reads `cash`.
- **Show returns the bare payment object**, not `{ "payment": … }` — "same
  shape" as a list row.
- **The QR-fare push is M-Pesa only.** Points and wallet fares are decided at
  the tap and shown on that screen; a push would repeat it.
- **A QR fare is `refunded` only when it was a wallet fare** given back by
  support; nothing in the system refunds an M-Pesa or points QR fare.
- **Only passenger accounts have payments.** A crew, office or staff account,
  or any number-plate session, gets an empty list and `404`: `user_id` on a
  booking is whoever keyed it, so a conductor's walk-ins would otherwise list
  as the conductor's.
- **`settled_at` on a cash booking is approximate**: nothing records when the
  crew took the cash.
- **`mpesa_receipt` is `null`** for a payment confirmed only through STK Push
  Query (no callback) or NCBA's query, because neither returns a code; the
  stand-in stored in its place is never shown.
- **`route` on a QR fare is inferred** from the bus's live trip, since a scan
  names a bus and not a journey.
- **Safaricom is first asked 45 s after the prompt**, not after the 120 s
  prompt lifetime: that is the existing reconcile grace, and an early query
  only ever settles what the passenger already did (a non-final answer is
  asked again). The give-up cut-off is 10 minutes, as briefed.
- Rows with no `created_at` (none written by this system) are not listed.
- Co-op STK pushes are recorded as `mpesa_stk_callbacks` rows with
  `provider = 'coop'` (the `coop_mpesa_stk_requests` table is an unused
  stub); they list exactly like Safaricom's.

## Backend notes

- `App\Services\Payments\PassengerPayments\PaymentStatus` — the one mapper
  (PHP, and the same rules as SQL for `?status=`).
- `PassengerPaymentFeed` — the list: a `UNION ALL` of two keyset walks
  (`bookings` and `qrcode_payments`, each on `(user_id, created_at, id)`),
  merged by `created_at`, then one batched load per relation.
- `PaymentUpdates` — announces from the booking events and the push / fare /
  receipt model events (and a wallet refund), after commit, deduped on
  `payment_announced`, which is compared and moved while the payment's row is
  locked. Owners that are not passenger accounts are never announced to.
- `payments:reconcile` now also queries Safaricom for QR-fare pushes and stamps
  `mpesa_stk_callbacks.unconfirmed_at` on silent ones (refused pushes are left
  out in the query, so they cannot fill its batch). It closes a push only on a
  final answer (1, 1032, 2001) and re-reads the push under a lock before
  confirming one, so a callback landing during the query is not settled twice.
- A QR fare is settled under a lock on its row: a second "paid" for the same
  payment writes no second receipt and awards nothing twice.
- `App\Services\Mpesa\StandInReceipt` — the one list of stand-in receipt
  prefixes, shared by the settlements that write them and the feed that hides
  them.
