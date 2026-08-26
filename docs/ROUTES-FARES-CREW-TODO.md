# Routes, Fares & Crew — what's built, what the frontend must do, what's left

Written 2026-08-26. Everything marked **DONE** is on branch `feat/routes-fares-crew`.
Everything marked **FRONTEND** is work the web dashboard or the mobile app must do —
the API side is finished and waiting.

---

## 1. The answer: which endpoint does the passenger app call to pick a route?

**There is no single one. It's a sequence**, and the first step *did not exist* until now.

| # | Call | What it's for |
|---|---|---|
| 1 | `GET /api/v1/auth/book_a_ride/stops` | **NEW.** The stop picker. Where the passenger is leaving from and going to. |
| 2 | `GET /api/v1/auth/book_a_ride/routes?from_place_id=&to_place_id=` | **The route picker.** Routes serving that segment, in that direction. |
| 3 | `GET /api/v1/auth/book_a_ride/queues?from_id=&to_id=` | The actual trips, with seats free. |
| 4 | `GET /api/v1/auth/book_a_ride/fare?sacco_id=&route_id=&from_id=&to_id=` | The price. Authoritative — never compute it client-side. |
| 5 | `GET /api/v1/auth/book_a_ride/seats?bus_id=&id=` | Seat map. |
| 6 | `POST /api/v1/auth/book_a_ride/booking/add` | Book. The **server** sets the price. |

**Why step 1 had to be built.** `book_a_ride/routes` searches by *place id*. The only
way to learn a place id was `GET routes/places`, which is gated on the `View Places`
permission — and a passenger holds no permissions at all. So the passenger journey had
no step one: the app could search only if it already knew two ids it had no way to get.

`book_a_ride/stops` is authenticated but **ungated**, and returns only places that are
actually a stop on a live route. That filter matters: `places` holds 1,980 rows for 120
distinct names, of which **936 are literally called "Boarding Terminal not provided"**.

Supports `?search=`, `?latitude=&longitude=` (sorts nearest first), and
`?from_place_id=` (only stops reachable *onward* from that one, so the passenger can't
pick a pair that no route serves).

---

## 2. Who can add routes — DONE

**Before:** anyone with `Add Routes` could rewrite *any route on the platform*. The
endpoint took an id behind a permission check with **no ownership test**. Since
`sacco_routes`, `route_fares` and live `queues` all point at `routes.id`,
re-destinating a row silently took another SACCO's fares and running trips with it.
Same shape on `routes/stages/add` and `routes/stages/coords/add`.

**Now:** `routes` has a `sacco_id` and is scoped. A SACCO admin sees and edits only
their own. Permission is still `Add Routes` / `Edit Routes` — both already on the
**SACCO Admin** role (verified in production: it holds all 103 needed permissions).

---

## 3. "Routes should be per SACCO, no shared routes" — DONE

Your instinct matched the data exactly.

| Production reality (measured 2026-08-26) | |
|---|---|
| `routes` rows | 1,972 |
| …with **no** SACCO pointing at them | **1,971** |
| …with a name | **0** |
| routes shared by more than one SACCO | **0** |
| `sacco_routes` rows, whole platform | **1** |
| `route_fares` rows | **0** |
| `route_stages` rows | **0** |
| `places` with coordinates | **0 of 1,980** |

The shared-corridor model was never once used, and it was what made the cross-tenant
writes possible. Two SACCOs on Nairobi–Thika now hold **a route row each**, priced and
staged independently — which is what a passenger is choosing between anyway: not a
corridor, but *whose bus, leaving when, for how much*.

The 1,971 orphans keep `sacco_id NULL`, which makes them invisible to every SACCO admin.
That's deliberate — a picker listing 1,971 indistinguishable unnamed routes is unusable.
**They should probably be deleted**; see §8.

---

## 4. Routes have fares, and fares have peak windows — DONE

There was **no time-based fare support anywhere** — no column, no config, no service.
This is all new.

**Resolution order** (most specific wins):

1. A stop-pair fare on a **peak period covering right now**, highest priority first
2. The base stop-pair fare
3. The SACCO's flat `sacco_routes.amount`
4. `null` → the caller **refuses**. It never guesses and never trusts a client amount.

**A period is defined once and priced against many segments.** "Morning peak, Mon–Fri,
06:00–09:00" lives in one row; when the rush shifts, you move it in one place and every
fare that references it moves with it.

**Amounts, not multipliers** — deliberate. Matatu fares are round negotiated numbers
(100, 150, 200). 1.4× of 150 is 210, which nobody charges, and then the rounding rule
becomes a second thing to get wrong.

**Times are Kenyan wall-clock** (Africa/Nairobi). Evaluated in UTC every window on the
platform would run three hours late. There's a test pinning this.

**Overnight windows work**: `end_time` earlier than `start_time` wraps midnight, so
21:00 → 05:00 is the late-night rate, and it belongs to the day it *starts* on.

**The cache does not freeze the price.** The fare bundle is cached for an hour but holds
period *definitions*; which one is live is decided per request. Baking the resolved price
in would make a 06:00 peak start somewhere between 06:00 and 07:00 depending on traffic —
the single most likely way to get this wrong. Also tested.

---

## 5. The map / OpenStreetMap pins — my recommendation

**Yes, do it, and it needs nothing new from the API.**

- There is **no** Leaflet, Mapbox, OSM, or geocoding anywhere in this codebase today.
  The only map integration is Google Maps in five dead Blade views whose API key was
  scrubbed (`***REMOVED***`).
- Leaflet + OSM tiles is the right choice: no API key, no per-load billing, and Kenyan
  stage-level detail in OSM is good.
- The API already takes exactly what a pin-picker produces: **two separate numbers**,
  `latitude` and `longitude`.

**The bug in your "New route" dialog screenshot is exactly this.** The field held
`-1.2858° S, 36.8286°` — one string, with a degree sign and a hemisphere letter. Every
coordinate rule in the codebase is `numeric`, and there is **no DMS or hemisphere parser
anywhere**. Send `latitude: -1.2858` and `longitude: 36.8286` as two numbers.

Also: `starts_at` / `ends_at` from that dialog appear **nowhere** in this codebase —
zero grep hits across `app/`, `routes/`, `database/`. No endpoint has ever accepted that
body. The dialog was built against a spec that was never implemented. §6 is the real one.

---

## 6. FRONTEND CONTRACT

> You asked to be told this every time so you don't forget. This is that list.

### 6.1 Web dashboard — **NEW: build a route in one call**

Replaces the old four-call flow (`routes/add` → `routes/place/add` ×N →
`routes/stages/add` ×N → `routes/stages/coords/add` ×N), which had no transaction
around it, so half a route was a normal outcome — and which *refused* unless every stop
already existed, so "name two new stops, drop two pins, save" could not be expressed.

```
POST /api/v1/auth/saccos/routes/build

{
  "name": "Nairobi CBD - Thika Main Stage",     // optional; derived from first+last stop
  "fare": 150,                                   // base fare, whole route
  "status": true,
  "stops": [                                     // TRAVEL ORDER. First = origin, last = destination.
    { "place_id": 12 },                          // an existing stop
    { "name": "Thika Main Stage",                // or a new one, from a map pin
      "latitude": -1.0396,
      "longitude": 37.0900,
      "county_name": "Kiambu" }
  ]
}

201 { "route": { id, sacco_id, name, from, to, fare, status,
                 stops: [{ sequence, place_id, name, latitude, longitude }] } }
400 { errors }    // validation
403 { error }     // no permission, or you named another SACCO
409 { error: "You already run a route between these two stops." }
422 { error: "Stop 2 (\"Thika Main Stage\") is new, so it needs a latitude and a longitude — drop a pin on the map." }
```

- 2–60 stops.
- **Do not send `distance` or `sequence`** — both are derived server-side. `distance` is
  cumulative km from the origin and is what makes a route findable at all
  (`book_a_ride/routes` matches on `pickup.distance < dropoff.distance`), so a client
  typo there would make a route unbookable in one direction.
- An existing stop with the same name is **reused**, not duplicated, and gets its
  coordinates backfilled if it had none.

**STOP CALLING:** `routes/add`, `routes/stages/add`, `routes/stages/coords/add`.
They still work and are now ownership-checked, but they're the old path.

### 6.2 Web dashboard — **NEW: peak fare windows**

```
GET  /api/v1/auth/saccos/fare-periods
     → { periods: [{ id, name, days:[1..7], start_time:"06:00", end_time:"09:00",
                     priority, status, wraps_midnight, live_now }],
         server_time, timezone: "Africa/Nairobi" }

POST /api/v1/auth/saccos/fare-periods/save
     { id?, name, days:[1,2,3,4,5], start_time:"06:00", end_time:"09:00", priority?, status? }
     // days are ISO: 1 = Monday … 7 = Sunday. end < start means it wraps midnight.

POST /api/v1/auth/saccos/fare-periods/delete   { id }
     → also reports fares_removed — deleting a window deletes the prices hung off it
```

Then price a segment for a window with the **existing** fares endpoint, plus one new field:

```
POST /api/v1/auth/saccos/fares/add
     { route_id, from_place_id, to_place_id, amount, fare_period_id? }
     // fare_period_id omitted / null  = the BASE fare (charged outside every window)
     // fare_period_id set             = the price while that window is live
```

`live_now` on the period list is there so the dashboard can say "Morning peak is live
now" instead of making an operator work it out from a table of times.

### 6.3 Passenger app — **NEW stop picker + peak info**

```
GET /api/v1/auth/book_a_ride/stops?search=Thika
GET /api/v1/auth/book_a_ride/stops?latitude=-1.2864&longitude=36.8172      // nearest first
GET /api/v1/auth/book_a_ride/stops?from_place_id=12                        // onward stops only
    → { stops: [{ id, name, county, latitude, longitude, km_away }], total }
```

`GET book_a_ride/fare` now also returns:

```json
{ "fare": { "amount": 200, "currency": "KES", "is_peak": true,
            "period": { "id": 3, "name": "Morning peak" } } }
```

**Show this.** A passenger quoted 200 at 07:00 and 150 at 11:00 will otherwise conclude
the app is broken or the SACCO is cheating.

### 6.4 Crews page — **NEW actions**

```
POST /api/v1/auth/crew/{id}/role       { roles: ["Driver"] }     // FULL replacement, not a delta
GET  /api/v1/auth/crew/vehicles?search=KDY&free_only=true        // the vehicle picker for Assign
```

`GET /api/v1/auth/crew` now also returns:

```json
{ "counts": { "total": 227, "named_after_a_bus": 171, "holding_more_than_one_bus": 1,
              "unassigned": 12, "role_type_mismatch": 37, "capped": false },
  "assignable_roles": ["Driver", "Conductor", "Queue Supervisor", "Investor", "..."] }
```

- **`counts` is whole-SACCO, not the current page.** The headline on your screenshot
  ("13 named after a bus rather than a person") was computed from the 20 rows on screen.
  The real NICCO figure is far higher. Use `counts`.
- **`assignable_roles`** is already filtered to what *this caller* may hand out — a
  permission ceiling stops anyone granting beyond what they hold. Use it for the dropdown.
- Each crew row now also carries `suspended_at` and `suspension_reason`, so the screen can
  tell "switched off by our SACCO" from "suspended by the platform, with a reason".

---

## 7. Why you couldn't update on the Crews page

Three separate reasons, all now addressed:

1. **The crew endpoints weren't deployed.** The whole crew feature sat unmerged on
   `fix/day1-hardening`. Merged and deployed 2026-08-26.
2. **There was no role-change endpoint on that screen at all.** `POST crew/{id}` validated
   exactly five fields — firstname, lastname, phone, email, status — and touched no roles.
   Now `POST crew/{id}/role` exists, delegating to the already-audited member-roles path.
3. **Most NICCO admins can't edit anything.** 37 of NICCO's 40 `type = admin` accounts
   **do not hold the `SACCO Admin` role** — they hold `Investor`, which has 11 read-only
   permissions and no edit rights whatsoever. Millicent Gichimu (id 119) is one of them.
   Every edit they attempt 403s.

   This is exactly what the "account type and role disagree" flag was pointing at, and
   the flag is now bidirectional so it catches this case (it previously only fired for
   drivers). **Fixing the data is a decision for you** — see §8.

---

## 8. Left to do

**Needs your decision:**

- [ ] **Give NICCO's 37 admins the `SACCO Admin` role** (or decide they shouldn't have it).
      They can't edit anything today. One-line fix per user via `POST crew/{id}/role`,
      or a single backfill if you confirm they should all have it.
- [ ] **Delete the 1,971 orphan routes** from the 2026-08-07 import. Nameless, stage-less,
      owner-less. I left them rather than make a data-loss call inside a migration.
- [ ] **Backfill coordinates** for the 41 termini and the real places. Everything geographic
      is blocked on this — no route line, no stage marker, no geofence anchor.
- [ ] **Deduplicate `places`**: 1,980 rows, 120 distinct names, including case-variant pairs
      ('Town'/'town', 'Nairobi'/'nairobi'). Two routes referencing two rows for the same real
      place never compare equal, so segment search silently fails to match them.

**Engineering, not yet done:**

- [ ] `POST vehicles/users/add` bypasses `VehicleAssignment` entirely — no fraud signal, no
      queue cancellation, no notification to the displaced driver. Route it through the
      service or retire it.
- [ ] `findCrew()` applies no crew predicate: it resolves *any* user in the SACCO, so a
      Finance officer can be assigned to a bus.
- [ ] Two doors to suspension, one accountable: `POST crew/{id}` with `status:false` writes
      the flag with **no audit record**, while `POST sacco/members/{id}/state` audits it.
- [ ] `customerQRCodeSTKPush` takes a **client-supplied amount** and never calls
      `FareResolver` — it bypasses peak pricing and the "passenger never sets the price"
      rule that both booking flows enforce.
- [ ] A booking made off-peak and paid in cash during peak is floored at the **old** price.
      Correct (the quote is honoured), but worth stating out loud.
- [ ] `queues.amount` snapshots the flat fare at driver-join time and never re-prices.
- [ ] **Decide who may deactivate a route.** A SACCO now owns its routes and can set
      `routes.status` through `saccos/routes/build`, but `ResourceStateController` still
      treats routes as platform records and refuses a SACCO admin the same column through
      `POST .../routes/{id}/state`. Both cannot be right. Places are genuinely still shared,
      so their half of that rule is fine.

---

## 9. The sample route — DONE, and verified end to end

Created on production 2026-08-26 as **route 1973**, NICCO MOVERS LIMITED (sacco 4),
through the real endpoint as Makena Lisper (a NICCO SACCO Admin), so every validation,
permission and tenancy check actually ran. `201 Created`.

`Nairobi CBD → Ruiru Stage → Juja Stage → Thika Main Stage`, base fare **150/=**.
Four new places created with real coordinates — the first coordinates on the platform,
out of 1,980 rows.

Verified as passenger id 3 (the tenantless account that could read KES 78,223,947 that
morning):

| Step | Result |
|---|---|
| `book_a_ride/stops` | returns exactly the 4 real stops — none of the 936 "Boarding Terminal not provided" rows |
| `?from_place_id=<CBD>` | returns only the 3 stops *onward* of it |
| `book_a_ride/routes` CBD→Thika | finds route 1973 |
| the same call **reversed** | **0 routes** — the direction guard holds |
| `book_a_ride/fare` | `{ amount: 150, currency: "KES", is_peak: false }` |
| stage distances | 0 → 21.96 → 29.82 → **40.96 km** (Nairobi–Thika is ~41 km) |
| `Summary::count()` for that passenger | **0** — the money stays shut |

Those four passenger endpoints now return something real for the first time.
