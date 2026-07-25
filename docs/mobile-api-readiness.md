# Mobile API Readiness Audit

**Repo:** `komiut_new_portal_v2` (Laravel 13) · branch `staging`
**Compared against:** `new-safetek` (C# microservices — FleetManagement/IAM/Yarp)
**Scope:** the passenger + driver surface the Flutter app will consume.

Verdict per area: ✅ ready · ⚠️ works but has a gap · ❌ missing.

---

## 1. Passenger seat availability — ✅ (the pick-as-you-go logic is genuinely good)

`GET /api/auth/book_a_ride/seats` → `BookARideSeatController::getVehicleSeats`

```
?bus_id=3&id=7&from_id=12&to_id=18&booking_id=41
→ { "seats": { …full layout, seat.seat_arrangements… }, "booked": [ {"seatId": 4}, … ] }
```

**Yes — the app can fetch the seat map AND which seats are already taken, in one call.**

The occupancy rule is `App\Services\Booking\SegmentSeatAvailability`, and it is segment-aware:

- A seat is only occupied for the **span** a passenger rides. Two bookings share one physical seat when their pickup→dropoff intervals don't overlap (A: CBD→Ruiru, B: Juja→Thika).
- Half-open interval overlap on route-stage distance: `[a,b) ∩ [c,d) ≠ ∅ ⇔ a < d ∧ c < b`.
- Honours an **unpaid-hold window** (`config('booking.hold_minutes')`, default 10) — an unpaid booking holds its seat for 10 min, then frees up.
- Falls back to the whole route `[0, ∞)` when a segment can't be resolved — fails safe, never oversells.
- `booking_id` excludes a booking's own seats when amending.
- Same rule `addBooking` enforces at confirm, so a seat shown free won't be rejected on submit.

### ⚠️ The one real gap: this is **not** realtime

`app/Events/` contains only `BookingPaid` and `VehicleMoved`. There is **no seat-changed broadcast**. Two passengers on the seat-picker simultaneously will not see each other's selection until they re-fetch.

Options, cheapest first:
1. **Poll** `book_a_ride/seats` every ~3–5s while the picker is open (works today, zero backend change).
2. Add a `SeatsChanged` Reverb event fired from `addBooking` on the `queue.{id}` channel — the infrastructure (Reverb + `VehicleMoved`) already exists, so this is small.

The 10-minute hold means a race only loses the seat if two people confirm within the same instant; the confirm-side check is authoritative either way.

---

## 2. Driver joins a queue — ⚠️ exists, but wrong shape for mobile

Laravel has `POST /api/auth/queues/add` (`QueuesAPIController::addQueue`), but it is **dispatcher-shaped, not driver-shaped**. The client supplies everything:

```
{ id, vehicle, terminus, status, route, choice, schedule_time, amount }
```

C# is driver-shaped — the client sends only the terminus, and the server derives the rest from the token:

```csharp
public sealed record JoinQueueCommandRequest(Guid TerminusId);
public sealed record JoinQueueCommand(Guid PersonnelId, Guid TerminusId);
```

Consequences of the Laravel shape for a mobile driver app:
- The driver must know and send a `vehicle` id — but a driver's vehicle is already known from their `VehicleUser` assignment (`DriverAuthController` even returns it at login).
- The driver sends `amount` — **the fare is client-controlled**. The SACCO's configured fare (`SaccoRoute` / `RouteFare`, which `FareAPIController` already resolves server-side) should win.
- The driver sends `status` (a `queue_status_id`) — the app shouldn't need to know status-table primary keys.
- `vehicle` is validated with `exists:vehicles,id`, which **bypasses Eloquent global scopes** — so it isn't SACCO-scoped.

### ⚠️ Side effect I introduced earlier this session — worth tightening

To let drivers call `completeQueue`, I granted `Edit Queues` to the Driver bundle. `addQueue`'s inline check is `can('Add Queues') || can('Edit Queues')`, so **drivers can now also call `addQueue`** — i.e. queue any vehicle in the brand at any fare. That is wider than intended.

Recommended fix (also closes the mobile gap):

```
POST /api/auth/queues/join     { terminus_id }        // vehicle + driver from token
POST /api/auth/queues/exit                            // mirrors C# queue/exit
```
…with a narrower permission for the driver path, and `addQueue` left to `Add Queues` only.

---

## 3. Driver starts a trip — ❌ missing

C#: `PUT /api/trips/start-trip`, `StartTripCommand(PersonnelId, TerminusId)`.

Laravel has **no start-trip endpoint**. The data model conflates queue and trip: `Queue` carries `start_time`/`end_time`/`queue_status_id`, and the only transitions are:

- created via `addQueue` with a client-supplied `status` id, and
- `POST queues/complete/queue` → sets status `Completed` (the end).

So there is no server-owned Pending→Active transition. The app would have to re-`POST queues/add` with a different `status` id to "start", which also re-runs the whole create/validate path.

Also note `TripManifestController::manifest` and `pickPassenger` already exist for the in-trip flow — the missing piece is only the explicit start.

---

## 4. Google Maps — ❌ not integrated at all

There is **no** Google Maps usage anywhere: no Maps/Directions/Places/Geocoding call, no API key, no polyline decoding. Grep for `google`, `maps.googleapis`, `geocod`, `polyline` across `app/`, `config/`, `routes/` returns nothing.

Geo work is done with two **inconsistent hand-rolled** implementations:

| Where | Method | Quality |
|---|---|---|
| `VehicleLocationService` (nearby vehicles) | proper haversine, `EARTH_KM = 6371.0088` | ✅ correct, portable, deliberate |
| `RouteAPIController:131,164` + `RouteStageController:60,125` | equirectangular approximation — `69.1` mi/deg, `cos(lat/57.3)`, ×1.609344 | ⚠️ crude, straight-line, **duplicated in 4 places** |

Why the second one matters more than it looks: **route-stage `distance` is the axis the whole pick-as-you-go seat model runs on** (`SegmentSeatAvailability::interval()`), and it also feeds fare logic. It is straight-line distance from the route origin, so on a route that curves or doubles back, two stops can be ordered wrongly — which would make two genuinely-overlapping segments look non-overlapping and **double-sell a seat**.

Recommendations:
- Short term: collapse the 4 duplicated formulas into one helper and switch them to the haversine already in `VehicleLocationService`.
- Medium term: store an explicit `sequence`/ordinal on `RouteStage` and use *that* (not distance) for interval ordering — road-following distance from Directions API is the "correct" fix, but a monotonic sequence removes the correctness risk without any external dependency or cost.
- Mapping in the app itself (drawing the route, live vehicle marker) can be done client-side against `book_a_ride/nearby` + `VehicleMoved` broadcasts; the backend does not need Maps for that.

---

## 5. Passenger profile — ⚠️ admin-shaped, thin for a passenger

`POST /api/auth/user` returns:

```
user (with roles, gender, sacco), permissions[], vehicle_users[], termini[], sacco, crew
```

That payload is built for a **dashboard/crew** user. For a passenger, `permissions`, `vehicle_users`, `termini`, `sacco` and `crew` are all empty or irrelevant, and what a passenger app actually needs is absent:

- no saved/favourite places (home, work)
- no saved payment identity (the M-Pesa phone used for STK)
- no ride-history summary (loyalty exists separately at `book_a_ride/loyalty/summary`)
- no notification preferences / device token surface on this payload (`FirebaseToken` exists as a model but isn't part of profile)
- no emergency contact / next-of-kin

Editable fields today are only `firstname`, `lastname`, `dob`, `gender` (`profile/edit`), plus password and picture.

### ❗ New IDOR found in `AuthController::user`

```php
if ($request->crew_id > 0) { $crew = Crew::find($request->crew_id); }
```

No ownership check. Any authenticated user can pass an arbitrary `crew_id` and read that crew member's **phone, email, id_number, badge_number**. (`password` is `$hidden`, so that isn't exposed.) This is the same family as the two IDORs fixed in `e17f8fb` and should be bound to the caller's own crew record the same way.

---

## 6. M-Pesa Daraja test keys — ❌ none exist in the C# codebase

They are genuinely not there, by design:

- `FleetManagement/appsettings.json` → `Daraja` block holds only `Environment: "Sandbox"`, `SandboxBaseUrl`, `ProductionBaseUrl`, `CallbackBaseUrl: ""`. **No consumer key / secret / passkey.**
- Credentials are **per-SACCO, stored in the DB, encrypted at rest** — `CreateMpesaCredentialCommandHandler` wraps every one in `_secretProtector.Protect(...)`, and `InitiateStkPush` / `StkPaymentReconciler` / `RegisterC2bUrls` all `Unprotect(...)` at use.
- The only `174379` hits are in `safetek-admin-frontend/db.json`, a json-server **UI mock fixture** (`tillNumber: "123456"`) — not usable credentials.

So there is nothing to copy. To test the payment flow, create a sandbox app at **developer.safaricom.co.ke** and use its Consumer Key + Secret; Safaricom publishes the sandbox shortcode (`174379`) and Lipa-na-M-Pesa passkey openly.

Put them in SSM (`/komiut/prod/*`) or a local `.env` — **not in git**, matching both codebases' existing practice.

Note our Laravel side already matches the C# reliability design: `DarajaClient` + `ReconcileMpesaPayments` (every 2 min) is the same "poll if the callback is lost" safety net the C# `StkPaymentReconciler` implements and that `MPESA-STK-MOBILE-API.md` documents as the ~8s fallback.

---

## Summary — what to build before the app starts

| # | Item | Severity |
|---|---|---|
| 1 | `AuthController::user` crew IDOR (arbitrary crew PII read) | **security** |
| 2 | Narrow driver's `Edit Queues` so it can't reach `addQueue` (queue any vehicle / any fare) | **security** |
| 3 | `POST queues/join` + `queues/exit` — terminus-only, vehicle from token | mobile blocker |
| 4 | `POST trips/start` — server-owned Pending→Active | mobile blocker |
| 5 | Server-authoritative fare on queue join (stop trusting client `amount`) | correctness |
| 6 | `RouteStage.sequence` for segment ordering (removes seat double-sell risk) | correctness |
| 7 | `SeatsChanged` broadcast (or document polling) | UX |
| 8 | Passenger-shaped profile payload + saved places / payment phone | product |
| 9 | Collapse 4 duplicated distance formulas onto the existing haversine | cleanup |
