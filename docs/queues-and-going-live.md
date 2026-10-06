# Join Queue and Go live are separate — driver app and dashboard spec

**For:** the driver app (Flutter) and KomiutWebApp `/queues`
**Backend:** `feat/queues-separate-from-going-live` (live once merged to `staging`)

## The rule

| | **Join Queue** | **Go live** |
|---|---|---|
| The driver picks | a **stage** (any terminus of their SACCO) | a **route** A – B |
| Route involved | none | the one picked |
| Bookable by passengers | **no** | **yes**, along that route |
| Backend row | `queues` row with `kind: "stage"`, `route_id: null` | `queues` row with `kind: "live"` (the run) |

Neither one creates, reuses or blocks the other:
- A bus can wait at a stage without being live.
- A bus can be live without having queued anywhere.
- A bus can be both at once: two rows.

**Why:** on 2026-10-06, KDN 458N joined at Ambassadeur after a Nairobi CBD – Thika run. The app sent the first route out of that stage, so the bus was queued and offered on Ambassadeur – Alsops.

## Driver app

### Join Queue

`POST queues/join`

```json
{ "terminus_id": 57 }
```

- Send the terminus only. The `route_id` that today's app sends is accepted and **ignored**. Delete `resolveRouteIdForTerminus` from the join path.
- Any stage in `queues/geofence` → `termini` can be joined, not only a route's origin.
- `201` returns the new place in line. `200` returns the existing place when the bus is already in line at that stage.
- `409` means the bus is in line at **another** stage. Leave it first.
- The response's `queue` now carries `kind: "stage"` and `route_id: null`, and `amount` is `0`. A place in a line is not sold.
- `queues/exit` and `trips/start` act on the stage queue only. They never touch the live run.

### Go live

`POST book_a_ride/location`, every ping:

```json
{ "latitude": -1.28, "longitude": 36.82, "fixed_at": "…", "route_id": 1973 }
```

- **Send `route_id` on every ping while live**, whether or not the bus is in a queue. This is the change that matters.
  - Today `goLive()` drops the route whenever `activeTripProvider` has a trip (`routeId: trip == null ? routeId : null`).
  - With the old coupling, that made a bus that went live from a stage broadcast against the stage queue.
- The first ping on a route opens the run, and its id comes back as `queue_id`. Later pings on the same route reuse it. A ping on a **different** route is a change of route:
  - if nobody is booked on the old run, it ends and a new one starts;
  - if passengers are still waiting on it, the old run stands until the crew end it.
- `queue_id` may still be sent. It is only honoured when it names this bus's open **live** run. A stage queue's id is ignored, and so is an ended run's id.
- A ping with neither a route nor a live run puts the bus on the map and offers it to nobody.
- **Do not call `queues/join` from Go live.**
  - The "Which route are you running?" sheet goes straight to broadcasting.
  - `GoLiveController.joinQueue(route)` should be deleted.

### What `driver/trip` returns now

```json
{
  "trip":  { "queue_id": 812, "kind": "live",  "route": "Nairobi CBD - Thika", "status": "Active", … },
  "stage": { "queue_id": 811, "kind": "stage", "position": 2, "terminus": "Nairobi CBD", "status": "Pending", … }
}
```

- `trip` is the live run. If there is none, it is a stage queue the bus has **departed** from (an app that departs without going live). Otherwise `trip` is `null`.
- **A bus still waiting in line has `trip: null`.** The place in line is in `stage`, and also, as before, in `queues/geofence` → `queue`.
- `driver/trip/end` ends the live run. If the bus departed a stage, it ends that stage queue in the same act, and the two count as **one** trip in earnings, trip history and SACCO reports. A bus still waiting in a line keeps its place.
- `trips/bookings`, `driver/bookings/{id}/cash` and `driver/bookings/{id}/mark` work on the live run's passengers.

### Compatibility with the app in drivers' hands today

| Flow | Today's app after this deploy |
|---|---|
| Join Queue at a stage | Works. The route it sends is ignored. |
| Go live with no queue | Works. Bookable on the chosen route. |
| Go live while **waiting** in a line | Works. `trip` is null, so the app sends `route_id`. |
| Go live **after departing** a stage (no run yet) | Shows on the map but **not bookable**: the app sends the stage queue's id and drops the route. Fixed by sending `route_id` on every ping (above). |
| Depart, end trip without going live | Works as before. |

## Dashboard `/queues`

- `GET queues` lists **stage lines** by default.
  - `?kind=live` lists the live runs.
  - `?kind=all` lists both.
- A stage row created from now on has `route: null` and `route_id: null`.
  - Show the stage (`terminus.place.name`) and the position.
  - Don't show a route column for it, or show "—".
- `queues/geofence` → `queue` is the driver's place in a line, never their live run.
- `queues/add` (dispatcher) is unchanged.
  - It refuses only when the bus already has an open **stage** queue.
  - A bus that is live on a route can still be placed in a line.

## Passenger app

Nothing to change. `book_a_ride/queues` already lists only buses that are broadcasting, and now lists only their live runs. Booking a stage queue (`book_a_ride/booking/add` with a stage queue's id) is refused with `422`.
