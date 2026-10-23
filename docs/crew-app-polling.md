# Crew app: how often to ask, and what the server now does

**Why this exists.** On 2026-10-23, between 11:48 and 12:56 EAT, fourteen newly onboarded crew phones polled
`driver/transactions`, `driver/home` and `driver/earnings`. Each request read the bus's whole payment history.
The PHP workers filled up, the load balancer's health check queued behind them, and AWS replaced both API
servers. No delivered payment was lost: the 19 that Safaricom could not deliver during the incident were
recovered from Safaricom's Pull API. The server side is fixed. These notes are what the app should do so that a
larger crew rollout stays cheap.

## What the server does now

| Endpoint | Behaviour |
|---|---|
| `GET driver/transactions` (no search) | The `total` comes from an index only and is reused for 60 s per bus. The rows are always live. |
| `GET driver/transactions?search=` | Receipt and name text matches only words of **3 or more** characters. Shorter words still match an amount, a date, or `mpesa`/`cash`/`points`. A search that cannot finish over the whole history within 1.5 s is answered over the **last 14 days**, and the response then carries `"search_window_days": 14` (otherwise `null`). |
| `GET driver/earnings` | `all_time` is reused for 30 minutes per bus, and only one request recomputes it at a time. `today`, `week` and `month` stay live. |
| `GET driver/home` | Unchanged shape. Its recent-takings list and total use the same cheap path as `driver/transactions`. |

## What the app should do

1. **Search from 3 characters.** Don't send `search` for one or two characters, because the server ignores them for
   names and receipts anyway. Filter the rows already on the phone locally instead.
2. **Cancel the previous search** when the term changes (Dio `CancelToken`), and debounce typing at about 700 ms.
   Every pause ("m", "ma", "mar") used to become a full-history query.
3. **If `search_window_days` is set,** say so under the results, e.g. *"Showing matches from the last 14 days."*
4. **Don't refetch `driver/earnings` on every payment push.** Builds before 2026-10-15 invalidated the earnings
   provider on each Reverb `PaymentRecorded` and on every 45 s poll that saw the total grow, on both the
   driver's and the conductor's phone. Apply the push to `today` locally, and refetch earnings on screen open and
   pull-to-refresh only.
5. **Keep `driver/transactions` page 1 at the 45 s poll, or slower.** The push already delivers new payments live.
