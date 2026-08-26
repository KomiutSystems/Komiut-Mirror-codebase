# Legacy → Frankfurt migration — working TODO

Generated 2026-08-26 from a six-agent read-only scoping pass over both systems.
Every claim below was measured against live data, not inferred.

**Status key:** `[ ]` not started · `[x]` done · `[!]` needs a human decision, not a command

---

## Hard rules for this whole programme

- **Never preserve legacy `mpesas.id` or `transactions.id`.** One uniform rule, no boundary
  conditions. Join on `TransID`, which is UNIQUE. Preserving ids collides with the live
  sequence, and `C2bPaymentRecorder` catches `Throwable` while the controller still returns
  `"Success"` to Safaricom — so the customer is debited and the money silently does not exist.
- **Never chunk the backfill by id range.** Legacy `mpesas.id` is not monotonic with `TransTime`;
  month boundaries overlap by 250k–380k ids. Chunk on `TransTime`, which is indexed.
- **Never run `copy:mpesa` or `legacy:import-money`.** Both are documented footguns —
  see the neutralisation step below.
- **Every index on a money table is `CREATE INDEX CONCURRENTLY`.** A plain build takes an
  ACCESS EXCLUSIVE lock and blocks writes to a table taking live payments. An index build
  has already taken the legacy system down once.
- **Legacy `TransTime` is EAT (UTC+3).** The DB box clock is EAT. Convert explicitly, both ways.
- **Legacy MySQL has a 30s statement cap.** Bound every query by an indexed `TransTime` range
  and never wrap an indexed column in a function, or you get `ERROR 3024`.

---

## Blockers found during scoping

### `BLOCKER` THE THREE-DAY TIMELINE DOES NOT FIT. Days 1-2 of this runbook are achievable and genuinely valuable. The six-month backfill is not a three-day job on a db.t4g.small: ~10.8M row inserts against ten indexes (four GIN trigram) on 2 burstable vCPU with ~5-6 hours of burst credit per day before throttling to a 20% baseline that would slow the LIVE payment path. Add 181 export chunks, 181 summary regene

**Mitigation:** Decouple history from cutover — they are not the same problem. Payment cutover needs correct INGESTION (steps 1-9), not six years of history. Realistic shape: Day 1 recorder fix + sequences + reconciliation; Day 2 durable pull sync + 17-day hole + users; Day 3 export tooling and first backfill nights; Days 4-5 the 48h observation gate runs on green reconciliation; Day 5-6 first tills re-registered; the 6-month backfill lands over the following 5-8 nights BEHIND the live cutover; operational/book

### `BLOCKER` Any id-preserving import makes the next live payment collide on mpesas_pkey, and the collision is SWALLOWED. C2bPaymentRecorder wraps everything in catch(Throwable) and C2bConfirmationController still returns {"C2BPaymentConfirmationResult":"Success"} to Safaricom. The customer is debited, the money is gone from the system, and the only trace is an mpesa_logs row.

**Mitigation:** Advance the sequences FIRST (step 2), before any other work and independently of when the import runs — mpesas_id_seq 20,306,042 against a legacy max of 21,017,089 is a 711k-wide exposure that exists today. Then never carry legacy mpesas.id or transactions.id at all; key on TransID, which is UNIQUE on both sides.

### `BLOCKER` ImportLegacyMoney (`legacy:import-money`) would ACTIVELY UNDO the sequence advance and silently discard real payments. Its resequence() does setval(sequence, MAX(id)) — run after a sequence advance with no newer rows, that drops mpesas_id_seq from 25,000,000 back to ~20.3M. Its insertOrIgnore is PostgreSQL ON CONFLICT DO NOTHING with NO conflict target, so a legacy row whose id already belongs to 

**Mitigation:** Do not use it for any part of this. Remove it from reach for the duration (step 4). Verified in source: `DB::table($table)->insertOrIgnore($batch)`; `DB::table('transactions')->whereNotNull('cash_id')->update(['cash_id' => null])`; `setval(pg_get_serial_sequence('{$t}','id'), COALESCE((SELECT MAX(id) FROM {$t}),1))` with handle() calling repair() then resequence() only AFTER the whole read loop.

### `BLOCKER` CopyMpesa (`copy:mpesa`) double-counts money across the whole history. Its cursor is read from a table five producers write to, an unknown cursor replays from the beginning of history, and it increments the vehicle summary unconditionally on reprocess with no `if ($transaction === null)` guard. Someone reaching for it during a migration crunch re-adds historical fares to vehicle day totals.

**Mitigation:** Do not use it; say so in writing in the runbook. It is already unscheduled and the Kernel.php comment documents why — keep it that way and gate the command behind a confirmation flag for the duration of the cutover.

### `BLOCKER` Every payment to the 54 safiri vehicles is being recorded with vehicle_id NULL right now — 2,576 transactions / KES 159,947 today, 40.9% of the day. If legacy vehicle_id is backfilled verbatim onto a live path that cannot attribute safiri, those 54 buses get six months of history and then flatline to zero at the import boundary. If attribution is instead re-derived through Frankfurt's resolver, ro

**Mitigation:** Fix resolveVehicle BEFORE the backfill and before the sync job (step 3) — withoutGlobalScopes for the brand, and honour the BillRefNumber the recorder already passes. Then legacy vehicle_id verbatim is correct and the two halves agree. Verify against a live safiri payment, not a unit test.

### `BLOCKER` Both Frankfurt app instances were restarted 28 seconds apart, leaving the ALB with no healthy target; the fire-and-forget forwarder saw ALB 5XX, treated it as a completed HTTP exchange, and dropped the payments. Http::post() without ->throw() does not raise on 4xx/5xx, so the catch block never fires and nothing is logged. Any deploy during the parallel-run window silently costs payments.

**Mitigation:** Make Frankfurt deploys rolling (drain one target, deploy, wait healthy, then the other) and freeze deploys during the 05:00-09:00 UTC peak before step 3 ships. Add a CloudWatch alarm on HTTPCode_ELB_5XX_Count > 0. The durable pull sync then removes the dependency on Frankfurt being reachable at the instant Safaricom calls.

### `BLOCKER` Re-running `legacy:import-users` as written overwrites 12 live Frankfurt accounts — including 10 working drivers — with legacy passengers' data, flips them to type=passenger while leaving their Driver role rows behind, and then aborts on a unique violation and rolls back. Its guard does not fire.

**Mitigation:** Do not run it in its current form. The guard is `$existing > 0 && DB::table('users')->where('id','>',$maxLegacyId)->exists()` with $maxLegacyId=6819 while Frankfurt max id is 6812 — no row satisfies it, so the guard passes and `updateOrInsert(['id'=>$u['id']], ...)` matches on id alone. Import the 20 absent people with NEW ids from the raised sequence and carry a legacy_id->frankfurt_id map. Skip legacy 6799 and 6801 — they already exist as Frankfurt 6802 and 6803.

### `BLOCKER` Both systems are minting user and sacco ids from the same range TODAY. Frankfurt's next signup takes id 6813, which legacy assigned to a different person on 2026-08-24 20:21:48. A realtime sync keyed on id would overwrite live Frankfurt accounts one at a time, silently, for the whole observation window. Saccos are three legacy SACCOs from the same failure (legacy max 39, Frankfurt occupies 42-52 w

**Mitigation:** Advance users_id_seq and saccos_id_seq to 100,000 in step 2, and give the sync an explicit id mapping rather than assuming equality. Never sync users on id.

### `BLOCKER` Importing route_stages without deriving `sequence` makes every seat read free to every passenger, with no error anywhere. The column defaults to 0, and SegmentSeatAvailability's overlap test `$reqFrom < $bTo && $bFrom < $reqTo` becomes `0 < 0` = false for every row, so occupiedSeatIds() returns [] unconditionally. Overbooked matatus surface only when passengers physically arrive.

**Mitigation:** Never load route_stages in one step. Load rows, then derive sequence as a separate explicit pass in true travel order, then assert zero rows with sequence=0 and no route with duplicate sequences BEFORE any queue exists. Do not derive it from legacy `distance`: on route 9 (Nairobi->Githurai) the origin Nairobi sorts LAST at 23.32, and route 10 has all five stages at the identical 4097.33.

### `BLOCKER` Migrating sacco_routes.amount verbatim ships 44 of 63 fares at KES 1. FareResolver returns it as the flat fare, addBooking stamps it on the booking, and MpesaPaymentsController charges `(int) round($booking->amount)` — a one-shilling STK push for a real ride. It does not look like a failure; it looks like a working booking.

**Mitigation:** Treat amount=1 as 'unpriced', not as a price. Import only the rows with a plausible amount and require the SACCO to enter the rest before the route goes live. Assert `SELECT COUNT(*) FROM sacco_routes WHERE status AND amount <= 1` = 0 and confirm the fare endpoint 404s rather than returning 1.

### `HIGH` Recomputing summaries cannot undo a bad import. GenerateVehicleSummaries::summariseDay only iterates vehicles that HAVE transactions on that date — it never deletes a stale summary row. So if backfilled transactions are later rolled back, the summary rows they created survive with wrong totals unless deleted explicitly. No report flagged this.

**Mitigation:** Make the rollback for every backfill step three-part and ordered: delete transactions, then mpesas, then DELETE the affected summaries rows outright before regenerating. Do not assume `--date=` cleans up after a rollback.

### `HIGH` ~901 payments/day (KES ~68,000) on shortcodes 880100, 6624890 and 6624891 never transit payments server 2 and reach Frankfurt at zero. A sync validated only against komiut_payments reports green while permanently missing this class, and it is nearly half the measured hourly gap.

**Mitigation:** Reconcile against komiut_latest_app (the superset), not komiut_payments. Trace the second path to its origin and get a written ruling on whether Frankfurt serves it before cutover or those tills stay on legacy. This is a human decision, not a code fix.

### `HIGH` The backfill throttles the database and takes the live payment path with it. 576 banked CPU credits earning 24/hour against ~120/hour burn for two vCPUs at 100% gives roughly 5-6 hours before the instance drops to its 20% baseline. Because C2bConfirmationController acks Safaricom AFTER the database work, a slow database directly delays the ack and triggers Safaricom retries.

**Mitigation:** One calendar day per transaction, oldest first, mandatory pause between days, hard stop if CPUCreditBalance falls below 250, and run inside the 22:00-05:00 EAT window. Consider dropping the four GIN trigram indexes on mpesas for the load and rebuilding them CONCURRENTLY afterwards — but never drop mpesas_transid_unique, which is the dedupe key the whole design rests on.

### `HIGH` Chunking the backfill by id range silently skips and double-counts, because legacy mpesas ids are not monotonic with TransTime — month boundaries overlap by 250k-380k ids.

**Mitigation:** Chunk strictly on TransTime, which is indexed on komiut_latest_app. Never wrap it in a function or the 30s cap kills the query with ERROR 3024. Verified: 2026-03 max id 15,937,549 while 2026-04 min id is 15,605,788.

### `HIGH` Frankfurt declares no foreign keys on any migrated money or operational table — transactions.vehicle_id/mpesa_id, summaries.vehicle_id, queues.*, sacco_vehicles.* are all unconstrained. The database cannot catch a bad remap; orphans land silently and surface later as missing money against a vehicle.

**Mitigation:** Run explicit LEFT JOIN orphan sweeps after every stage and treat any nonzero count as a stop condition. The baseline is currently clean — verified TXN_ORPHAN_MPESA=0 and TXN_ORPHAN_VEHICLE=0 today — so any nonzero result is import-induced.

---

## Ordered steps

### [x] 1. Take a NAMED manual RDS snapshot of komiut-prod-db, e.g. komiut-prod-db-precutover-20260826. Separately take ad-hoc mysqldumps of legacy summaries, places, routes, termini and users, stored off-box.

`DAY 1 · WRITE — AWS resource only, no DB change · 10 min · ROLLBACK: delete the snapshot`

**Why:** Correcting the premise every report carried: Frankfurt is NOT unprotected. It has 30-day automated backups and live PITR (LatestRestorableTime was 5 minutes old when checked). But automated snapshots age out and PITR restores to a NEW instance — an endpoint swap with downtime, not an undo. A named manual snapshot is a restore point that will still exist when someone needs it, and it costs nothing on a 908 MB database. The legacy MySQL boxes genui

**Verify:** aws rds describe-db-snapshots --region eu-central-1 --db-instance-identifier komiut-prod-db --snapshot-type manual --query "DBSnapshots[?DBSnapshotIdentifier=='komiut-prod-db-precutover-20260826'].[Status,SnapshotCreateTime]" --output text -> available. A snapshot that has not been listed as available is not a restore point.

### [x] 2. Advance five sequences clear of legacy's id space, before anything else and independent of every later schedule: SELECT setval('mpesas_id_seq',25000000,true); setval('transactions_id_seq',26000000,true); setval('summaries_id_seq',200000,true); setval('users_id_seq',100000,true); setval('saccos_id_se

`DAY 1 · WRITE — PRODUCTION DB · under 1 second · NOT REVERSIBLE (a sequence must never be lowered to 'undo' this) but harmless — a gap in the id space costs nothing`

**Why:** First because it closes a payment-losing hazard that exists TODAY, on its own, even if every other step slips. mpesas_id_seq is 20,306,042 against a legacy max of 21,017,089 — a 711k-wide window in which an id-preserving write makes the next live payment collide on mpesas_pkey, C2bPaymentRecorder swallows the Throwable, and C2bConfirmationController still tells Safaricom 'Success'. It also breaks the shared user/sacco id space: Frankfurt's next s

**Verify:** select sequencename, last_value from pg_sequences where sequencename in ('mpesas_id_seq','transactions_id_seq','summaries_id_seq','users_id_seq','saccos_id_seq') -> the five floors. Then wait for one live payment and confirm it landed above the floor: select max(id), max("TransTime") from mpesas -> id > 25000000 with TransTime inside the last minute.

### [ ] 3. Fix the two defects in C2bConfirmationController::resolveVehicle. (a) Query Vehicle::withoutGlobalScopes() — payment recording is a system op, exactly the argument C2bPaymentRecorder already makes for Transaction. (b) Honour the BillRefNumber the recorder already passes: for a paybill shortcode, res

`DAY 1 · WRITE — CODE + ROLLING DEPLOY · 3-4 h including review · ROLLBACK: revert the deploy, code only, no data migration`

**Why:** Everything downstream runs through this method — the live path, the realtime sync in step 8, and the meaning of every backfilled vehicle_id. Right now 2,576 transactions and KES 159,947 of today's money are being recorded with vehicle_id NULL, 100% of it safiri, every one on a shortcode that matches exactly one vehicle. Legacy attributes the identical traffic at 2,676/2,676. Until this lands, backfilling legacy vehicle_id verbatim would give 54 b

**Verify:** select v.brand, count(*) filter (where t.vehicle_id is null) unattributed, count(*) total from transactions t join mpesas m on m.id=t.mpesa_id join vehicles v on v.merchant_short_code=m."BusinessShortCode" where t.created_at > now() - interval '10 minutes' group by 1 -> safiri unattributed must be 0. Baseline before the fix: komiut 3,590 attributed / safiri 2,481 all unattributed. Then confirm the

### [ ] 4. Neutralise the three commands that would react to backfilled history on their own: comment out the hourly `app:attribute-coop-settlements` schedule entry, and put `legacy:import-money` and `copy:mpesa` behind a confirmation flag or remove them from reach.

`DAY 1 · WRITE — CODE, deploy alongside step 3 · 30 min · ROLLBACK: revert`

**Why:** AttributeCoopSettlements is hourly and date-UNBOUNDED, so it would begin creating transactions and summary rows for dates months in the past within an hour of the first backfill chunk landing, unreviewed. ImportLegacyMoney is worse than the reports described: its resequence() does setval(MAX(id)), which would actively UNDO step 2's sequence advance, and its untargeted insertOrIgnore drops colliding rows with a success exit code. CopyMpesa is the 

**Verify:** In the deployed container: php artisan schedule:list -> no app:attribute-coop-settlements entry. grep the runbook and every deploy script for 'legacy:import-money' and 'copy:mpesa' -> no occurrences.

### [ ] 5. CREATE INDEX CONCURRENTLY on mpesa_logs (trans_id) and (created_at), and set a 30-day retention prune.

`DAY 1 · WRITE — DDL · 2 min · ROLLBACK: DROP INDEX, fully safe`

**Why:** Placed before the reconciliation check because that check depends on mpesa_logs to tell 'never arrived' from 'arrived but not recorded' — the distinction that decides whether a gap is a transport problem or a recorder bug. The table has only mpesa_logs_pkey today and grows ~42,000 rows/day carrying full JSON bodies. Indexed after it becomes load-bearing, the reconciler is the thing that degrades the app it exists to protect. CONCURRENTLY so it ta

**Verify:** select indexname from pg_indexes where tablename='mpesa_logs' -> pkey plus the two new indexes. explain (analyze) select 1 from mpesa_logs where trans_id = 'UHQ0T4HMZG' -> Index Scan, not Seq Scan.

### [ ] 6. Ship the cross-system reconciliation check as a scheduled read-only job, alerting on any minute bucket where legacy count > Frankfurt count, excluding the trailing 10 minutes. LEGACY (komiut_latest_app, bound by the indexed TransTime, EAT): SELECT DATE_FORMAT(TransTime,'%Y-%m-%d %H:%i') m, COUNT(*) 

`DAY 1 · READ-ONLY · 2 h to build · nothing to roll back`

**Why:** Read-only, so it can ship today, and it is the only instrument that tells you whether any later step worked. Nothing downstream should run unmeasured — the current loss is invisible precisely because nobody is comparing. Use komiut_latest_app, NOT komiut_payments: the app DB is the superset (2,676/hour vs 2,641) and is the only place the second ingestion path appears. Bucket on TransTime because it is EAT and naive on BOTH sides; created_at in th

**Verify:** Reproduce today's known deficit for 2026-08-26 08:00-09:00 EAT: legacy 2,676 / KES 169,074 against Frankfurt 2,600 / KES 162,024 = 76 missing. The drill-down must classify it as 40 Customer Merchant Payment + 1 OD Payment Transfer (transport loss) and 35 blank-TransactionType (structural), with Organization To Organization Transfer matching exactly at 17/17.

### [!] 7. Rule on the second ingestion path: shortcodes 880100, 6624890 and 6624891 arrive in komiut_latest_app with a blank TransactionType, never transit payments server 2, and reach Frankfurt at ZERO. Someone must trace that path to its origin and decide in writing whether Frankfurt serves it before cutove

`DAY 1 · HUMAN DECISION — not a command`

**Why:** This is ~46% of the measured hourly gap and no forwarding design closes it — it is a routing fact, not a bug. If it is not settled explicitly, the reconciliation check from step 6 will alarm forever on a class nobody owns, and the first person reconciling against a Safaricom statement will read it as a failed migration. It also cannot be resolved by an engineer alone: it may be a deliberate separate integration.

**Verify:** A written, signed list of shortcodes deliberately left on legacy. Then: legacy SELECT BusinessShortCode, COUNT(*) FROM mpesas WHERE TransTime >= <24h ago> GROUP BY 1 differenced against Frankfurt returns zero UNEXPLAINED shortcodes. Today's baseline to close: 901 rows / KES 67,991 on 2026-08-25.

### [ ] 8. Build the durable realtime sync as a PULL job on Frankfurt, running on komiut-scheduler-1 with withoutOverlapping() and onOneServer(). Source komiut_latest_app on the slave. Cursor on mpesas.id (PRIMARY). Settle window `TransTime <= NOW() - INTERVAL 3 MINUTE`. Trailing re-scan of the last 30 minutes

`DAY 2 · WRITE — NEW CODE + CONFIG · 6-8 h · ROLLBACK: disable the scheduled job; Frankfurt holds extra rows, harmless while it is not yet authoritative`

**Why:** Pull, not push, because Frankfurt already runs redis with a real worker and scheduler while legacy payments server 2 has QUEUE_CONNECTION=sync, no queue worker, and no cron running schedule:run at all — durable push means installing untested infrastructure on a box being retired, with APP_DEBUG=true and no backups. Pull is self-healing: the cursor IS the completeness proof. It comes after step 3 because it writes through the same resolveVehicle; 

**Verify:** Induced-outage test: stop the Frankfurt app containers for 5 minutes during a low-traffic hour, then confirm the step-6 check returns to zero deficit within two poll intervals with no manual action — and that summaries totals for the affected vehicle_ids are UNCHANGED after the catch-up replays rows the forwarder had already delivered. Legacy lag baseline for comparison: MAX(TransTime) 10:04:59 ag

### [ ] 9. Close the 17-day hole with the same pull job, given an explicit bounded start cursor at the beginning of 2026-08-09. Dedupe on TransID. Do NOT preserve legacy ids.

`DAY 2 · WRITE — PRODUCTION DATA · ~2 h · ROLLBACK, bounded and precise: DELETE FROM transactions WHERE mpesa_id IN (SELECT id FROM mpesas WHERE id > 25000000 AND "TransTime" >= '2026-08-09' AND "TransTime" < '2026-08-26'); then those mpesas; then DELETE the summaries rows for 2026-08-09..08-25 and regenerate`

**Why:** One mechanism instead of two — the job built in step 8 is exactly the right tool, and using it here proves it works before it becomes load-bearing. Ids cannot be preserved for this band: Frankfurt's sequence has already issued 20,300,058-20,306,104 to different, live-forwarded payments, and that overlap grows ~2,600/hour. Legacy id 20,300,058 is UH8A6298CE from 2026-08-08 22:33:02 while Frankfurt id 20,300,058 is a 2026-08-26 payment. Closing thi

**Verify:** select count(*), round(sum("TransAmount"::numeric),2) from mpesas where "TransTime" >= '2026-08-09' and "TransTime" < '2026-08-26' -> approaching 703,328 / 49,686,207.22 (less whatever the step-7 ruling leaves on legacy). select "TransTime"::date d, count(*) from mpesas where "TransTime" >= '2026-08-01' group by 1 order by 1 -> continuous, no gap between 08-08 and 08-26. Collision check: Frankfurt

### [ ] 10. Import the TWENTY absent legacy people with NEW ids, carrying legacy_id in a mapping table. Skip legacy 6799 and 6801 — they already exist as Frankfurt 6802 and 6803 — and record that mapping. Do NOT run `legacy:import-users` as written.

`DAY 2 · WRITE — PRODUCTION DATA · 1-2 h · ROLLBACK: DELETE FROM users WHERE id > 100000, precise because every new user takes an id from the raised sequence`

**Why:** The brief's 'ten users, nobody knows which ten' is wrong, and so is report 1's list. Legacy ids 6798-6819 are ALL absent (22 ids), while Frankfurt 6800-6803/6805-6812 are 12 different people created natively 2026-08-10..08-24 as drivers and an admin in saccos 39 and 42-52 that do not exist in legacy. Verified by hash at every id. The existing command's guard (`id > maxLegacyId` where maxLegacyId=6819, Frankfurt max 6812) does not fire, so it woul

**Verify:** select count(*) from users -> 6828 (6827 if the team declines the 6798 tombstone). select count(*) from users where id between 6800 and 6812 and type='driver' -> still 10, with phone hashes unchanged from 770ca955/0b127704/37a0fb1f/c15551d8/af32e0fb/7995599e/a3bc747a/c85f7948/eedb3b8f/15090ccc. Every legacy phone hash in 6798-6819 resolves to exactly one Frankfurt row.

### [!] 11. Decide three things a command cannot decide: (a) whether legacy id 6798 — status 0, parked-duplicate-6798@komiut.invalid, phone 0000006798 — is ported at all, since it is a tombstone that would occupy a real phone-unique and email-unique slot; (b) whether the 11 SACCO-tier accounts should keep the e

`DAY 2 · HUMAN DECISION`

**Why:** These are authorization decisions on a system that handles money, made silently by an import, on a database whose audit_logs holds only 32 rows and none about user changes. They are not migration defects to be fixed — they are grants that someone has to own or reverse. Putting them here, before cutover, is the last point at which reversing them is cheap.

**Verify:** A written record naming each of the 11 accounts and its intended role, then: select r.name, count(*) from model_has_roles m join roles r on r.id=m.role_id where r.name in ('SACCO Admin','Fleet Manager','Operations Manager','Finance') group by 1 matches it. select model_id from model_has_roles group by 1 having count(*)>1 returns only ids a human signed off.

### [ ] 12. Repair the six genuinely locked-out accounts and the duplicate vehicle rows: assign Queue Supervisor (or an explicit No-Totals variant) to users 603 and 817; assign Investor to the 4 missing holders; set users.financier on the Bank Viewer account 6272; retire or repoint vehicles 760 and 761, then ad

`DAY 2 · WRITE — PRODUCTION DATA · 30 min · ROLLBACK: delete the specific role rows and null the financier column; keep a list of exactly which ids you touched`

**Why:** These are small, fully understood, and each one is a person who cannot work on the new system. The Bank Viewer case is the quietest: FinancierScope fails closed with DENY_ALL while User::isBankUser() returns true from the role alone, so that account loads a dashboard with no data and no error. Two corrections to the reports while doing it: this is SIX accounts, not seven-plus-6,545 — legacy 'Nicco Managers' (19 holders) DID map to Queue Superviso

**Verify:** select r.name, count(*) from model_has_roles m join roles r on r.id=m.role_id group by 1 -> Investor 17, Queue Supervisor 21. select count(*) from users where financier is not null -> 1. select upper(regexp_replace(plate,'[^A-Za-z0-9]','','g')) k, count(*) from vehicles group by 1 having count(*)>1 -> 0 rows. Then sign in as 6272 and confirm the vehicle list is non-empty.

### [ ] 13. Build the per-day export from the legacy slave: 181 files, one per calendar date, each holding that day's mpesas and transactions as gzipped JSONL, plus a manifest row (date, mpesas_rows, mpesas_sum, tx_rows, tx_sum, sha256). Bound every query by the indexed TransTime / trans_date. Stage on / (62 GB

`DAY 3 · READ-ONLY on legacy · 1 day of engineering · nothing to roll back`

**Why:** Per-day granularity is what makes the import resumable, verifiable and cheap to roll back, and the manifest is the only independent record of what the source held at export time — the source is still growing at ~42,000 rows/day. Verified feasible: one day is 32,821 rows and a full month reads inside the 30s cap (March 2026 returned 1,178,408 rows / KES 59,328,264.06 in a single bounded query). Slicing by id instead would silently skip and double-

**Verify:** 181 files present. Sum of manifest mpesas_rows = 6,692,394 and mpesas_sum = 387,670,656.41; tx_rows = 6,691,822 and tx_sum = 377,531,732.15. Spot-check any single day against a fresh legacy COUNT/SUM for that date — 2026-03-15 must return 32,821 rows / KES 1,643,430.

### [ ] 14. Import day by day, oldest first, driven by a backfill_progress control table. Per day: COPY the day's mpesas into staging; INSERT INTO mpesas (...) SELECT ... ON CONFLICT ("TransID") DO NOTHING; INSERT INTO transactions (vehicle_id, mpesa_id, amount, trans_date, ...) SELECT s.vehicle_id, m.id, ... F

`DAYS 3-8 · WRITE — PRODUCTION DATA · THE LONG POLE, 5-8 overnight sessions · ROLLBACK, bounded: DELETE FROM transactions WHERE mpesa_id IN (SELECT id FROM mpesas WHERE id > 25000000 AND "TransTime" < '2026-08-09'); then those mpesas; then DELETE the affected summaries rows and regenerate`

**Why:** Never preserve mpesas or transactions ids, anywhere — not even for pre-2026-07-08 history where they are technically free. One uniform rule, no boundary conditions, and the TransID join is needed for the forked band regardless; a second code path for 'safe' ids is a bug waiting for a tired engineer at 3am. TransID is UNIQUE on both sides, which makes the whole operation idempotent. Legacy vehicle_id verbatim is correct because legacy has NO brand

**Verify:** Per day against the manifest: select count(*), round(sum("TransAmount"::numeric),2) from mpesas where "TransTime" >= :d and "TransTime" < :d + interval '1 day'. Re-running a completed day must insert 0 rows and change no sum — that is the idempotency proof, and it should be exercised deliberately at least once. Orphan sweep after each day, both must be 0: transactions with a non-null mpesa_id havi

### [ ] 15. Between days, check CPUCreditBalance and pause. Hard-stop the run if it falls below 250 and resume the next night. Run the bulk inside 22:00-05:00 EAT.

`DAYS 3-8 · OPERATIONAL GUARD, runs between every import day · ROLLBACK: n/a, this is a stop condition`

**Why:** db.t4g.small has 576 credits earning 24/hour; two vCPUs at 100% burn ~120/hour, giving roughly 5-6 hours before the instance throttles to its 20% baseline. That throttle would hit the LIVE payment path, not just the import — and because C2bConfirmationController acks Safaricom AFTER the database work, a slow database directly delays the ack and triggers Safaricom retries. Legacy's own hourly counts show traffic collapsing to single digits between

**Verify:** aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name CPUCreditBalance --dimensions Name=DBInstanceIdentifier,Value=komiut-prod-db stays above 250 throughout; it returns to the 576.0 baseline observed today within a few hours of each session ending.

### [ ] 16. After each MONTH of transactions lands, regenerate that month's summaries with `php artisan app:generate-vehicle-summaries --date=YYYY-MM-DD` per day, skipping today. Do NOT import legacy summaries at all, and do not touch the SummarySync cursor.

`DAYS 4-9 · WRITE — PRODUCTION DATA · ~181 invocations · ROLLBACK: delete the summaries rows for the affected dates and re-run`

**Why:** summaries is derived, and importing it is doubly impossible: legacy has 545 duplicate (vehicle_id, trans_date) groups against Frankfurt's UNIQUE index (7 inside the window, one worst group of 47 rows for vehicle 877 on 2026-08-04), AND summaries_id_seq at 84,944 has already overtaken legacy's max id of 84,178, so the collision runs both ways. The recompute is absolute (SET, not increment), idempotent and self-correcting. --date is the right entry

**Verify:** Run each day with --dry-run first to see the diff, then for real. Final: select count(*) from (select vehicle_id, trans_date from summaries group by 1,2 having count(*)>1) x -> 0. Money tie-out: select round(sum(mpesa_amount)::numeric,2), sum(mpesa_txn) from summaries where trans_date >= '2026-02-26' and trans_date < '2026-08-26' must equal select round(sum(amount)::numeric,2), count(*) from trans

### [ ] 17. Reconcile end to end and PUBLISH the expected residual before anyone else finds it: the 572 window mpesas rows / KES 10,138,924.26 that carry money but no transaction (562 bank HO settlement sweeps typed 'Organization To Organization Transfer', plus ~35 rows where an M-Pesa statement narrative leake

`AFTER THE BACKFILL · READ-ONLY · 2 h`

**Why:** Roughly KES 22M and ~390,000 payments were never captured by legacy at all in that June window — the backfill cannot restore them and any reconciliation against Safaricom will show the hole. If it is not stated up front as an expected residual, the first person comparing Frankfurt to a Safaricom statement reads it as a failed migration and the cutover pauses for the wrong reason. Note one correction to publish alongside it: the KES 5.38M on short

**Verify:** select count(*), round(sum("TransAmount"::numeric),2) from mpesas m where "TransTime" >= '2026-02-26' and "TransTime" < '2026-08-26' and not exists (select 1 from transactions t where t.mpesa_id = m.id) -> 572 / 10,138,924.26. And select "TransTime"::date, count(*) from mpesas where "TransTime" between '2026-06-14' and '2026-06-26' group by 1 reproduces the legacy daily series (06-14: 32,889 then 

### [!] 18. Freeze the day-one SACCO list in writing before importing any operational data, and rule on places/routes/termini: APPEND the legacy reference set under new ids with an explicit legacy_id->frankfurt_id map, do NOT truncate the synthetic set.

`PARALLEL TRACK, SEPARATE OWNER · HUMAN DECISION`

**Why:** This is the track that actually gates 'SACCOs start using the new system', and it is not gated on the payment backfill — different data, different owner, run it concurrently. Scoping first is what makes it tractable: 25 of 39 legacy SACCOs have zero routes, zero termini, zero queues and zero bookings, NICCO MOVERS alone holds 12,164 of 12,575 queues and 1,439 of 1,458 bookings, and the 463-vehicle GITHURAI fleet is payments-only and needs none of

**Verify:** select count(*) from routes where name is null or name = '' -> currently 1972, confirming no mapping key exists. After the ruling: every route_stages.place_id and .route_id resolves through the map, zero orphans, and the sequences are setval'd past max(id).

### [ ] 19. Load the operational tier in dependency order, with two rows treated as separate explicit steps rather than column copies: derive route_stages.sequence as true travel order AFTER loading the rows, and REBUILD seat_arrangements from the seats grid rather than importing legacy's. Create sacco_termini 

`PARALLEL TRACK · WRITE — PRODUCTION DATA · several days · ROLLBACK: these tables are at 0 today, so a bounded DELETE of everything imported restores the current state exactly`

**Why:** Each of these fails silently rather than loudly. sequence left at its default 0 makes SegmentSeatAvailability's overlap test 0<0 for every row, so every seat reads free forever and matatus oversell with no error. Legacy seat_arrangements are wrong for their own classes — the 9-seater has 7 arrangements and covers 625 vehicles, and the 45/51-seater maps are swapped — so the trip list advertises seats the picker cannot offer. sacco_termini is 0 on 

**Verify:** Three assertions on stages, all zero rows: sequence = 0; any route where count(distinct sequence) <> count(*); any priced route whose origin is not the minimum sequence. Per seat class: count(seat_arrangements) = seats.seats for all five, max(row) <= rows, max(column) <= columns. select count(*) from sacco_routes where status and amount <= 1 -> 0. select count(*) from vehicles where seat_id is nul

### [ ] 20. Rehearse the complete passenger journey on one real launch route before declaring operational readiness: GET book_a_ride/routes -> queues -> seats -> fare -> POST booking/add -> STK push, then re-read seats to confirm the booked seat now reads occupied and a second overlapping booking is refused.

`PARALLEL TRACK · READ-ONLY · half a day`

**Why:** Every table above can pass its own row-count check while the journey still breaks at the join between two of them — a route with stages but no searchable pair, a fare that resolves to 1, a seat map whose ids the booking rejects. This is the only check that exercises FareResolver, SegmentSeatAvailability and the seat/arrangement relationship together. It is also the only check the realtime sync cannot provide, because legacy has had zero queues an

**Verify:** booking/add returns 200 with a server-set amount matching the fare endpoint (not 1, not 0); the immediately-following seats call returns the just-booked seat as occupied; a second booking of the same seat on an overlapping segment is refused with 'Seat N already booked'.

### [ ] 21. Migrate tills by re-registering ConfirmationURL, one at a time, only after ALL gates hold: 48 hours of continuous zero-unexplained-deficit reconciliation spanning two peak cycles; the pull job demonstrably recovering from an induced outage on each side; safiri unattributed = 0 on live traffic; the s

`CUTOVER · WRITE — one till at a time · ROLLBACK: re-register that till's ConfirmationURL back to the legacy payment tier, no DNS change, no bank involvement, no bulk data operation`

**Why:** Per-till re-registration is the only genuinely reversible unit of change in this whole programme, which is why C2bConfirmationController deliberately answers the same /api/confirmation/{mpesa_setting_id} shape legacy registers. The DNS flip was already rejected on a TLS hard stop and this is its replacement — do not quietly resurrect it. Rolling deploys must land before this point because today's largest single loss burst was both app instances r

**Verify:** Rehearse the ROLLBACK first, on a single low-volume till: re-register it to legacy, confirm within 15 minutes that new TransIDs for that shortcode appear in komiut_payments and stop appearing in Frankfurt's mpesa_logs, then re-register forward and confirm the reverse. A rollback path that has never been executed is not a rollback path. Then per migrated till: its shortcode's daily count and sum in

---

## Done so far (2026-08-26)

- [x] Named RDS snapshot `komiut-prod-db-precutover-20260826-0733` — available, 100%.
      Frankfurt also has 30-day automated backups, Multi-AZ and PITR back to 2026-08-07.
      (The "no backups" warning in the codebase applies to the LEGACY stack, not RDS.)
- [x] Advanced five sequences clear of legacy id space, guarded to only ever move up:
      `mpesas_id_seq` 20,307,855 → 25,000,000 · `transactions_id_seq` 21,312,760 → 26,000,000 ·
      `summaries_id_seq` 86,058 → 500,000 · `users_id_seq` 6,812 → 100,000 ·
      `saccos_id_seq` 52 → 100,000. Verified live: payments recording on the new id space.
      Closed a 711,014-row collision window on `mpesas` and 710,708 on `transactions`.

