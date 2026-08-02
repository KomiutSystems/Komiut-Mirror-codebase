# M-Pesa Payments Dashboard — Web API

For the SACCO web dashboard ("Komiut Mpesa Payments"). Every call needs
`Authorization: Bearer <token>`, `X-App-Key: komiut|safiri`, and
`Accept: application/json`. All reads/writes are **auto-scoped to the caller's
SACCO** (a superadmin sees across SACCOs and may pass `?sacco=<id>` / `sacco_id`).

Base: `https://api.komiut.com/api/v1/auth`

---

## Credentials are shared per SACCO

A SACCO configures its M-Pesa **once** (keys, short code, paybill, passkey,
payment mode). Each **vehicle** differs only by its own **Till Number** +
**Merchant Short Code**, set when adding/editing the vehicle. There is **no
sandbox** — settings are always live. **Secrets are write-only**: you send them,
they're encrypted at rest, and they are **never returned** — reads tell you only
whether each is set.

---

## Settings

### GET `mpesa/settings`
```json
{ "setting": {
  "id": 1, "sacco_id": 7, "business_short_code": "174379", "paybill": "5557936",
  "payment_mode": "CustomerPayBillOnline", "is_live": true, "status": true,
  "consumer_key_set": true, "consumer_secret_set": true, "pass_key_set": true,
  "created_at": "...", "updated_at": "..." } }
```
`setting` is `null` when the SACCO hasn't configured M-Pesa yet.

### POST `mpesa/settings` (upsert — needs `Add`/`Edit Payment Settings`)
```json
{ "business_short_code": "174379", "paybill": "5557936",
  "payment_mode": "paybill",                // or "buygoods" (aliases of the two Daraja modes)
  "status": true,
  "consumer_key": "...", "consumer_secret": "...",
  "pass_key": "..."                          // this is the form's "API Key" (Daraja STK passkey)
}
```
- **First save requires all three credentials.** On edit, **omit** any credential
  to keep the stored one (the form shouldn't echo secrets) — send it only to rotate it.
- `payment_mode`: send `paybill` / `buygoods` (or the raw `CustomerPayBillOnline` /
  `CustomerBuyGoodsOnline`). No sandbox field — always live.
- Returns `{ "success": "...", "setting": { …masked… } }`. `400` with `errors` on
  validation, `403` without permission.

---

## Vehicle payment details (Till + Merchant)

Set on the vehicle itself via the existing endpoint — the vehicle inherits the
SACCO's credentials + paybill.

### POST `vehicles/add` (create/update; needs `Add`/`Edit Vehicles`)
```json
{ "id": 0, "plate": "KDY 599G", "seat": "<layout name>", "sacco": "<sacco name>",
  "till_number": 4321087, "merchant_short_code": 4321075, "status": 1 }
```
`id: 0` creates; a real id updates.

---

## Tills

### GET `mpesa/tills?search=&page=1`
```json
{ "tills": [
    { "vehicle_id": 12, "plate": "KDY 599G", "till_number": "4321087",
      "merchant_short_code": "4321075", "paybill": "5557936", "status": true } ],
  "count": 1, "total": 182, "page": 1, "per_page": 20, "total_pages": 10 }
```
Lists vehicles that have a till/merchant configured, with the SACCO's paybill.

---

## Transactions

### GET `transactions/mpesa?date=&search=&vehicles=[..]&amount=&page=1`
```json
{ "mpesa": [
  { "TransID": "UH2LY1DZB0", "MSISDN": "2547…", "TransAmount": "80.0",
    "TransTime": "2026-08-02T11:01:00Z", "FirstName": "MUNIRA", "LastName": "…",
    "BusinessShortCode": "5557936", "BillRefNumber": "4321087",
    "transaction": { "vehicle": { "plate": "KDW 978G", "sacco": {…} } } } ] }
```
Now confined to the caller's SACCO. `date` defaults to today; 20/page. Dashboard
columns map as: Trans ID=`TransID`, Name=`FirstName`+`LastName`, Vehicle=
`transaction.vehicle.plate`, MSISDN=`MSISDN`, Amount=`TransAmount`,
Paybill=`BusinessShortCode`, Merchant=`BillRefNumber`, Date=`TransTime`.

`transactions` (all methods) and `transactions/cash` exist alongside, same filters.

---

## Dashboard tiles

### GET `mpesa/stats`
```json
{ "mpesa_today": 911210.15, "tills_count": 182, "users_count": 5,
  "recent_transactions": [
    { "trans_id": "UH2LY1DZB0", "name": "MUNIRA", "vehicle": "KDW 978G",
      "msisdn": "2547…", "amount": 80.0, "paybill": "5557936",
      "merchant": "4321087", "date": "2026-08-02T11:01:00Z" } ] }
```
`mpesa_today` = today's M-Pesa collection for the SACCO; `tills_count` = configured
tills; `users_count` = SACCO users; `recent_transactions` = latest 10.

The chart series for the Dashboard graph is the existing `GET dashboard`
(`?year=0..4`, `?sacco=`, `?vehicles=`).
