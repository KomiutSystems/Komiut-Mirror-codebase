# Web Team Handoff — driver onboarding, SACCO directory, bank leads

Written for the Next.js dashboard team. Covers **what we just shipped, why it works
the way it does, and the exact contracts**. Live docs (auto-generated, always
current): **https://api.komiut.com/docs**

---

## 0. Ground rules (these bite if missed)

| Thing | Value |
|---|---|
| Base URL | `https://api.komiut.com` |
| Path prefix | `/api/v1/auth/...` (`/api/auth/...` is an identical alias) |
| **Brand header** | `X-App-Key: komiut` or `safiri` — **required on every call** |
| Auth | `Authorization: Bearer <token>` from a login endpoint |

**The brand header is not optional.** This backend serves two white-label products
(komiut and 2Safiri) out of one codebase. Brand is resolved from `X-App-Key` (or the
hostname) *before* auth, and it **fails closed with a 404** — so a missing or unknown
key looks like "endpoint doesn't exist", not "unauthorized". If you get a mystery 404
on a route you can see in the docs, check this header first.

**This service is now API-only.** The Blade dashboard, the marketing pages and
`/pay_online` were deleted. Nothing server-rendered remains. ⚠️ **Before `komiut.com`
is cut over from legacy Mumbai to this backend, the Next.js app must cover those
public paths** — right now they'd 404.

---

## 1. Why driver onboarding exists (read this before the endpoints)

The marketing team onboards matatu drivers **in person, on the street**. The problem:
a driver works *under a SACCO*, but **most SACCOs haven't signed up** and their
decision-makers are hard to reach. Waiting for SACCOs to register would block driver
acquisition entirely.

So we inverted it:

> **A SACCO is a directory entry (just a name) until it chooses to claim itself.**

- Directory entries have **no account, no password, nothing to log into**.
- A driver picks their SACCO from that directory during onboarding — or types a new
  name, which creates a `pending_review` entry.
- When the SACCO later registers, it **claims** the existing entry, **keeping its id** —
  so every driver already onboarded under that name becomes a member automatically.

That last part matters for the dashboard: a SACCO that signs up may already have
drivers and vehicles attached on day one.

**A SACCO row now carries `claim_status`:**

| Value | Meaning |
|---|---|
| `directory` | Reference data. Seeded or admin-entered. No login. |
| `pending_review` | A driver typed this name; needs admin review/merge. |
| `claimed` | A real tenant with a dashboard login. |

---

## 2. Driver onboarding flow

```
1. GET  saccos/directory?q=nic     → driver picks their SACCO (or types a new name)
2. POST driver/onboard             → creates driver + vehicle + assignment
3. POST driver/login               → phone + plate, no password
```

### 2.1 SACCO type-ahead

```
GET /api/v1/auth/saccos/directory?q=nic
X-App-Key: komiut
```
**No auth** (the driver has no token yet). Min 2 chars, max 20 results, rate-limited 60/min.

```json
{ "saccos": [ { "id": 12, "name": "Nicco SACCO" } ] }
```

Returns **only `id` and `name`** — deliberately. SACCO rows carry contact details and
this endpoint is public, so it never serialises the model.

### 2.2 Onboard the driver

```
POST /api/v1/auth/driver/onboard
X-App-Key: komiut
```
**No auth.** Rate-limited 20/min.

| Field | Rules |
|---|---|
| `firstname` | required, ≤60 |
| `lastname` | required, ≤60 |
| `phone` | required, **exactly 10 digits** (e.g. `0712345678`) |
| `email` | optional |
| `id_number` | **required** — the bank can't open an account without it |
| `sacco_id` | required *without* `sacco_name`, must exist |
| `sacco_name` | required *without* `sacco_id`, ≤120 — creates a `pending_review` entry |
| `plate` | required, ≤20 (normalised server-side: `kdq 446r` == `KDQ446R`) |
| `vehicle_capacity` | optional, 1–100 — seat count; the bank sizes the account by it |
| `bank_opt_in` | boolean |
| `preferred_branch` | required **if** `bank_opt_in` is true |

**201 Created:**
```json
{
  "driver":  { "id": 4630, "firstname": "John", "lastname": "Mwangi",
               "phone": "0712345678", "type": "driver" },
  "sacco":   { "id": 12, "name": "Nicco SACCO" },
  "vehicle": { "id": 752, "plate": "KDQ 446R" },
  "next_step": "Sign in with this phone number and number plate. No password needed."
}
```
`400` validation errors · `409` conflict.

**Two behaviours to design around:**
- **There is no OTP.** Product decision for speed on the ground. The phone is *not*
  verified, so treat it as user-entered.
- **Re-onboarding the same phone does not create a second driver.** It reuses the
  existing driver and **moves them to the new vehicle**, closing the previous
  assignment. Onboarding the same person twice is safe.

### 2.3 Driver login — and why login *is* the vehicle assignment

```
POST /api/v1/auth/driver/login
{ "phone": "0712345678", "plate": "KDQ 446R" }
```

**No password.** SACCOs rotate drivers between vehicles daily, so rather than making
someone maintain assignments by hand, **the act of logging in records "this driver is
on this vehicle today"** — the previous assignment is closed with an `end_date`, giving
a full rotation history for free.

Guard: **the driver and vehicle must belong to the same SACCO** (a plate is publicly
visible on the bus), otherwise `403`.

Token lifetime follows the SACCO's `rotates_drivers` flag — expires end-of-day if it
rotates, non-expiring if not.

**For the dashboard:** "who is driving what right now" = the driver's active
`VehicleUser` row (`status = true`, `end_date = null`). History = the closed rows.

---

## 3. SACCO registration now *claims*

`POST /api/v1/auth/register/sacco` — unchanged fields (`name`, `email`, `phone`,
`password` + `password_confirmation`), but the behaviour changed:

- Name matches an **unclaimed** directory entry → **claims it**, keeping the id and its drivers.
- Name matches an **already-claimed** SACCO → `400`, `errors.name`.
- Name is new → creates it as `claimed`.

This existed because otherwise the first driver onboarded under "Nicco SACCO" would
have **permanently blocked Nicco from ever registering** (the name is unique).

---

## 4. `last_active_at` — available on every user

Every user payload now includes `last_active_at` (ISO timestamp or `null`). It's stamped
on authenticated requests, throttled to once per 5 minutes.

It is **not** `updated_at` — `updated_at` still means "the record was edited". Use
`last_active_at` for "last seen" columns and active/dormant filters. No new endpoint;
it's already in the existing user/member responses.

---

## 5. Bank partner lead list — **API only, you build the page**

Banks (**NCBA → komiut**, **Co-op → 2safiri**) want a list of drivers who want an
account opened, so they can pre-fill paperwork and move faster.

The **bank is derived from the brand, never chosen by the client.**

Fields per lead: **name, email, phone, SACCO, preferred branch, vehicle seats** (+
opt-in timestamp, so we can show a bank the driver actually asked).

**Status: not built yet — blocked on the access password.** It will be a
password-protected, brand-scoped, read-only list + CSV export. It is **not public**:
it's driver personal data, so an open URL would expose the whole pipeline to anyone
who guessed it.

---

## 6. Passenger Google sign-in — built, needs credentials

```
POST /api/v1/auth/social/google
{ "access_token": "<token from native Google Sign-In>" }
→ { user, access_token, token_type }
```

- **Passengers only.** A driver/admin/superadmin account gets `403` — staff must use credentials.
- Links by email, so someone who signed up with a password can later use Google and keep the same account.

**Blocked on Google Cloud Console credentials (Web Client ID + Secret).**

⚠️ **Pending hardening:** it currently accepts a Google *access token*. We plan to
switch to the **ID token** and verify the audience. If that lands, the app sends
`id_token` instead of `access_token` — a one-field change, flagged here so it isn't a surprise.

---

## 7. Also shipped recently (may affect existing screens)

- **Seat availability is segment-aware** (`GET book_a_ride/seats`) — returns the layout
  plus `booked[]`. Two passengers can share one seat on non-overlapping legs. **Not
  realtime** — poll while the picker is open.
- **`bookingType`** on bookings is now real: `route` (booked at the terminus) vs
  `pickAsYouGo` (flagged down while the matatu is already moving).
- **Driver trip lifecycle:** `POST queues/join`, `POST trips/start`, `POST queues/exit`,
  `GET trips/bookings`.
- **Realtime is Pusher-protocol (Laravel Reverb)**, not SignalR.
