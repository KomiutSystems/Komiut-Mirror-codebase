# Loyalty — real authenticated shapes (mobile)

Captured from the running backend, not the Scribe `/docs`. **Ignore the flat
`{points, redeemed_points}` shape in the live `/docs` — it is stale** (pre-dates the
per-SACCO loyalty rewrite). These are the true shapes.

Base: `https://api.komiut.com/api/v1/auth` · `Authorization: Bearer` + `X-App-Key`.

## The model (answers to James's two questions)
- **Points are per-SACCO.** A passenger has one balance per `(user, SACCO)`. Each SACCO sets its own program: `divisor` (KES of fare per point) and `redemption_threshold` (points for a free ride). Earning = `fare ÷ divisor`. So the per-SACCO mobile UI is correct — keep it.
- **Redeem targets a booking, not a SACCO.** You don't send a `sacco_id`; you send the `booking_id`. The backend derives the SACCO from the booking (`booking → queue → vehicle → sacco`) and spends *that* SACCO's threshold from *that* SACCO's balance.

## GET `book_a_ride/loyalty/summary`
Per-SACCO cards, sorted redeemable-first then closest-to-reward.
```json
{ "loyalty": [
  { "sacco_id": 1, "sacco": "Nairobi CBD SACCO", "balance": 620,
    "redemption_threshold": 500, "points_to_reward": 0,
    "eligible_to_redeem": true, "is_active": true },
  { "sacco_id": 2, "sacco": "Thika Road SACCO", "balance": 300,
    "redemption_threshold": 1000, "points_to_reward": 700,
    "eligible_to_redeem": false, "is_active": true }
] }
```
- `points_to_reward` = `max(0, threshold − balance)` → drive the progress bar.
- `eligible_to_redeem` = active program, threshold > 0, and balance ≥ threshold → show the "Free ride" tag.
- Empty array `{ "loyalty": [] }` when the passenger has no points anywhere.

## POST `book_a_ride/loyalty/redeem`  — pay with points
```json
// request
{ "booking_id": 41 }
// 200
{ "success": "Free ride redeemed!", "booking_id": 41, "points_spent": 500 }
```
Settles a **reserved (unpaid)** booking as paid, `payment_method = loyalty_points`.
Errors (all `{ "error": "..." }`): `403` not your booking · `422` already paid /
SACCO has no active program / not enough points. This is the "pay with points"
call in the booking flow — same place STK would go, gated by
`eligible_to_redeem` for that booking's SACCO.

## GET `book_a_ride/loyalty/history?sacco_id=&page=`
Standard Laravel paginator (`{ current_page, data:[…], total, per_page, … }`).
Each item:
```json
{ "id": 1, "sacco_id": 1, "value": -500, "type": "redeemed",
  "booking_id": 41, "created_at": "2026-08-03T06:23:22Z",
  "sacco": { "id": 1, "name": "Nairobi CBD SACCO" } }
```
`type` is `earned` (value > 0) or `redeemed` (value < 0). Optional `?sacco_id` filters to one SACCO.

## Earn (no endpoint — automatic)
Points are credited server-side when a booking is paid (M-Pesa), off the payment
path. The app doesn't call anything to earn; just refresh `summary` after a paid trip.
```
```
