# Flutter Passenger App — Auth Migration to the Laravel Backend

For the Flutter dev. The passenger app's auth layer is still shaped for the old
C# gateway (`/api/iam/...`, camelCase, nested `{value}` bodies). We are moving
the app onto the Laravel backend (`api.komiut.com`). This is the exact mapping.

**Convention shift:** the Laravel API is **snake_case**, flat request bodies, no
gateway prefix, and every call carries **`X-App-Key: komiut`** (or `safiri`) —
not the `_domain` GUID header. There is no automatic key transform on either
side, so the model `fromJson`/`toJson` keys must change to match.

---

## Endpoint mapping

| Purpose | OLD (C#) | NEW (Laravel) |
|---|---|---|
| Base | `<brand>/api/iam` / `/api/fleet` | `https://api.komiut.com/api/v1/auth` |
| Login | `POST /api/iam/authentication/login` | `POST /api/v1/auth/login` |
| Register | `POST /api/iam/authentication/register` | `POST /api/v1/auth/register` |
| Current user | `GET /api/iam/authentication/who-am-i` | `POST /api/v1/auth/user` |
| Forgot password | `.../forgot-password` | `POST /api/v1/auth/forgot-password` |
| Reset password | `.../reset-password` | `POST /api/v1/auth/reset-password` |
| Change password | `.../change-password` | `POST /api/v1/auth/profile/change_password` |
| **Google sign-in** | *(none)* | `POST /api/v1/auth/social/google` |
| **Update profile (name/phone)** | *(local only)* | `POST /api/v1/auth/profile/update` |
| Upload photo | `POST /api/iam/Users/{id}/profile-image` | `POST /api/v1/auth/profile/upload_picture` |
| Refresh | `.../refresh-token` | `POST /api/v1/auth/refresh` |

Every request needs header **`X-App-Key: komiut`** (Komiut) or **`safiri`** (2Safiri).
An unknown/missing key returns **404**, not 401 — so a "route not found" on a
path you can see usually means the header is missing.

---

## Request bodies — drop the `{value}` wrappers, use snake_case

**Login** — was `{ email: {value}, password }`, now flat:
```json
{ "email": "jane@example.com", "password": "secret" }
```

**Register** — was camelCase + nested, now:
```json
{
  "firstname": "Jane",
  "lastname": "Wanjiru",
  "email": "jane@example.com",
  "phone": "0712345678",
  "password": "secret123",
  "password_confirmation": "secret123"
}
```
Note: `firstname`/`lastname` (not `firstName`), `phone` (not `phoneNumber`),
`password_confirmation` (not `confirmedPassword`), and **no `{value}` nesting**.

**Change password**: `{ "current_password": "...", "new_password": "...", "confirm_password": "..." }`

---

## Response shape — read `access_token` and the snake_case user

Login / Google sign-in return:
```json
{
  "access_token": "1|xxxx…",
  "token_type": "bearer",
  "user": {
    "id": 42,
    "firstname": "Jane",
    "lastname": "Wanjiru",
    "email": "jane@example.com",
    "phone": "0712345678",
    "type": "passenger",
    "last_active_at": "2026-07-28T09:00:00Z",
    "roles": [], "gender": null
  },
  "permissions": [], "sacco": null, "vehicle_users": [], "termini": []
}
```

Model changes needed:
- **token**: read `access_token` (the app currently reads `accessToken`/`token` — neither matches).
- **name**: there is no `fullName`. Build it from `firstname` + `lastname`.
- **id/email/phone**: `id`, `email`, `phone` — these already match your fallbacks.
- **type**: `user.type` is `passenger` | `driver` | `admin` | `superadmin` (was `role`/`userType`).
- The token is a **Sanctum** token (`1|abc…`), **not a JWT** — do not try to decode claims from it. Get identity from the `user` object and `POST /user`.

`POST /api/v1/auth/user` returns the same `user` object (plus `permissions`,
`sacco`, etc.) — use it for "who am I" on app start.

---

## Google sign-in — the new flow

1. Add `google_sign_in` to `pubspec.yaml`.
2. Configure it with the **Web** client id as `serverClientId` (this is what makes the SDK return an ID token whose audience the backend checks — **not** the Android/iOS client id):
   - Komiut: `1028247623841-7tshoiv54l29aa2rtva1qlglqs5f0vvv.apps.googleusercontent.com`
   - 2Safiri: `750939898589-d9ktqpvuc44f66vco11if4lute37nrae.apps.googleusercontent.com`
3. On tap, get the `idToken`, then:
   ```
   POST /api/v1/auth/social/google
   X-App-Key: komiut
   { "id_token": "<idToken>" }
   ```
4. Read `access_token` from the response exactly like login.

**One button, not two** — the backend logs in an existing passenger or creates a
new one automatically, so there is no separate sign-up/sign-in. Add a "Continue
with Google" button + an "OR" divider to `login_screen.dart` below the Sign in
button.

Google passengers arrive with **email but no phone** (both columns are nullable
on the backend, so this is fine). They can browse, but **cannot pay for a ride
until they add a phone** — the phone is what M-Pesa STK push charges.

---

## Phone-later — wire the edit screen to the backend

Today `ProfileService.updateProfile` writes name/phone to the device only. Point
it at the new endpoint:
```
POST /api/v1/auth/profile/update
X-App-Key: komiut
Authorization: Bearer <access_token>
{ "phone": "0712345678" }        // any of: firstname, lastname, phone
```
Returns `{ "success": "...", "user": { id, firstname, lastname, email, phone } }`.
Sends only the changed fields; phone must be unique (422 with `errors.phone` if taken).

**Not verified yet:** the backend does not OTP-check the phone. A typo here
misdirects a real M-Pesa payment, so before payments go live we should add an
OTP step (new backend endpoints + a verify screen). Flagged, not built.

---

## Suggested order

1. Point the base URL at `api.komiut.com/api/v1/auth`, add the `X-App-Key` header (drop `_domain`).
2. Update login/register request+response models (flat, snake_case, `access_token`).
3. Swap who-am-i to `POST /user`; stop decoding the token as a JWT.
4. Add Google sign-in (package + button + datasource method).
5. Wire the edit-profile save to `profile/update`.

Live API docs (always current): **https://api.komiut.com/docs**
