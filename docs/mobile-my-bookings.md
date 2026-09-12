# My bookings and "Where is my bus" — the mobile contract

Added 2026-09-12. Captured from the running backend.

Base: `https://api.komiut.com/api/v1/auth` · `Authorization: Bearer` + `X-App-Key`.

---

## The state of a booking, in one word

Every row from `GET bookings/passengers` (and the single view, and the track
call below) carries `state` and `trackable`. Render `state`; put the **map
button on every row where `trackable` is true**, and nowhere else.

| `state` | what happened | `trackable` | screen |
|---|---|---|---|
| `reserved` | booked, not paid yet | **true** | pay, or it expires. Map button shows |
| `confirmed` | paid, waiting for the bus | **true** | **map button** |
| `boarded` | the crew tapped *board* — you are on the bus | false | no map; you are the dot |
| `completed` | boarded, and the trip has ended | false | history |
| `not_boarded` | the crew marked you not boarded, or the trip ended without you | false | **"Not boarded — refunded"**. Points came back if you paid points; the fare's worth in points if you paid money (`paid_with` says which; `points_spent` says how many) |
| `cancelled` | cancelled deliberately — by you, or the office | false | history |
| `expired` | the unpaid hold ran out (10 minutes) | false | history |

Two other fields help the row read right:

- `cancellation_reason` — `no_show` / `cancelled` / `expired` / `null`. Persisted
  since 2026-09-12; older cancelled rows carry `null` and `state` still derives
  sensibly (unpaid → `expired`, paid → `cancelled`).
- `cancelled_at` — ISO timestamp, or `null`.
- `paid_with` / `points_spent` / `amount_collected` — see the loyalty doc. For a
  `not_boarded` row, `paid_with: "points"` means "your N points are back";
  `paid_with: "mpesa"` means "KES X came back as points".

`trackable` is exactly: state is `reserved` or `confirmed` **and** the trip is not
over. It is a snapshot — the crew can end the trip after you fetched the list —
so the track call below answers it again, and the map should close itself when
`vehicle.moved` stops and a re-fetch says `trackable: false`.

There is nothing to refund from the app: `not_boarded` is written only by the
crew (per-passenger tap, or the trip-end sweep). See the loyalty doc, *Refunds*.

---

## `GET bookings/passengers/track/{id}` — the map's first paint

One call when the map opens. Then subscribe to the channel it names and stop
polling. Owner only (or View Passengers); `403` otherwise, `404` for an unknown id.

```jsonc
{
  "booking_id": 8,
  "state": "confirmed",
  "trackable": true,
  "vehicle": { "id": 728, "plate": "KDN 458N", "fleet_no": "458" },
  "trip": {
    "queue_id": 17,
    "status": "Active",              // Pending | Active | Completed | Cancelled
    "channel": "trip.17",            // subscribe here: echo.private('trip.17')
    "event": "vehicle.moved"
  },
  "bus": {                           // null if this bus has NEVER broadcast
    "latitude": -1.2012, "longitude": 36.9501, "heading": 45,
    "recorded_at": "2026-09-12T07:41:02+00:00",
    "age_seconds": 9,
    "broadcasting": true,
    "live": true                     // draw a confident dot ONLY while true
  },
  "pickup":  { "place_id": 1981, "name": "Nairobi CBD",      "latitude": -1.2833, "longitude": 36.8167 },
  "dropoff": { "place_id": 1984, "name": "Thika Main Stage", "latitude": -1.0333, "longitude": 37.0693 },
  "route": {
    "id": 1973, "name": "Nairobi CBD - Thika",
    "stops": [ { "place_id": 1981, "name": "Nairobi CBD", "latitude": -1.2833, "longitude": 36.8167, "sequence": 1 }, ... ]
  }
}
```

### How to read `bus`

- **`null`** — the driver has never broadcast from this bus. Show the route and
  the pickup; say "waiting for the driver to go live". Do not invent a position.
- **`live: true`** — the position is under 120 seconds old and the driver is
  broadcasting. Animate the marker; heading is degrees clockwise from north.
- **`live: false`** — you still get the last position. Grey the marker and show
  "last seen N minutes ago" from `age_seconds`. `broadcasting: false` means the
  driver explicitly stopped (trip over, or the app was closed).

The 120-second rule lives on the server (`VehicleLocationService::FRESH_SECONDS`)
so the app never has to know it — read `live`, not the timestamp.

### After the first paint

```dart
echo.private('trip.$queueId').listen('vehicle.moved', (e) {
  // {vehicle_id, queue_id, plate, latitude, longitude, heading, recorded_at}
  moveMarker(e['latitude'], e['longitude'], e['heading']);
});
```

The channel authorises anyone who booked that queue — nothing extra to send.
If no event arrives for ~2 minutes, re-fetch the track call: `bus.live` will say
whether to grey the marker, and `trackable` will say whether to close the map
(the crew ended the trip, or marked you boarded / not boarded).

### `pickup` for a roadside booking

A pick-as-you-go booking (`broadcast/reserve`) carries the exact GPS point the
passenger flagged from; `pickup.latitude/longitude` is that point, not the
snapped stop. `pickup.place_id` is still the stop the fare was priced on.
Coordinates fall back to the route's stage, then the place; either can be
`null` for a stop nobody has geocoded — skip the marker, keep the name.

---

## What stays the same

- The list is still `GET bookings/passengers` with the same filters
  (`range=all`, `from`/`to` dates, `booking_status`). Nothing was removed; the
  new keys are additive.
- `status_label` is not appended; use `state`.
- The driver's position comes from the crew app posting `book_a_ride/location`
  while broadcasting — this doc does not change that side.
