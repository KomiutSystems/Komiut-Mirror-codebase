# Komiut v2 — Road to Production (To-Do)

_As of 2 Aug 2026. Checked = done & live on Frankfurt. Unchecked = remaining. Owner in **bold**._

---

## ✅ Done (core backend — live on Frankfurt)

- [x] Multi-brand (komiut / 2Safiri), Sanctum auth, per-SACCO tenancy
- [x] Security audit — closed 2 IDORs + crew-PII IDOR + driver queue over-grant
- [x] Removed Blade — service is now pure API
- [x] Deploy pipeline (push → GitHub Action → Frankfurt, secrets in SSM)
- [x] Google sign-in for passengers (email no longer required)
- [x] Driver street onboarding (phone + plate, no OTP; login = vehicle assignment)
- [x] `last_active_at` on every account
- [x] Self-service profile update (name + phone)
- [x] SACCO directory (NTSA/SASRA names, claim-on-registration)
- [x] Segment-aware seat availability + seat double-sell fix (`RouteStage.sequence`)
- [x] Driver queue/trip lifecycle: `queues/join`, `queues/exit`, `trips/start`, manifest, pick-passenger
- [x] M-Pesa C2B webhook hardened (no more silent payment loss)
- [x] STK status poll + cancel
- [x] Pull Transactions API (built, dormant, ready for cutover)
- [x] Payment reconciler (poll-if-callback-lost)
- [x] Notifications — in-app + push + realtime (all endpoints live)
- [x] Loyalty points
- [x] Bank partner lead-list APIs (NCBA, Co-op)

---

## 🔴 Remaining to reach production

### 1. Mobile app migration — **Mobile team** _(critical path)_
- [ ] Point app at Laravel base URL + `/api/v1/auth/*`
- [ ] Wire Google sign-in to the new verify endpoint
- [ ] Swap realtime client SignalR → Pusher/Echo (notifications, live vehicle, seats)
- [ ] Adopt new notification contract (path change only — shapes already match)
- [ ] Adopt driver join/exit/start endpoints
- [ ] Full regression on booking + payment flows against Frankfurt

### 2. Product gaps — **Backend**
- [ ] **Decide: is Wallet in v1?** (no backend exists — if yes, it's a real build)
- [ ] Passenger-shaped profile (saved places, payment phone, ride history)
- [ ] Booking refund / cancellation flow
- [ ] (Optional) `SeatsChanged` realtime broadcast — polling works today
- [ ] Carpool — **deferred to post-launch** (confirm app hides it)

### 3. Payments productionization — **Backend + You**
- [ ] Live end-to-end payment test on Frankfurt with a real till
- [ ] 2Safiri per-SACCO Daraja credentials (encrypted at rest)
- [ ] Turn Pull API reconciler on at cutover

### 4. Realtime & email delivery — **Backend + You**
- [ ] 2Safiri Firebase project + service-account file (its push is a no-op until then)
- [ ] Request SES production access (email dark in sandbox — request early, takes days)
- [ ] Confirm Reverb live end-to-end once app is on Pusher

### 5. Web portals — **Web team** (APIs already done)
- [ ] Bank partner page
- [ ] SACCO claim / registration portal
- [ ] Audit admin/dispatcher dashboard parity with legacy

### 6. Infra & security (before switching traffic off legacy) — **Backend + You**
- [ ] Rotate AWS root key
- [ ] Rotate SSM secrets
- [ ] Revoke exposed legacy git PAT (in prod git remote)
- [ ] Close legacy DB port 3306 (open to the internet)
- [ ] Reconcile 14 unpushed commits on legacy master
- [ ] Restore QR fare-token feature missing from mirror/prod
- [ ] Monitoring + alerting (error tracking + CloudWatch alarms on payments)
- [ ] Backup / restore drill on Frankfurt

### 7. Pre-launch validation — **All**
- [ ] Load / soak test (peak-hour booking + payment)
- [ ] Full payment reconciliation validation (STK + C2B + Pull, both brands)
- [ ] Security review of the release
- [ ] Pilot with a small set of SACCOs
- [ ] Approve legacy decommission plan

---

## 🗓️ Suggested order (target: soft launch ~mid-Sept, legacy off ~early Oct)

- [ ] **Now → 15 Aug:** close backend gaps (§2) + payments live (§3)
- [ ] **11 Aug → 5 Sep:** mobile migration (§1) — *start ASAP, it's the gate*
- [ ] **11 → 29 Aug (parallel):** web portals + email/2Safiri (§4, §5)
- [ ] **25 Aug → 5 Sep:** infra & security hardening (§6)
- [ ] **8 → 19 Sep:** validation + pilot (§7)
- [ ] **22 Sep +:** decommission legacy
