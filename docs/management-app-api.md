# Management screens, admin End Trip, arrival alerts: API for the apps

**Backend:** `feat/mgmt-totals-endtrip-arrival`

## Collections at a glance: `GET summaries/overview`

**Who can call it:** anyone with `View Summaries`: SACCO Admin, Operations Manager, Fleet Manager, Queue Supervisor, Finance, Investor. It is scoped like the summaries list:
- a SACCO user sees their SACCO's buses;
- an **investor** sees only the buses they own;
- a bank user sees the fleet it financed.

| Query | Meaning |
|---|---|
| `date` | `Y-m-d`. Defaults to today in Nairobi. |
| `period` | `day` (default), `week` or `month`: which period `vehicles` ranks. |
| `vehicles_limit` | 1–200, default 50. |

```json
{
  "date": "2026-10-15",
  "totals": {
    "day":   { "from": "2026-10-15", "to": "2026-10-15", "collections": 1200, "mpesa_amount": 1000, "cash_amount": 200, "total_txn": 2, "vehicles": 2, "cashless_percent": 83.3, "net_amount": 1200, "expense_fee_amount": 0, "mpesa_txn": 1, "cash_txn": 1 },
    "week":  { "from": "2026-10-12", "to": "2026-10-18", "...": "same fields" },
    "month": { "from": "2026-10-01", "to": "2026-10-31", "...": "same fields" }
  },
  "daily": [ { "date": "2026-10-01", "collections": 0, "mpesa_amount": 0, "cash_amount": 0, "vehicles": 0 }, "... every day of the month" ],
  "period": "day",
  "vehicles": [ { "vehicle_id": 7, "plate": "KDA 001A", "collections": 1000, "mpesa_amount": 1000, "cash_amount": 0, "total_txn": 1 } ],
  "idle": 3
}
```

- Weeks run Monday to Sunday; months are calendar months.
- `idle`: buses that collected nothing in the ranked period.
- Bus-level detail and exports stay on `GET summaries` (`from`, `to`, `search`, paging).

Not in this endpoint yet, pending a decision: the investor's "cash for collection", and the stage manager / stage accountant scope.

## Every bus and its money today so far: `GET summaries/today`

This is the stage manager's screen, for the **Queue Supervisor** role (also any role with `View Summaries`). Optional `?search=` part of a plate.

```json
{
  "as_of": "2026-10-16T13:00:00+03:00",
  "date": "2026-10-16",
  "totals": { "collections": 1200, "mpesa_amount": 1200, "cash_amount": 0, "total_txn": 2, "vehicles": 3, "collecting": 2 },
  "vehicles": [
    { "vehicle_id": 8, "plate": "KDB 002B", "collections": 200, "mpesa_amount": 200, "cash_amount": 0, "total_txn": 1, "last_payment_at": "2026-10-16T12:50:00+03:00" },
    { "vehicle_id": 7, "plate": "KDA 001A", "collections": 1000, "mpesa_amount": 1000, "cash_amount": 0, "total_txn": 1, "last_payment_at": "2026-10-16T12:30:00+03:00" },
    { "vehicle_id": 9, "plate": "KDC 003C", "collections": 0, "mpesa_amount": 0, "cash_amount": 0, "total_txn": 0, "last_payment_at": null }
  ]
}
```

- Lists **all** the SACCO's buses. The bus that took money most recently comes first; buses with nothing yet today come last.
- Amounts update live with every payment. Pull to refresh, or poll every 30–60 s.

## End a bus's trip from the office: `POST queues/{id}/end`

**Permission:** `Add Queues`, which is office staff only. Drivers and conductors hold `Edit Queues` and must not end another bus's trip. The caller must belong to a SACCO, or be a super admin. `{id}` is the bus's live run, or the stage queue it departed from (from `GET queues?kind=live` / `kind=all`).

| Response | When |
|---|---|
| `200` `{ "success": "Trip ended.", "trip": { "queue_id", "kind", "vehicle", "route", "status": "Completed", "ended_at", "ended_by" } }` | Ended. |
| `409` with `unmarked: [{ id, name, passengers, from_id, payment_method }]` | Paid passengers haven't been marked boarded or not boarded. **Only the crew can mark them**. The office cannot (decided 2026-09-12). Show the list and ask the crew to mark them. |
| `409` `"This bus is waiting in line, not on a trip…"` | The id is a stage queue still waiting. Use queue exit instead. |
| `409` `"This trip has already ended."` | |
| `404` | Not found, or another SACCO's. |

`queues/complete/queue` stays retired (`410`).

## "Your bus is arriving" push

No new endpoint. When a bus on a live run comes within **200 m** of a paid, not-yet-boarded passenger's pickup point, that passenger gets one notification:
- `type: "trip"`, `referenceId`: the booking id;
- title *"Your bus is arriving"*, body *"KDA 001A is about 150 m from Tea Room. Get ready to board."*

It comes through the usual channels: push, the in-app list, and the socket.

**Passenger app:** a tap should open that booking's live tracker. Push only reaches passengers who registered their device (`POST notifications/devices`).
