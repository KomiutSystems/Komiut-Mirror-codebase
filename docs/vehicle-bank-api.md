# Vehicles: bank (financier) and full edit — dashboard spec

**For:** the dashboard (KomiutWebApp, `komiut.com/vehicles`)
**Backend:** branch `feat/sacco-sets-vehicle-bank` (live once merged to `staging`)

## Goal

A SACCO must be able to see which bank finances each of its buses, find buses with no bank, and correct the bank itself. Today only a Komiut super admin can set it.

Build three things on `/vehicles`:

1. A **bank filter** with counts.
2. An **Edit vehicle** form.
3. A **bank choice when adding** a vehicle.

## Concepts

`vehicles.financier` is the bank that finances a bus. It is also an **access key**: a bank's users see only the buses carrying their bank, and each bank gets a monthly statement of those buses' collections. So who may change it is narrower than who may edit a vehicle.

| API value | Show as |
|---|---|
| `"NCBA"` | NCBA |
| `"coop-bank"` | Co-op Bank |
| `null` | No bank |

### Permissions

Permissions come from the signed-in user's `permissions` array (`POST user`, already used by `usePermissions().can()`).

| Permission | Allows |
|---|---|
| `View Vehicles` | `GET vehicles` |
| `Add Vehicles` | create (`id: 0`) |
| `Edit Vehicles` | edit (`id > 0`) of plate, fleet no, seat layout, tills, status |
| `Edit Vehicle Bank` | **new**. Set, change or clear a bus's bank. SACCO Admin has it; Fleet Manager and Investor do not. |

Super admins can do everything.

**Bank viewers** see only their own bank's buses, so hide every bank control from them. The page already has `showBank = !isBankViewer(...)`.

### The rule for changing a bank

A SACCO admin can only:
- **move** a bus to a bank that already finances at least one *other* bus in the same SACCO, or
- **clear** a bus to "No bank".

NICCO already has NCBA and Co-op buses, so it can move any bus between NCBA, Co-op and No bank. A SACCO that has no bus under a bank yet gets a 403 (see below). In that case, Komiut assigns the first bus.

Clearing a SACCO's **last** bus under a bank ends that relationship. Only Komiut can then put a bus back under that bank. If you show a confirmation before a bank change, mention this when the user is clearing the last NCBA or Co-op bus (check `financier_counts`).

## Endpoints

All paths are relative to the API's `/api/v1/auth/` base. The dashboard reaches them through its BFF: `api.get("vehicles")` goes to `/bff/vehicles`. All need the bearer token.

### 1. `GET vehicles` — list, with the bank filter

**Query parameters** (all optional):

| Param | Meaning |
|---|---|
| `page` | 1-based page, 20 rows per page |
| `search` | Plate (spaces and case ignored), till number or merchant code |
| `financier` | **New.** `NCBA`, `coop-bank` or `none` (= no bank). Blank means no filter. Any other value returns **400**. |
| `seat` | Seat layout id |
| `sacco` | SACCO id (super admin only) |

**Response 200:**

```json
{
  "vehicles": [
    {
      "id": 151, "plate": "KDY 599G", "fleet_no": "12",
      "till_number": "1747747", "merchant_short_code": "4321075",
      "ncba_till": null, "coop_till": null,
      "financier": "NCBA",
      "brand": "komiut", "sacco_id": 4, "seat_id": 2, "status": true,
      "user": { "...": "..." }, "seat": { "id": 2, "name": "45-Seater" }, "sacco": { "...": "..." }
    }
  ],
  "total": 126, "per_page": 20, "current_page": 1, "last_page": 7,
  "financier_counts": { "NCBA": 126, "coop-bank": 54, "none": 0 }
}
```

- **`financier_counts` is new.** It covers the caller's whole fleet and **ignores** `financier`, `search` and the page, so the counts on the filter stay put while the list changes.
- `financier_counts` and `total` are both counted live. Right after a save, the counts and the pager agree.
- **The three keys are always present**, even when zero. If the key is missing (an older backend), show the filter without numbers.

**Errors:**
- **400** `{"errors":{"financier":["The selected financier is invalid."]}}`

### 2. `POST vehicles/add` — create or edit

The same endpoint does both: `id: 0` creates, `id: <vehicle id>` edits.

**Body:**

| Field | Type | Notes |
|---|---|---|
| `id` | int, required | `0` to create |
| `plate` | string, required | Unique across the platform |
| `seat` | string, required | Seat layout **name**, not id. Options come from `GET vehicles/seat_settings`. |
| `status` | int, required | `1` active, `0` inactive |
| `sacco` | string | SACCO **name**. A SACCO user can only name their own. Any other name is ignored. On create, leaving it out means the user's own SACCO. |
| `fleet_no` | string \| null | |
| `till_number` | int \| null | Safaricom till, digits only |
| `merchant_short_code` | int \| null | Digits only |
| `ncba_till` | string \| null | Bank collection account. A string, because leading zeros matter. |
| `coop_till` | string \| null | Same as `ncba_till` |
| `financier` | `"NCBA"` \| `"coop-bank"` \| `null` \| `""` | `null` or `""` means No bank. Only send it when the user may change the bank (see below). |

**When editing, a field you leave out is kept as it is.** This applies to `fleet_no`, `till_number`, `merchant_short_code`, `ncba_till`, `coop_till` and `financier`. To clear one of them, send it as `null`. On create, a missing field means `null`.

**Sending `financier`:**
- **The user has `Edit Vehicle Bank`, or is a super admin:** send the chosen value, on create and on edit.
- **Any other user:** **leave `financier` out.** (Re-sending the stored value unchanged is also accepted. Sending a different value returns 403.)

**Responses:**

| Status | Body | When |
|---|---|---|
| 200 | `{"success":"Vehicle saved successfully"}` | Saved |
| 400 | `{"errors":{"<field>":["…"]}}` | Validation failed: bad `financier`, plate taken, missing `seat`, non-digit till, etc. |
| 401 | `{"error":"Permissions to Add/Edit Vehicle Denied"}` | No `Add Vehicles` / `Edit Vehicles`. This is an old 401 from before; see the warning below. |
| 403 | `{"error":"You do not have permission to change which bank finances this vehicle"}` | A bank change without `Edit Vehicle Bank` |
| 403 | `{"error":"This SACCO has no buses financed by NCBA Bank yet. A Komiut administrator assigns a SACCO's first bus to a bank."}` | The rule for changing a bank, above |
| 403 | `{"error":"Your account is not attached to a SACCO."}` | Account has no SACCO |
| 404 | `{"message":"No query results for model …"}` | Vehicle is not in the user's SACCO |

- **A 403 or 400 saves nothing.** None of the other fields in the request are written either.
- **Show the `error` text as it comes.** It is written for the SACCO user.

> ⚠ **401 for a missing permission.** If the dashboard's API client treats every 401 as "session ended" and signs the user out, an unpermitted click would log them out. Gate the UI so it never happens: show Edit only with `can("Edit Vehicles")`, and Add only with `can("Add Vehicles")`.

### 3. `GET vehicles/seat_settings` — seat layouts for the form

Already used by the dashboard (`{ seats: [{ id, name, ... }] }`). Send the chosen layout's **`name`** as `seat`.

### 4. `GET activity` — bank changes in the activity log

Needs `View Activity Log`. Every actual bank change appears with `action: "vehicles.financier.changed"` and a ready-made sentence in `description`, for example *"{name} changed the bank for KDY 599G from NCBA Bank to Co-operative Bank"*. The `data` object holds `{ vehicleId, plate, saccoId, from, to, onCreate }`. You can filter with `?action=vehicles.financier.changed`. No dashboard change is needed beyond showing it.

## What to build

### A. Bank filter (on `/vehicles`, hidden for bank viewers)

- A control above the table with these options: **All banks · NCBA (n) · Co-op Bank (n) · No bank (n)**. The numbers come from `financier_counts`.
- Selecting an option sends `financier=NCBA|coop-bank|none` and goes back to page 1. "All" sends nothing.
- Keep it working together with the search box.
- In the **Bank** column, show **"No bank"** (muted) for `null` instead of "—".

### B. Edit vehicle (one per row, only when `can("Edit Vehicles")`)

1. **Form fields** (a sheet, like the existing ones):
   - Plate
   - Fleet no.
   - Seat layout (a select from `vehicles/seat_settings`)
   - **Bank** (a select: NCBA / Co-op Bank / No bank)
   - M-Pesa till number
   - Merchant short code
   - Status (Active / Inactive)
2. **Prefill** from the row, including `null` values. Don't change values the user didn't touch.
3. **Bank select access:** enable it only when `can("Edit Vehicle Bank")` or the user is a super admin. Otherwise show it disabled with the hint *"Only your SACCO admin can change the bank."*, and leave `financier` out of the request.
4. **Validation:** till and merchant code must be digits only (as the "Set" sheet does today). Plate and seat layout are required.
5. **On success:** show a toast and refresh the vehicles query, so the list and `financier_counts` update. Invalidate the same query keys the existing till/merchant mutation does.
6. **On error:** show the server's `error`, or the first message under `errors`.

### C. Add vehicle (existing "Add vehicle" sheet)

- Add the same **Bank** select, only for users with `Edit Vehicle Bank` or super admins. Others don't see it, and the request leaves `financier` out.
- `sacco` may be left out; the backend uses the user's own SACCO.

### D. Existing till/merchant "Set" sheet (`use-vehicles.ts`)

- **Stop sending `financier`.** It sends `vehicle.financier` today. That is harmless while unchanged, but not needed.
- It does not send `fleet_no`. The new backend keeps the stored fleet number when it's left out. The old backend wiped it, which is why some buses show "—". To be safe with either backend, send `fleet_no: vehicle.fleet_no`.

## Acceptance checks

1. As a NICCO SACCO admin:
   - The filter shows NCBA 126 · Co-op Bank 54 · No bank 0.
   - Change a bus NCBA → Co-op Bank: the counts become 125 / 55, the row shows Co-op Bank, and the activity log shows the change.
2. As a Fleet Manager:
   - The Bank select is disabled with the hint.
   - Editing the plate or fleet no. saves.
   - The bank does not change.
3. As a bank viewer: no bank filter, no Bank column, no bank controls. This is unchanged from today.
4. Set a bus's till with "Set": its fleet no. is still there afterwards.
5. A SACCO admin of a SACCO with no buses under any bank picks NCBA: the server's 403 message is shown and nothing changes.
6. Filter "No bank" + search: both apply. The numbers on the filter don't change when you search.
7. Everything works at phone width.
