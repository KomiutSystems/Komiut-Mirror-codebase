# School bus tracking

A **school** is a SACCO with `saccos.kind = 'school'`. It reuses everything a SACCO has: admins and roles, buses (`vehicles.sacco_id`), drivers (phone + plate sign-in, `vehicle_users`), routes and stops, the driver app's go-live and broadcasting (`book_a_ride/location`, LiveRun, `vehicle_locations`, `VehicleMoved`), and monthly billing (base fee + per-bus fee, `InvoiceService`).

A **parent** is an ordinary passenger account in either app (Komiut or 2Safiri). A parent finds a school bus by plate, follows it with the bus's **follow code** that the school gives them, and then sees it live and is told when it nears their stop.

## Rules (security, privacy)

1. **School buses are never offered to passengers.** They never appear in the passenger list (`book_a_ride/queues`), nearby search, booking, seats, or route catalogue, in either brand.
2. **Location only for followers, and only while live.** Search returns no position. A follower sees a position only while the bus is broadcasting inside the live window (`VehicleLocationService::FRESH_SECONDS`).
3. **The follow code is a shared secret.** It's hidden from every implicit serialization (`Vehicle::$hidden`), not mass-assignable, and leaves the API only through `GET school/buses`, for the school's own staff. Follow attempts are throttled (`throttle:school-follow`). A wrong code and an unknown bus give the same answer.
4. **Children's data is minimal.** We store no child names. A follower row is parent ↔ bus ↔ optional stop. The school sees followers' names and masked phones only, and can remove any follower or rotate the code, which can also revoke everyone.
5. **Tenant walls.** School admin endpoints act only on the caller's own school (SaccoScope plus an explicit `kind = school` check). Parent endpoints read the bus cross-tenant and cross-brand, deliberately, but expose only the fields listed below.
6. **Every follow and unfollow, school removal and code rotation is audit-logged** (`AuditLogger`).

## API (mobile app). Prefixes `api/v1/auth/...` and `api/auth/...`; bearer token required.

### Parent

| Method | Path | Body / query | Response |
|---|---|---|---|
| GET | `school-buses/search` | `plate` (required, 3–15 chars) | `200 {"buses":[{"id","plate","school":{"id","name"},"following":bool}]}`. Exact normalised plate match only (`PlateSql`); no position; school buses only; max 5. |
| POST | `school-buses/{vehicle}/follow` | `code` (required); optional `stop_place_id` OR `stop_latitude`+`stop_longitude` | `201 {"bus": FollowedBus}`. A wrong code, unknown bus or non-school bus gets `404 {"error":"No school bus matches that plate and code."}`. Already following: `200` (idempotent; updates the stop if given). Max `VehicleFollower::MAX_PER_USER` gives `422`. |
| PATCH | `school-buses/{vehicle}/follow` | stop fields as above, or `"stop": null` to clear | `200 {"bus": FollowedBus}`. Not following: `404`. |
| DELETE | `school-buses/{vehicle}/follow` | – | `204`. Not following: `404`. |
| GET | `school-buses/following` | – | `200 {"buses":[FollowedBus...]}` |

`FollowedBus`:
```json
{
  "id": 717, "plate": "KCW 563E",
  "school": {"id": 2, "name": "Greenfield Academy"},
  "stop": {"place_id": 12, "name": "Kahawa West", "latitude": -1.18, "longitude": 36.92} | null,
  "live": {"latitude": -1.2, "longitude": 36.9, "heading": 74, "speed_kmh": 28.5, "recorded_at": "ISO8601", "age_seconds": 12, "distance_to_stop_m": 940, "eta_to_stop_seconds": 119} | null,
  "trip": {"queue_id": 901, "route": {"id": 5, "name": "Morning run"}} | null,
  "channel": "private-school-bus.717",
  "followed_at": "ISO8601"
}
```

Realtime: subscribe to private channel `school-bus.{vehicleId}` (Reverb, `/broadcasting/auth`). Only followers of that bus, plus its crew, are admitted. It carries the same `vehicle.moved` event the trip channel carries. Push notification `bus_approaching`, once per run, when the bus is about 2 minutes from the follower's stop (`booking.school_approach_seconds`). The estimate is distance over the bus's own smoothed speed (`vehicle_locations.speed_kmh`, 20 km/h until known, never below 10, never more than 3 km away). Title "Your school bus is 2 minutes away", body "Your school bus KCW 563E is about 2 minutes from your stop." The app can read the body aloud for voice.

### School (school admin; same tenant rules as SACCO dashboards)

| Method | Path | Permission | Response |
|---|---|---|---|
| GET | `school/buses` | View Vehicles | School admin: `200 {"school":{"id","name"},"buses":[{"id","plate","fleet_no","school":{"id","name"},"follow_code","followers":int,"live":bool}]}`. Superadmin: every school, `{"schools":[{"id","name","buses","live","followers"}],"buses":[...],"total","page","per_page","last_page"}` (50 a page, by school then plate), or one school with `?school=`. |
| POST | `school/buses/{vehicle}/follow-code` | Edit Vehicles | body `revoke_followers` bool. `200 {"bus":{"id","plate","follow_code"},"revoked":int}` |
| GET | `school/buses/{vehicle}/followers` | View Vehicles | `200 {"followers":[{"id","name","phone_masked","stop":{...}|null,"followed_at"}]}` |
| DELETE | `school/buses/{vehicle}/followers/{follower}` | Edit Vehicles | `204` |

A bus outside the caller's school gets `404`. The caller's SACCO not being a school gets `403 {"error":"This is for school accounts."}`.

Creating a school: `POST register/sacco` with `"kind":"school"`. Its first admin gets the **School Admin** role. Every bus added to a school is issued a follow code automatically.

## Run it locally (Docker)

Uses the local test Postgres container (`komiut-test-pg`, port 54321) and the `komiut:php8427` image. Nothing touches production.

1. Create a demo database:
   ```bash
   docker exec komiut-test-pg psql -U komiut -d komiut_test -c "create database komiut_demo"
   ```
2. Create a `.env` (it's gitignored). Set: `APP_KEY` (any base64 key), `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=54321`, `DB_DATABASE=komiut_demo`, `DB_USERNAME=komiut`, `DB_PASSWORD=secret`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=log`, `MAIL_MAILER=log`, `KOMIUT_HOST=localhost`, `KOMIUT_HOSTS=127.0.0.1`, `KOMIUT_APP_KEY=demo-komiut-app`. **Move it aside before running the test suite**: it changes brand hosts the tests rely on.
3. Migrate and seed (`D` = `docker run --rm --network host -u $(id -u):$(id -g) -v $PWD:/var/www -w /var/www --entrypoint php komiut:php8427`):
   ```bash
   $D artisan migrate --force
   for s in GenderSeeder PermissionSeeder RoleSeeder QueueStatusSeeder SchoolDemoSeeder; do $D artisan db:seed --class=$s --force; done
   ```
4. Serve the API on port 8011:
   ```bash
   docker run -d --name school-demo --network host -u $(id -u):$(id -g) -v $PWD:/var/www -w /var/www --entrypoint php komiut:php8427 artisan serve --host=127.0.0.1 --port=8011
   ```
5. Walk the flow (school admin, then parent search/follow, then driver driving towards the stop, then the 2-minute push):
   ```bash
   python3 scripts/demo/school_parent_flow.py
   ```

Demo logins (password `password`):
- `admin@greenfield.school.test` (School Admin)
- `parent@greenfield.school.test` (passenger)
- driver: phone `0711000202` with plate `KSB 101A`

The location endpoint allows 60 pings a minute, so wait a minute between demo runs.
