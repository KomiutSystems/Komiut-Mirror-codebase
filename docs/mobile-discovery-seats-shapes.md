# Discovery + Seats — real authenticated response shapes

For the Flutter discovery/seat parsers (Phase 3b). These are **real** responses
captured from the backend against seeded data — not hand-written — so the field
names, nesting, and types are exact. Raw captures: `shape_routes.json`,
`shape_queues.json`, `shape_seats.json` (delivered alongside).

> Note on types: these endpoints serialize raw Eloquent models, so **`status` is
> an int (`0`/`1`), not a bool**, `queue_type` is an int, timestamps are ISO-8601,
> and there is **no pagination envelope** — just `{queues:[…]}` / `{routes:[…]}`,
> paged with `?page`. No total/count is returned.

---

## ⭐ The id mapping (the highest-risk assumption — confirmed)

This is the thing that would 422 every booking if wrong. Confirmed from the
controllers and the live responses:

| The app's concept | The API field to send | Where it comes from in discovery |
|---|---|---|
| **Trip id** (`selectedTripId`) → `id` in `booking/add` and `seats` | **`queue.id`** | `queues[].id` (or `routes[].queues[].id`) |
| **Vehicle** → `bus_id` in `seats` | **`queue.vehicle.id`** | `queues[].vehicle.id` |
| **Pickup** → `from_id` (queues/seats) / `fromId` (booking/add) | **Place id** | `route_stages[].place_id` / `.place.id` / `route.from.id` |
| **Dropoff** → `to_id` / `toId` | **Place id** | `route_stages[].place.id` / `route.to.id` |
| **Seats** → `seats: "[1,2]"` in `booking/add` | **seat-arrangement ids** | `seats.seat.seat_arrangements[].id` |

**"Trip" IS "queue".** There is no separate trip entity — the queue is the trip.
`selectedTripId` must carry `queue.id`.

**"Stop" IS "place".** `from_id`/`to_id`/`fromId`/`toId` are **`Place` ids** — the
`place_id` on a route stage (equivalently `route_stage.place.id`). They are **not**
route_stage ids and **not** terminus ids. Sending a route_stage id will silently
match the wrong stop or 422.

**`booked[].seatId` = seat-arrangement id.** Disable the seat whose
`seat_arrangements[].id` appears in `booked`.

---

## 1. `GET book_a_ride/queues` — the direct discovery source for booking

Query: `from_id`, `to_id` (place ids), `sacco` (exact name), `search` (plate/fleet), `page`.
Each item is the trip you book against. Shape (abridged; full in `shape_queues.json`):

```json
{ "queues": [ {
  "id": 1,                         // ← queue id = the trip id → booking/add `id`, seats `id`
  "queue_number": "QN-1",
  "vehicle_id": 1, "route_id": 1, "terminus_id": 1, "queue_status_id": 1,
  "amount": 200,                   // server fare (indicative; booking/add returns the authoritative one)
  "queue_type": 0, "start_time": "2026-08-02 09:22:27", "end_time": null,
  "queue_status": { "id": 1, "name": "Pending", "status": "Pending", "active": 1 },
  "vehicle": {
    "id": 1,                       // ← seats `bus_id`
    "plate": "KDA007X", "fleet_no": "7", "seat_id": 1, "status": 1,
    "sacco": { "id": 1, "name": "Sacco 1", "phone": "0700000001", "status": 1 },
    "seat": { "id": 1, "name": "Layout 6", "seats": 4, "rows": 4, "columns": 1 }
  },
  "route": {
    "id": 1, "name": "Nairobi CBD - Thika", "from_id": 1, "to_id": 2,
    "from": { "id": 1, "name": "Nairobi CBD" },   // ← place ids for from_id/to_id
    "to":   { "id": 2, "name": "Thika" },
    "route_stages": [
      { "id": 1, "route_id": 1, "place_id": 1, "distance": 0,  "sequence": 1,
        "place": { "id": 1, "name": "Nairobi CBD" } },
      { "id": 2, "route_id": 1, "place_id": 2, "distance": 40, "sequence": 2,
        "place": { "id": 2, "name": "Thika" } }
    ]
  },
  "terminus": { "id": 1, "name": "Terminus 5", "place_id": 1, "place": { "id": 1, "name": "Nairobi CBD" } }
} ] }
```

Stops for the picker come from `route.route_stages[]`, ordered by **`sequence`**
(or `distance`) — each stop's id for `from_id`/`to_id` is `place_id` / `place.id`.

## 2. `GET book_a_ride/routes` — route-centric (queues nested)

Query: `from_place_id` + `to_place_id` (preferred) or `from` + `to` (names, legacy), `page`.
Same building blocks as above but grouped under the route, with matching queues
nested at `routes[].queues[]` (each queue has the same shape as §1). Use this when
the UI lists routes first; use `queues` when it lists departures directly. Full
capture in `shape_routes.json`.

## 3. `GET book_a_ride/seats` — the seat map + occupancy

Query (all confirmed): `bus_id` (=`vehicle.id`, required), `id` (=`queue.id`,
required), `from_id`, `to_id` (place ids, optional — segment-aware occupancy),
`booking_id` (optional, excludes an amended booking's own seats).

```json
{ "seats": {
    "id": 1, "plate": "KDA007X", "seat_id": 1,
    "seat": {
      "id": 1, "name": "Layout 6", "seats": 4, "rows": 4, "columns": 1,
      "seat_arrangements": [
        { "id": 1, "name": "S1", "row": 1, "column": 1, "seat_id": 1, "status": 1 },
        { "id": 2, "name": "S2", "row": 2, "column": 1, "seat_id": 1, "status": 1 },
        { "id": 3, "name": "S3", "row": 3, "column": 1, "seat_id": 1, "status": 1 },
        { "id": 4, "name": "S4", "row": 4, "column": 1, "seat_id": 1, "status": 1 }
      ]
    }
  },
  "booked": [ { "seatId": 1 } ]
}
```

The **real layout is now server-driven** — render from `seat.rows` × `seat.columns`
and place each `seat_arrangement` by its `row`/`column` (label = `name`). This
replaces the client-side seat generation. A seat is taken iff its
`seat_arrangements[].id` is in `booked[].seatId`; occupancy is for the
`from_id→to_id` segment (a seat free on a non-overlapping segment is bookable).
The `booked` example shows seat-arrangement id `1` held by an unpaid booking (the
10-min hold still counts as occupied).

---

## End-to-end, with the ids wired

1. `GET book_a_ride/queues?from_id={placeFrom}&to_id={placeTo}` → pick `queue`.
2. `GET book_a_ride/seats?bus_id={queue.vehicle.id}&id={queue.id}&from_id={placeFrom}&to_id={placeTo}` → render `seat.seat_arrangements`, disable ids in `booked`.
3. `POST book_a_ride/booking/add` `{ id: queue.id, seats: "[1,2]", name, phone, fromId: placeFrom, toId: placeTo, payment_method: "mpesa" }` → `{ booking_id, amount }`.
4. `POST mpesa/stk { phone, booking_id }` → poll `mpesa/stk/status/{CheckoutRequestID}` (Phase 3a).

If `booking/add` ever returns `422`, the first suspect is an id-mapping slip:
`id` not a queue id, or `fromId`/`toId` not place ids.
