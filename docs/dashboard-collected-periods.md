# Dashboard: "Collected" for 3 months and 6 months — dashboard spec

**For:** KomiutWebApp, `/dashboard` (the same page on komiut.com and 2safiri.co.ke)
**Backend:** `perf/dashboard-periods-from-summaries` (live once merged to `staging`)

## Goal

The dashboard already shows **Collected today** and a Collections chart with period buttons. Two changes are wanted:

1. **Show what was collected over the selected period**, next to today's figure: "Collected in the last 3 months: KES …".
2. **Offer the periods This week, This month, 3 months and 6 months.** Six months is the longest; remove "Year to date". The new system has no data before April 2026, so a year view would mislead.

The backend change makes these figures fast. A 6-month total used to take 5–9 seconds per load and per period click; it now takes milliseconds. It also counts days by **Nairobi** time.

## Endpoint (unchanged shape)

`GET dashboard?year=<n>` — already called by `useDashboardCollections()` in `src/hooks/use-dashboard.ts`.

| Button | `year` | Window |
|---|---|---|
| This week | `0` | Monday to Sunday of the current Nairobi week |
| This month | `1` | 1st to the last day of the current month |
| 3 months | `2` | 1st of the month two months ago to the end of this month. Today (6 Oct) that is 1 Aug – 31 Oct. |
| 6 months | `3` | 1st of the month five months ago to the end of this month. Today that is 1 May – 31 Oct. |
| ~~Year to date~~ | ~~`4`~~ | Still answered by the API, but **remove the button**. |

### Response

The keys and nesting are unchanged. The values below are examples:

```json
{
  "today":  { "date": "2026-10-06", "mpesa": 1422735, "cash": 0, "total": 1422735 },
  "period": { "from": "2026-08-01", "to": "2026-10-31", "mpesa": 96012345, "cash": 0, "total": 96012345 },
  "mpesa": 96012345, "cash": 0, "totals": 96012345,
  "transactions": "[{\"totals\":28547654,\"year\":2026,\"month\":8}, …]",
  "xaxis": "[\"Aug\",\"Sep\",\"Oct\"]"
}
```

What each field means:

- **`period.total`** is what the selected period collected (M-Pesa + cash), **including today so far**. Use it for the new figure, with **`period.mpesa`** and **`period.cash`** for the split.
  - **`period.from`** and **`period.to`** are the window's first and last days. `to` can be later than today, because the window runs to the end of the month.
- **`today`** is unchanged in meaning: today's Nairobi date. It is now correct between 00:00 and 03:00 Nairobi time; before, it showed yesterday.
- The **`transactions`** series is the chart.
  - For week: `{totals, day}`, where `day` is a day name.
  - For month: `{totals, day}`, where `day` is an **integer** day of the month.
  - For 3 and 6 months: `{totals, year, month}`, with **integer** year and month.
  - Before, `day`, `year` and `month` could arrive as strings. The current code already reads them through `Number()`, so it keeps working.
  - Points add up to `period.total`.
- A user sees only their own scope:
  - **SACCO admin:** their SACCO's buses.
  - **Investor:** their own buses.
  - **Bank viewer:** their bank's financed buses.
  - **Super admin:** everything.

  This works the same way on both brands.

## What to build

1. **Period buttons:** keep This week · This month · 3 months · 6 months. Remove "Year to date" from `DASHBOARD_PERIODS`, along with its `rangeFor` / `RANGE_PHRASE` entries. If a saved state still holds "year", fall back to "6 months".
2. **The period figure:** in the Collections card header, show the selected period's total from `period.total`.
   - Example: **"KES 96,012,345 collected in the last 3 months"**, with a subtitle like "1 Aug – 6 Oct". Use `period.from` to today, not `period.to`, because the rest of the month hasn't happened yet.
   - Show the M-Pesa / cash split from `period.mpesa` / `period.cash` in the same style as the today tile.
   - Show a skeleton while loading, as the chart already does.
   - On an older backend without `period`, fall back to the top-level `totals`.
3. **Keep "Collected today" exactly as it is,** driven by `today`.
4. **Refresh:** keep the current refetch. The query is now cheap, so the 60-second refresh is fine.
5. **Brands:** no brand-specific code. The same page on komiut.com and 2safiri.co.ke shows each viewer their own scope.

## Acceptance checks

1. As a NICCO SACCO admin:
   - "3 months" shows a total equal to the sum of the three monthly bars.
   - "6 months" shows May–October, and its total equals the six bars added up.
2. As the Co-op Bank viewer: "6 months" covers only Co-op buses. For example, August alone shows KES 28,547,654.
3. As the NCBA viewer: the same check for NCBA buses. For example, August alone shows KES 46,947,934.
4. Switching between 3 months and 6 months responds in well under a second.
5. At 00:30 Nairobi time, "Collected today" shows only the new day's collections.
6. "Year to date" is gone.
7. The layout works at phone width.
