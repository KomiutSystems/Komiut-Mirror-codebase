# SACCO Tenancy Scoping Audit

**Repo:** `komiut_new_portal_v2` (Laravel 13) · branch `staging`
**Scope of audit:** `app/Http/Controllers/Dashboard/**` (web) and `app/Http/Controllers/APIs/Dashboard/**` (API), plus `database/migrations/**` and `app/Models/**`.
**Actor model (decided):** `superadmin` (all SACCOs) · `admin` = SACCO admin (own SACCO only, permissions via spatie) · `driver` · `passenger`. Tenancy boundary = `sacco_id`.

> **Headline finding.** Tenancy is **not enforced anywhere on the server**. The near-universal pattern for "scoping" a dashboard/API list is `if ($request->sacco > 0) { $query->where('sacco_id', $request->sacco); }` — the SACCO id is a **client-supplied request parameter**, not derived from the authenticated user. This produces two failure modes simultaneously:
> 1. **Omit the param → no filter at all** → the query returns **every SACCO's rows** (unscoped read/write).
> 2. **Supply another SACCO's id → the filter happily uses it** → cross-tenant read/write (**IDOR**).
>
> `Auth::user()->sacco_id` *is* read in most controllers, but only to hydrate the sacco dropdown in the Blade view (`$sacco = Sacco::find(Auth::user()->sacco_id)`) — it is **almost never** used to constrain the actual data query. Spatie permissions gate **actions** (`View/Add/Edit Vehicles`), never **tenancy**, so a SACCO admin who legitimately holds `View Vehicles` can read *all* SACCOs' vehicles.
> There are **no policies** (`app/Policies` is empty), **no global scopes / model traits** (`addGlobalScope`, `booted()` not present in any model), and route-model-binding is not used — every mutation resolves rows with `Model::find($request->id)` / `findOrFail($request->id)` and no ownership check.

> **Blast-radius calibration — the outer boundary is the BRAND, enforced at the database layer.** The `brand` middleware (`app/Http/Middleware/ResolveBrand.php`) resolves a white-label brand from the `X-App-Key` header (mobile) or hostname (web), **fails closed with 404** on an unknown host/key, and switches to that brand's **own database** *before* auth (`ResolveBrand.php:34-45`). So every leak documented below is bounded to **"all SACCOs *within the caller's brand database*"** — **not** the entire platform across brands. That is still a full cross-tenant breach for a SACCO admin (who must see only their one SACCO), but it does not cross brands. Wording throughout should be read as "all SACCOs in this brand." `CheckUserStatus` / `CheckAPIUserStatus` only gate on `user.status` / `sacco.status` (`CheckUserStatus.php:22-31`) — they do **not** scope queries by sacco. The one filesystem-level exception (logs, §3) *does* cross brands.

---

## 1. Summary table — which tables carry `sacco_id` and how a SACCO is reached

`foreignIdFor(Sacco::class)` generates the `sacco_id` column. "Direct" = own `sacco_id` column; "Pivot/relation" = must be joined through another table.

| Model / table | Has `sacco_id`? | How the SACCO is reached |
|---|---|---|
| `User` / `users` | ✅ direct (nullable) | `users.sacco_id` (migration `2014_10_12_000000...:26`) — the user's *current* SACCO |
| `Vehicle` / `vehicles` | ✅ direct (nullable) | `vehicles.sacco_id` (`...create_vehicles_table:23`) |
| `SaccoUser` / `sacco_users` | ✅ direct | membership pivot (`user_id`,`sacco_id`,`end_date`) — historical/active membership |
| `SaccoVehicle` / `sacco_vehicles` | ✅ direct | vehicle↔sacco pivot |
| `SaccoRoute` / `sacco_routes` | ✅ direct | route↔sacco pivot |
| `SaccoTerminus` / `sacco_termini` | ✅ direct | terminus↔sacco pivot |
| `ExpenseFee` / `expense_fees` | ✅ direct (nullable) | `expense_fees.sacco_id` |
| `Point` / `points` | ✅ direct (nullable) | `points.sacco_id` |
| `PointSetting` / `point_settings` | ✅ direct (nullable) | `point_settings.sacco_id` |
| `MpesaPaymentSetting` / `payment_settings` | ✅ direct (nullable) | `payment_settings.sacco_id` |
| `VehicleUser` / `vehicle_users` | ✅ direct (nullable) | crew↔vehicle assignment, also stamped with `sacco_id` |
| `Subscription` / `subscriptions` | ✅ direct (`unsignedInteger`, no FK) | `subscriptions.sacco_id` |
| `Booking` / `bookings` | ❌ **none** | via `queue.vehicle.sacco_id` (relation) |
| `Parcel` / `parcels` | ❌ none | via `vehicle.sacco_id` |
| `Queue` / `queues` | ❌ none | via `vehicle.sacco_id` (also `terminus`→`sacco_termini`) |
| `Transaction` / `transactions` | ❌ none | via `vehicle.sacco_id` |
| `Summary` / `summaries` | ❌ none | via `vehicle.sacco_id` |
| `Cash`, `Mpesa`, `QrcodePayment`, `MpesaQrcodePayment` | ❌ none | via `transaction`→`vehicle.sacco_id` |
| `Crew` / `crews` | ❌ none | via `user`→`sacco_users` / `vehicle_users` |
| `Route`, `Place`, `RouteStage`, `Terminus` | ❌ none | shared reference data; SACCO ownership only via `sacco_routes` / `sacco_termini` pivots |
| `Seat`, `SeatArrangement` | ❌ none | via `vehicle` |
| `DirectLineClaim`, `VehicleExpenseAndFee`, `RedeemedPoint`, `PointTransaction` | ❌ none | via `vehicle.sacco_id` |

**Key structural consequence:** the high-value transactional tables (bookings, transactions, summaries, queues, parcels) have **no `sacco_id` of their own** — they can only be scoped by *joining through the vehicle*. Any enforcement mechanism must therefore support "reach sacco via `vehicle`/`queue.vehicle`", not just a flat column.

### How the current user's SACCO is determined — and the inconsistency

There are **four** competing notions of "which SACCO is this user":

1. **`users.sacco_id`** — read as `Auth::user()->sacco_id` in ~20 controllers, but only to load the view's sacco object. This is the natural "current SACCO" for an `admin`.
2. **`sacco_users` pivot** (`SaccoUser`, filtered by `end_date = null`) — the authoritative *membership* record; writes go here (e.g. `IndexApiController:1113`), but reads rarely consult it.
3. **`vehicle_users` pivot** (`VehicleUser`) — used by `DashboardController::getDashboard:47` to pick *which vehicles* a user sees (`VehicleUser::where('user_id', auth()->id())`). This is a *different* boundary (per-vehicle, not per-sacco).
4. **`terminus_users` pivot** (`TerminusUser`) — analogous per-terminus assignment.

These are **not reconciled**. `users.sacco_id`, the active `sacco_users` row, and the `vehicle_users`/`terminus_users` assignments can disagree, and different endpoints trust different ones. Server-side tenancy must pick **one** canonical source (recommend `users.sacco_id`, backfilled from the active `sacco_users` row) and use it everywhere.

### Is spatie actually used? — Yes, but only for action-gating

`spatie/laravel-permission` is genuinely wired in: `User` uses `HasRoles` (`app/Models/User.php:14`), controllers apply `permission:` middleware (e.g. `VehicleController.php:25` `permission:View Vehicles`) and inline `auth()->user()->can('Edit Vehicles')` checks throughout. A `Super Admin` role exists but is only referenced in **commented-out** bootstrap code (`DashboardController.php:29`) and an exclusion in search (`SearchController.php:22`). **No permission encodes tenancy** — permissions are `View/Add/Edit <Resource>`, brand-/platform-wide. So permission checks and tenancy scoping are orthogonal, and only the former is implemented.

---

## 2. CRITICAL — Cross-tenant IDOR (client-supplied id reaches another SACCO's row)

These are the most dangerous: a valid, authenticated SACCO admin (or any token holder with the relevant permission) reaches another SACCO's data by changing a request value. **Fix these first.**

### 2a. Mutations resolved by raw `find($request->id)` with no ownership check

Every write below loads the target row purely by the client's `id` and never verifies it belongs to the caller's SACCO.

- **`app/Http/Controllers/Dashboard/Sacco/SaccoUserController.php:67`**
  `$saccoUser = SaccoUser::find($id)->update(['sacco_id' => 0]);`
  Removes **any** membership row from **any** SACCO by id. Attacker evicts arbitrary members of other SACCOs. (Also a null-deref if id is bogus.)
- **`app/Http/Controllers/Dashboard/Sacco/SaccoRouteController.php:104`**
  `$saccoRoute = SaccoRoute::find($id)->delete();`
  Deletes **any** SACCO's route assignment by id.
- **`app/Http/Controllers/Dashboard/Users/UsersController.php:113`**
  `$user = User::findOrFail($request->id);` then updates name/phone/sacco/status.
  Edit **any** user in the platform, including reassigning their `sacco_id`.
- **`app/Http/Controllers/Dashboard/Profile/ProfileController.php:38`**
  `$user = User::findOrFail($request->id);` (editProfile) — updates arbitrary user by posted `id` rather than `Auth::id()`. (Contrast `:60`/`:74` which correctly use `Auth::user()->id`.)
- **`app/Http/Controllers/Dashboard/Vehicles/VehicleController.php:51`** and **`APIs/Dashboard/Vehicles/VehiclesAPIController.php:75`**
  `$vehicle = Vehicle::findOrFail($request->id);` — edit any vehicle by id; `sacco_id` is then set from `$request->sacco_id` / looked-up name, so a vehicle can be moved between SACCOs at will.
- **`app/Http/Controllers/Dashboard/Sacco/SaccoVehiclesController.php:97`** — `SaccoVehicle::findOrFail($request->id)`; line `:110` `Vehicle::where('id', $request->vehicle)->update(['sacco_id'=>$request->sacco])` re-assigns any vehicle to any SACCO.
- **`app/Http/Controllers/Dashboard/Crew/CrewController.php:89`** — `Crew::find($request->id)` (edit crew, no sacco check).
- **`app/Http/Controllers/Dashboard/Queues/QueuesController.php:146`** `Queue::findOrFail($request->id)`, **`:249`** `Booking::findOrFail($request->id)` — mutate any SACCO's queue/booking.
- **`app/Http/Controllers/Dashboard/Bookings/BookingsController.php:167`** — `Parcel::findOrFail($request->id)`.
- **`app/Http/Controllers/Dashboard/Vehicles/VehicleUsersController.php:98`** — `VehicleUser::findOrFail($request->id)` (also `:82` `User::find($request->user)`, `:84` `Vehicle::find($request->vehicle)` — assign any user to any vehicle).
- **`app/Http/Controllers/Dashboard/Vehicles/DirectLineClaimsController.php:98`** — `DirectLineClaim::findOrFail($request->id)`.
- **`app/Http/Controllers/Dashboard/Vehicles/VehicleSeatsController.php:39` & `:108`** — `Seat::findOrFail` / `SeatArrangement::findOrFail`.
- **`app/Http/Controllers/Dashboard/ExpenseAndFees/ExpenseAndFeesController.php:96`** — `VehicleExpenseAndFee::findOrFail($request->id)`.
- **`app/Http/Controllers/Dashboard/Settings/ExpenseAndFeeSettingsController.php:69`** `ExpenseFee::findOrFail`, **`Settings/MpesaPaymentSettings.php:95`** `MpesaPaymentSetting::findOrFail`, **`Settings/PointsSettingsController.php:100`** `PointSetting::findOrFail` — each edits another SACCO's settings by id (these models *have* `sacco_id`, so the check is straightforward once added).
- **`app/Http/Controllers/Dashboard/Routes/RouteStageController.php:47,:117,:145`**, **`Routes/TerminusSaccoController.php:81`** (`SaccoTerminus::findOrFail`), **`Sacco/SaccoMembersController.php:83`** (`SaccoUser::findOrFail`).
- **`app/Http/Controllers/Dashboard/Routes/TerminusUserController.php:84`** — `TerminusUser::findOrFail($request->id)`; same tenant-assignment IDOR class as `VehicleUser` — mutate any SACCO's terminus-user assignment by id.

### 2b. Reads/writes scoped **only** by a client-supplied `$request->sacco`

The list/index endpoints below filter on `$request->sacco` (attacker-chosen). Supplying another SACCO's id returns that SACCO's rows; the id is never checked against `Auth::user()->sacco_id`.

**API (`auth:sanctum`):**
- `APIs/Dashboard/Vehicles/VehiclesAPIController.php:37-38` — `where('sacco_id', $request->sacco)`
- `APIs/Dashboard/Bookings/BookingsAPIController.php:67-70`, `:78-80`, `getParcels :139-142`
- `APIs/Dashboard/Saccos/SaccoVehiclesAPIController.php:25-26` (list) and `:51`,`:64`,`:72` (assign vehicle to `$request->sacco`)
- `APIs/Dashboard/Saccos/SaccoMembersAPIController.php:24-25`, and `:49`,`:62`,`:70` (`User::update(['sacco_id'=>$request->sacco])` — add any user to any SACCO)
- `APIs/Dashboard/Saccos/SaccoRoutesAPIController.php:20-21`
- `APIs/Dashboard/Saccos/SaccoAPIController.php:21-22` (`where('id', $request->sacco)` — otherwise lists **all** saccos)
- `APIs/Dashboard/Vehicles/VehicleUsersAPIController.php:20-21`
- `APIs/Dashboard/Transactions/CashAPIController.php:35-37`, `MpesaAPIController.php:36-38`, `TransactionsAPIController.php:40-41` (`vehicles.sacco_id`)
- `APIs/Dashboard/Summaries/SummariesAPIController.php:43-44` (`vehicles.sacco_id`)
- `APIs/Dashboard/ExpenseAndFees/ExpenseAndFeesAPIController.php:35-37`
- `APIs/Dashboard/Settings/ExpenseAndFeesSettingsAPIController.php:22-23` (and `:46`,`:56` write)
- `APIs/Dashboard/QRCode/QRCodeApiController.php:51-53`
- `APIs/Dashboard/Users/UsersAPIController.php:25-26`
- `APIs/Dashboard/Queues/QueuesAPIController.php:52-54`
- `APIs/Dashboard/HomeAPIController.php:19` (`$sacco = $request->sacco > 0 ? $request->sacco : ""`)

**Web dashboard (`auth`):**
- `Dashboard/DashboardController.php:40` then filters at `:105,:114,:123,:133,:155,:174,:193,:213` all on the client `$request->sacco`
- `Dashboard/QRCode/QrCodeTransactionsController.php:34-36`
- `Dashboard/Points/PointsController.php:52-53`, `:89-91`
- `Dashboard/Crew/CrewController.php:29-31`
- `Dashboard/ExpenseAndFees/ExpenseAndFeesController.php:43-45` (`getExpenseAndFees`)
- `Dashboard/Bookings/BookingsController.php` (list methods around `:23`/`:82` build queries filtered by `$request->sacco`)
- `Dashboard/Transactions/{TransactionController,MpesaController,CashController}.php`, `Summaries/SummaryController.php`, `Settings/*` — all follow the same `Sacco::find(Auth::user()->sacco_id)` (view only) + `$request->sacco` (filter) shape.

---

## 3. UNSCOPED read/write (no filter at all → returns/mutates across ALL SACCOs)

The `$request->sacco` filters in §2b are guarded by `if ($request->sacco > 0)`. **When the parameter is absent or `0`, no `where` is applied and the endpoint returns every SACCO's rows *in the brand database* (see blast-radius note above).** So each §2b list endpoint is *also* an unscoped-read bug in its default (no-param) call. Concretely, calling these with no `sacco` param dumps every SACCO in the brand:

- `APIs/Dashboard/Vehicles/VehiclesAPIController.php:21-54` (`getVehicles`) → all vehicles, all SACCOs.
- `APIs/Dashboard/Bookings/BookingsAPIController.php` `getBookings`/`getParcels` → all bookings/parcels.
- `APIs/Dashboard/Transactions/{Cash,Mpesa,Transactions}APIController` → all transactions.
- `APIs/Dashboard/Summaries/SummariesAPIController` → all summaries.
- `APIs/Dashboard/Users/UsersAPIController` → all users platform-wide.
- `APIs/Dashboard/Saccos/SaccoAPIController:21-22` → all saccos when `sacco` empty.
- `APIs/Dashboard/Saccos/SaccoMembersAPIController`, `SaccoRoutesAPIController`, `SaccoVehiclesAPIController` → all pivot rows.
- `Dashboard/*` datatable feeders (`getTransactions`, `getSummaries`, `getPoints`, `getExpenseAndFees`, `getQRCodePayments`, `getCrews`, dashboard cards) → all rows when `sacco` empty.

Additional genuinely-unscoped reads/writes worth flagging:
- **`APIs/Dashboard/Saccos/SaccoVehiclesAPIController.php:72`** — `Vehicle::where('id', $request->vehicle)->update(['sacco_id'=>$request->sacco])`: no ownership check on either the vehicle or the destination sacco.
- **`Dashboard/Logs/LogController.php:15-28`** — reads `storage_path('logs/laravel.log')` wholesale and returns it to any user holding `View Logs`. The log file is a **single filesystem artifact shared across every SACCO *and every brand*** (it is not inside the per-brand DB). This is the one leak that **crosses brands**, and it exposes not just other tenants' activity but stack traces, request payloads, M-Pesa/payment data and PII. Treat as high-severity; scope or per-tenant-filter log access, or remove the endpoint.
- **`APIs/Dashboard/Search/SearchApiController.php:17-31`** (`getVehicle`) — looks up a vehicle by `till_number` with **no sacco scoping**; returns any vehicle in the brand (plus its seat arrangement) by till number. Cross-SACCO vehicle-metadata disclosure.
- **`Dashboard/Search/SearchController.php:35-40`** (`searchTermini`) — returns all termini in the brand unscoped (termini are shared reference data with no `sacco_id`, so likely acceptable — see §4). Contrast the *other* methods in this controller, which are correctly scoped (below).

### Endpoints that ARE correctly scoped (use as the reference pattern)
- **`Dashboard/Search/SearchController.php`** — `searchSaccos:29-31`, `searchUser:50-52`, `searchPaybills:65-67` all do `if (Auth::user()->sacco_id > 0) ->where('sacco_id', Auth::user()->sacco_id)`. This is the correct pattern and the cleanest in-repo reference. (It also underlines the inconsistency: Search uses the *authed* sacco; the datatable feeders for the same resources use the *client* `$request->sacco`.)
- **`APIs/Dashboard/Queues/QueuesAPIController.php:236`** — `SaccoTerminus::...->where('sacco_id', Auth::user()->sacco_id)->get()` — the one API list that scopes to the authed user's SACCO.
- **`Dashboard/ExpenseAndFees/ExpenseAndFeesController.php:131-132`** — `searchExpenseAndFees` does `if (Auth::user()->sacco_id > 0) $expense_and_fees->where('sacco_id', Auth::user()->sacco_id)`. Note the **same controller's** `getExpenseAndFees:43-45` uses the *client* `$request->sacco` — a direct illustration of the inconsistency.

### View-only shells (no data leak in the controller; the data feed is the concern)
- **`Dashboard/Vehicles/VehiclesLocationController.php:15-18`** returns only the Blade view with `$sacco = Sacco::find(Auth::user()->sacco_id)`; the live-position data is fetched by a separate datatable/API feed (the vehicles/location endpoints), which must be scoped there — the controller itself leaks nothing.

---

## 4. NEEDS CONFIRMATION

- **Intended superadmin cross-SACCO access.** The `$request->sacco` pattern may have originally been the *superadmin's* way of pivoting between SACCOs from a single dashboard. If so, the design is "trust the client to send the right sacco" — fine for a platform superadmin *within a brand*, catastrophic for a SACCO admin. Confirm which endpoints are superadmin-only; those must **ignore** `$request->sacco` for non-superadmins and force `Auth::user()->sacco_id`. (Note: the superadmin here is a *per-brand* superadmin — the brand DB boundary already caps their reach to one brand.)
- **`searchTermini` / `searchRoles` unscoped-ness.** `SearchController.php:35-40` (termini) and `:20-23` (roles) return brand-wide results. Termini/routes are shared reference data (no `sacco_id`); roles are brand-wide by design. Confirm these are intended to be brand-global and not sacco-scoped.
- **`subscriptions.sacco_id`** is an `unsignedInteger` with **no foreign key** (`create_subscriptions_table:16`); `SubscriptionController` accepts `sacco_id` from the request (`:20,:32,:70`). Confirm whether subscription management is superadmin-only (it reads like platform billing).
- **`bookings` filter on a non-existent column.** `BookingsAPIController.php:79` does `->where('sacco_id', $request->sacco)` directly on the `bookings` query, but `bookings` has **no `sacco_id` column** (§1). This clause is either dead, erroring, or silently mis-filtering — verify at runtime. (The parallel `:67-70` correctly goes through `queue.vehicle`.)
- **Shared reference data.** `Route`, `Place`, `Terminus`, `Seat` have no `sacco_id` and appear intentionally global (a route/place exists once, SACCOs subscribe via `sacco_routes`/`sacco_termini`). Confirm these should stay brand-global and are *not* expected to be tenant-scoped — otherwise `RouteController`, `PlaceController`, `TerminusController` list endpoints leak nothing tenant-specific by design.
- **`DashboardController::getDashboard` vehicle set** (`:47`) is scoped by `vehicle_users` (per-user vehicles), not by `sacco_id` — a third boundary. Confirm this is intended for drivers vs. admins.
- **`getPassengerBooking` (`BookingsAPIController.php:105-108`)** scopes to `user_id` only when the caller *lacks* `View Passengers`; holders of the permission can fetch any booking by id across SACCOs — confirm intended.

---

## 5. Recommended enforcement mechanism for THIS codebase

Given (a) no policies/global scopes exist today, (b) the hottest tables (`bookings`, `transactions`, `summaries`, `queues`, `parcels`) have **no `sacco_id`** and must be reached via `vehicle`, and (c) tenancy is a per-request concern of the authenticated user — a **single global scope keyed on the authed user's SACCO is the cleanest fit**, with a small amount of per-model configuration for the "reach via relation" cases:

1. **Canonical current-SACCO resolver.** Add one accessor, `User::currentSaccoId()`, returning `users.sacco_id` (backfilled from the active `sacco_users` row). Use it everywhere; stop trusting `$request->sacco` for non-superadmins.
2. **`BelongsToSacco` global scope + trait.** Apply to models with a direct column (`Vehicle`, `ExpenseFee`, `Point`, `PointSetting`, `MpesaPaymentSetting`, `VehicleUser`, `SaccoUser`, `SaccoVehicle`, `SaccoRoute`, `SaccoTerminus`). The scope adds `where('sacco_id', $user->currentSaccoId())` automatically on every query, closing both the "no param" and "wrong param" holes at once. For the relation-reached models (`Booking`, `Transaction`, `Summary`, `Queue`, `Parcel`, …) let the trait declare a path (e.g. `protected $saccoVia = 'queue.vehicle'`) and have the scope emit the corresponding `whereHas`. This is far less error-prone than fixing ~40 `where('sacco_id', $request->sacco)` sites by hand and it defends future controllers by default.
3. **Ownership check on the mutation path — mostly subsumed by step 2.** `Eloquent\Model::find()` / `findOrFail()` **do apply global scopes**, so once the `BelongsToSacco` scope from step 2 is in place, the §2a mutations on **direct-column** models (`Vehicle`, `SaccoUser`, `SaccoVehicle`, `SaccoRoute`, `SaccoTerminus`, `ExpenseFee`, `PointSetting`, `MpesaPaymentSetting`, `VehicleUser`, `TerminusUser`, …) are neutralized automatically — a wrong-SACCO id resolves to `null` → 404, no extra code. **Residual §2a risk therefore concentrates on:** (i) relation-reached models where the scope is a `whereHas` (`Booking`, `Transaction`, `Summary`, `Queue`, `Parcel`) — verify the scope actually constrains a `find`, or add an explicit `authorizeSacco()` there; and (ii) any raw `DB::` query or `->withoutGlobalScopes()` call, which bypasses the scope entirely. In short, steps 2 and 3 are **largely one fix, not two** — do not hand-patch all ~30 `findOrFail` sites if the global scope covers them.
4. **Superadmin bypass — exactly one place.** The global scope must be skipped for superadmins: `if ($user->hasRole('Super Admin')) return;` at the top of the scope's `apply()`, and *only* superadmin endpoints may honour an explicit `$request->sacco` to pivot between tenants. This is the single sanctioned bypass; nothing else should read `$request->sacco` for filtering.

**Why not policies alone:** policies excel at per-row *authorization* (the §2a mutations) but do nothing for *list* queries — they can't stop an unscoped `->get()` from returning other SACCOs' rows. Use policies (or the `authorizeSacco` helper) as the second layer for mutations, on top of the global scope that handles reads. **Why not a base-query trait you must remember to call:** the current bugs exist precisely because scoping was opt-in per query; a global scope makes correct behaviour the default and leaking the exception.

**Enforcement checklist for the follow-up pass:** fix §2a (ownership on mutations) and §2b/§3 (replace `$request->sacco` with `currentSaccoId()`) together — fixing only one still leaves the other as a live cross-tenant path.

---

## 6. ENFORCEMENT STATUS (implemented on this branch)

**Mechanism shipped:** `App\Models\Concerns\BelongsToSacco` trait + `App\Models\Scopes\SaccoScope` global scope, keyed on `User::currentSaccoId()`. The scope no-ops for: unauthenticated requests (webhooks/callbacks), superadmins (`User::isSuperAdmin()` — type or `Super Admin` role), and users with no home SACCO (passengers/drivers). Verified by 7 denial tests in `tests/Feature/Tenancy/SaccoScopeTest.php`.

**Scoped models — direct `sacco_id`:** Vehicle, ExpenseFee, Point, PointSetting, MpesaPaymentSetting, VehicleUser, SaccoUser, SaccoVehicle, SaccoRoute, SaccoTerminus, Subscription. This auto-neutralizes the §2a `find($request->id)` IDORs on those models (a wrong-SACCO id resolves to null → 404).

**Scoped models — relation-reached (`$saccoVia`, via `whereHas`):** Booking (`queue.vehicle`), Queue/Transaction/Summary/Parcel (`vehicle`). Empirically confirmed that `whereHas` constrains `find()` (the audit's §3.3 uncertainty) — `Booking::find(otherSaccoId)` returns null.

**User surface — fixed by hand (User model cannot be globally scoped):**
- `ProfileController::editProfile` now edits `$request->user()`, not `$request->id`.
- `UsersAPIController::getUsers` scopes to the authed user's SACCO for non-superadmins.
- `UsersController::editUser` guards target ownership + forces the assigned SACCO for non-superadmins.
- `LogController::index` restricted to superadmins (the one cross-brand leak).

**NAMED RESIDUALS (not yet closed — deliberate follow-ups):**
1. **Write-side reassignment:** the scope constrains SELECTs, not SET values — a non-superadmin can still move *their own* vehicle/member to another SACCO via `update(['sacco_id' => …])` on the create/assign paths not yet hand-guarded (SaccoVehiclesController/SaccoMembers assign flows). Lower severity (own→other), but open.
2. **Fail-open on null `sacco_id`:** an admin mis-provisioned with `sacco_id = null` is exempted (sees all). Close via the `type` backfill + keying superadmin/staff on `type`.
3. **Remaining §2a controllers not individually re-audited post-scope:** the scope covers the model-level find() IDORs, but per-controller assign/create writes that set `sacco_id` from request input on non-scoped-return paths should be swept in a dedicated pass.
4. **SaccoAPIController list** still returns saccos by `$request->sacco`; Sacco (the tenant root, keyed by `id` not `sacco_id`) is not covered by the trait — scope its list to the authed user's own SACCO for non-superadmins.
