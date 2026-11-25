# School bus tracking

A **school** is a SACCO with `saccos.kind = 'school'`. It reuses everything a SACCO has: admins and roles, buses (`vehicles.sacco_id`), drivers (phone + plate sign-in, `vehicle_users`), routes and stops, the driver app's go-live and broadcasting (`book_a_ride/location`, LiveRun, `vehicle_locations`, `VehicleMoved`), and monthly billing (base fee + per-bus fee, `InvoiceService`).

Parents watch a school bus the same way any passenger watches any bus: as a **saved bus** (below). There is no following, no follow code and no server-side list of who watches what.

## Rules

1. **School buses are never offered to passengers.** They never appear in the passenger list (`book_a_ride/queues`), nearby search, booking, seats, route catalogue, roadside reserve, or a passenger's whole-fleet `vehicle_locations` read, in either brand (`SchoolBuses::excludeFrom` and friends).
2. **Saved buses are open by plate. This is a product decision.** Any signed-in user who knows a bus's plate can find it and see where it is while it is live, school buses included. There is no code, invitation or approval step. **The plate is the only way in:** search hands back each bus's `key` (`SavedBuses::key`: the id plus an HMAC of it under `APP_KEY`), and `buses/live` and the bus channel take that key, never a bare id. Vehicle ids are sequential; reading by id would let anyone walk them and rebuild the whole school-bus fleet without knowing a plate.
3. **A position only while live.** Search returns no position. `buses/live` gives one only while the bus is broadcasting inside the live window (`VehicleLocationService::FRESH_SECONDS`, 2 minutes); otherwise `live` is `null`, never an old position.
4. **Brand wall for matatus, none for school buses.** A matatu is seen only from its own brand's app, the same wall as the passenger nearby map (`SavedBuses::brandWall`, mirroring `BrandScope`): the bus's `vehicles.brand` must be the app's brand, unless the caller is a superadmin, the bus is in the caller's own SACCO, or the caller is a bank and financed the bus. A school bus is seen from both apps (a parent runs whichever app they installed). The same wall holds on search, on live and on the bus channel.
5. **Only these fields leave.** Both endpoints build their answer field by field: id, plate, fleet number, operator (id, name, kind), position, run and route. Never a till, merchant code, financier, owner, driver or phone number.
6. **Tenant walls for the school.** `GET school/buses` acts only on the caller's own school (an explicit `kind = school` check); a superadmin sees every school.
7. **Alerts are the app's job.** The server sends no "bus approaching" push. While a saved bus is open on the map, the app works out its own arrival estimate from the position and `speed_kmh`. (`bus_approaching` stays in `NotificationType` only so notification rows already stored still read back.)

## Saved buses (mobile app, any passenger). Prefixes `api/v1/auth/...` and `api/auth/...`; bearer token required.

The app keeps the saved list on the phone. It finds a bus by plate once, stores the bus's `key` (with its plate, for display), then asks where its saved buses are by key. While one is open on the map it subscribes to that bus's channel and estimates its arrival itself. A key never changes for a bus; if one comes back under `missing` (bus switched off, or `APP_KEY` rotated), the app can find the bus by plate again.

| Method | Path | Query | Response |
|---|---|---|---|
| GET | `buses/search` | `plate` (required, 3–15 chars). Throttle 30/min, its own counter. | `200 {"buses":[Bus]}`. Exact normalised plate match only (`PlateSql`: "ksb101a" finds "KSB 101A"); buses in service only; max 5; no position. `[]` when nothing matches. `422 {"errors":{"plate":[...]}}` on bad input. |
| GET | `buses/live` | `keys=961-4f0c…,962-…` or `keys[]=…&keys[]=…`: 1–20 distinct keys from `buses/search` (repeats count once; case-insensitive). Throttle 120/min, its own counter (polling live never uses up search's limit). | `200 {"buses":[LiveBus],"missing":[key]}` in the order asked. `missing` = not a key this server made, unknown, out of service, or another brand's matatu (one list, so a caller cannot tell which). `422 {"errors":{"keys":[...]}}` when empty, more than 20, or not shaped like a key (a bare id included). One SQL query however many keys. |

"In service" = the vehicle is on (`vehicles.status`) and its operator, SACCO or school, is on (`saccos.status = 1`).

`Bus`:
```json
{"id": 961, "key": "961-4f0c2a9be81d7730c15e", "plate": "KSB 101A", "fleet_no": "GB1", "operator": {"id": 100013, "name": "Greenfield Demo Academy", "kind": "school"}}
```
`operator.kind` is `sacco` or `school`.

`LiveBus` = `Bus` plus:
```json
{
  "live": {"latitude": -1.1932, "longitude": 36.9353, "heading": 74, "speed_kmh": 28.5, "recorded_at": "ISO8601", "age_seconds": 4} | null,
  "trip": {"queue_id": 5521, "route": {"id": 88, "name": "Greenfield morning run"} | null} | null,
  "channel": "private-bus.961-4f0c2a9be81d7730c15e"
}
```

- `live` is non-null only while the bus is broadcasting a fresh fix (rule 3).
- `speed_kmh` is the bus's own speed over its last fixes, smoothed (`VehicleLocationService::speed`): `null` until two fixes a sensible time apart are seen; a GPS jump (over 120 km/h) is ignored. The app's estimate is distance over this speed; a sensible app default until it is known is city speed (20 km/h), never below a crawl (10 km/h).
- `trip` is the bus's open live run (a `live` queue, Pending or Active) that the live position belongs to; `null` when not live or not on a run.

Realtime: subscribe to private channel `bus.{key}` (Reverb, `/broadcasting/auth`, sending the app's `X-App-Key` as every API call does). It admits exactly whom `buses/live` answers for that key: a key this server made, a bus in service, behind the same brand wall (the brand is read off the auth request's `X-App-Key`, else its host; when neither names a brand, no matatu is admitted). A bare id, a forged key, an unknown or out-of-service bus admits nobody. At most 60 bus subscriptions per user per minute. It carries the same `vehicle.moved` event, with the same payload, as the trip channel (`vehicle_id`, `queue_id`, `plate`, `latitude`, `longitude`, `heading`, `recorded_at`), in the same single broadcast as its `trip.{queueId}` channel. A stale fix goes to the trip channel only.

Only buses someone is watching are broadcast: subscribing marks the bus watched for 2 hours (`SavedBuses::WATCH_SECONDS`, in the brand-independent `shared` Redis cache store), and every fix broadcast to it renews that. A bus nobody has opened costs no call to Reverb. A map left open on a bus that then stays silent for over 2 hours stops hearing it until it resubscribes (reopening the bus does that).

## School (school admin; same tenant rules as SACCO dashboards)

| Method | Path | Permission | Response |
|---|---|---|---|
| GET | `school/buses` | View Vehicles | School admin: `200 {"school":{"id","name"},"buses":[{"id","plate","fleet_no","school":{"id","name"},"live":bool}]}`. Superadmin: every school, `{"schools":[{"id","name","buses","live"}],"buses":[...],"total","page","per_page","last_page"}` (50 a page, by school then plate), or one school with `?school=`. |

The caller's SACCO not being a school gets `403 {"error":"This is for school accounts."}`.

Creating a school: `POST register/sacco` with `"kind":"school"`. Its first admin gets the **School Admin** role.

### What was removed (2026-11)

Following by follow code: `school-buses/search|following|{id}/follow|{id}/stops`, `school/buses/{id}/follow-code`, `school/buses/{id}/followers[/{follower}]`, the `vehicle_followers` table, `vehicles.follow_code`, the `school-follow` rate limiter, the `bus_approaching` push and its `booking.school_*` settings, and the `school-bus.{id}` channel. The web dashboard drops its followers view.

The `vehicle_followers` table and `vehicles.follow_code` column stay in the schema for one more release (expand/contract): the deploy migrates before it rolls the instances, and the previous release reads both while it still serves. Nothing in this release reads them; `follow_code` stays in `Vehicle::$hidden` meanwhile. The drop is parked in `database/contract/drop_school_bus_following.php` (with a 5 s `lock_timeout`): in the next release, move it into `database/migrations/` with that day's timestamp and remove `follow_code` from `Vehicle::$hidden`. `VehicleMoved` also keeps an always-false `toFollowers` property for that one release so queued broadcasts survive the rolling deploy in both directions; remove it then too.

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
5. Walk the flow (school admin, then a passenger finds bus KSB 101A by plate and reads it by its key, then the driver goes live and drives, then the passenger sees its position and speed):
   ```bash
   python3 scripts/demo/saved_bus_flow.py
   ```

Demo logins (password `password`):
- `admin@greenfield.school.test` (School Admin)
- `parent@greenfield.school.test` (an ordinary passenger account)
- driver: phone `0711000202` with plate `KSB 101A`

The location endpoint allows 60 pings a minute, so wait a minute between demo runs.
