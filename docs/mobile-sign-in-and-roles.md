# Mobile: one sign-in for crew and staff, and the stage manager screens

**Backend:** live on prod (2026-10-19). Base path `/api/v1/auth`.

## 1. Sign-in: `POST mobile/login`

One form for everyone who is not a passenger. Passengers keep Google or Apple.

| Field | Label in the app | Notes |
|---|---|---|
| `phone` | Phone number | Any form: `0712…`, `254712…`, `+254 712…` |
| `secret` | **Number plate or password** | Crew type the bus's plate. Stage managers and investors type their password. |
| `firebase_token`, `device_id` | (not shown) | Optional. Send them as on the other sign-ins. |

**`200`, crew (they typed a plate):**

```json
{ "session": "crew", "user": {…}, "vehicle": {…}, "access_token": "…", "token_type": "Bearer", "expires_at": "…" }
```

This is the same body as `driver/login`. Go to the **crew** app.

**`200`, staff (they typed a password):**

```json
{ "session": "staff", "staff_roles": ["stage_manager"], "access_token": "…", "refresh_token": "…", "user": {…}, "permissions": […], "sacco": {…} }
```

This is the same body as `login`. Route on `staff_roles`:

| `staff_roles` contains | Open |
|---|---|
| `stage_manager` | Stage manager home (§3) |
| `investor` | Investor home (§4) |
| `sacco_admin`, `operations_manager`, `fleet_manager`, `finance`, `cashless_admin` | Management screens: `summaries/overview`, `summaries/today` (see `management-app-api.md`) |

If a person has more than one role, ask once which view to open, or default to the first one in the list.

**Errors:**
- **`401`:** *"We couldn't sign you in. Check your phone number and your number plate or password…"*. The message is the same whichever half was wrong.
- **`403` `reason: "no_mobile_role"`:** the account has no mobile screens. Show the server's message. A crew member who typed a password gets *"Crew sign in with the number plate of their bus."*
- **`429`:** too many tries. Wait a minute.

**Forgot password** (staff and investors): `POST forgot-password` with their email.

### What a plate (crew) session can and can't do

- It reaches only the crew app's endpoints: `driver/*`, `queues/join|exit|geofence`, `trips/*`, `book_a_ride/*`, `summaries`, `transactions*`, notifications, profile and so on.
- Anything else answers **`403` `reason: "crew_session"`**: *"Sign in with your password to see this…"*.
- The money it does see (`summaries`, `transactions`) is **only the bus it signed in with**, even for an owner-driver who also owns other buses.
- To see their investor view, an owner-driver signs in again with their **password**.

## 2. What the app needs to collect

| Who | Collect | When |
|---|---|---|
| Everyone (non-passenger) | phone, plate-or-password | Sign-in |
| Stage manager | **their stage**: pick one from `GET mobile/stage` → `stages` | First open, when `stage` is `null`. Changeable from a settings item. |
| Investor | nothing more | |
| Crew | nothing new | |

## 3. Stage manager (`staff_roles` has `stage_manager`)

1. `GET mobile/stage` returns `{ "stage": {id, name} | null, "stages": [{id, name}, …] }`. These are their SACCO's stages.
2. If `stage` is `null`, show a picker, then send `PUT mobile/stage {"terminus_id": 57}`. It returns `{stage}`, or `422` if the stage isn't one of their SACCO's.
3. Home screen: `GET mobile/stage/buses`. Poll every 30–60 s or pull to refresh.

```json
{
  "stage": { "id": 57, "name": "Ambassadeur" }, "date": "2026-10-19", "as_of": "2026-10-19T09:30:00+03:00",
  "buses": [
    { "vehicle_id": 7, "plate": "KDN 458N", "state": "on_trip", "position": null, "today_amount": 4200,
      "trip": { "queue_id": 812, "queue_ids": [811, 812], "kind": "live", "status": "Active",
                "joined_at": "…", "departed_at": "…", "ended_at": null, "amount": 1350, "payments": 19 },
      "previous_trip": { "amount": 2850, "payments": 41, "…": "…" } }
  ]
}
```

- **`state`:**
  - `waiting`: in line; `position` is its place.
  - `on_trip`: departed or live.
  - `finished`: the crew ended the trip.
- **Order:** waiting (by position), then on trip, then finished.
- **`trip.amount`:** the money this bus made **on this trip**. That's every payment from when it joined the line (passengers pay while it loads) until the crew ends the trip. A line place and the run the bus went live on count as one trip.
- **`today_amount`:** this one bus's takings today.
- **There is no SACCO total, by design.** Don't add one up on the phone.
- **`409` `reason: "no_stage"`** means they need to pick a stage first.
- **Trips come from the crew.** A bus shows up here once its crew joins the stage's queue or goes live from it. Empty state: *"No buses have joined the line at {stage} today."*

## 4. Investor (`staff_roles` has `investor`)

These already exist. All are limited to **the buses this investor owns**:

| Screen | Endpoint |
|---|---|
| Today, live: each bus and its money so far, most recent first | `GET summaries/today` (poll 30–60 s) |
| Day / week / month totals, daily chart, buses ranked | `GET summaries/overview?period=day\|week\|month` |
| Every payment as it lands | `GET transactions/mpesa?date=YYYY-MM-DD` (newest first; poll 15–30 s; `cursor` for paging) |
| Per bus over a range | `GET summaries?from=…&to=…` |

The investor totals are the totals **of their own buses**, which is correct for an owner.
