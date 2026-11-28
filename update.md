# AlbaBook — Update plan

_Reviewed 27 Nov 2026 against the "AlbaBook — Product Design Flow" document and the current codebase._

Only features that make sense for AlbaBook are listed. Items the document
proposes that do not fit (see the end of the conversation, not this file)
were left out on purpose.

**Legend**

| Mark | Meaning |
|---|---|
| ⚪ White | Not started |
| 🟡 Yellow | Partially built: schema, engine or one side exists, the rest does not |
| 🟢 Green | Fully built (still subject to the MySQL 8 test run) |

**Priority:** P0 = before launch · P1 = first months after launch · P2 = when demand shows · P3 = growth phase

---

## 1. Booking engine & policies

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 1.1 | Server-calculated availability | 🟢 | Business hours, branch overrides, staff schedules, breaks, leave, blocked periods, closures, buffers, existing bookings, rooms/equipment with capacity, minimum notice, maximum advance. | — |
| 1.2 | Double-booking prevention | 🟢 | Staff → resource row locks at READ COMMITTED, slot holds, idempotency keys, customer-overlap check. Concurrency tests exist (`ParallelBookingTest`) but must pass on MySQL 8 (see 9.1). | — |
| 1.3 | Same rules for public, receptionist and API bookings | 🟢 | One `ManualBookingService`; staff override is an explicit permission (`appointments.override_policy`) and audited. | — |
| 1.4 | Auto-confirm vs manual approval | 🟢 | `ManualBookingService::initialStatus` reads `confirmation_mode` (branch row → tenant row → auto): customer bookings land `confirmed` or `pending`; staff bookings always confirm. Editable in Settings → Booking rules and the setup wizard. | — |
| 1.5 | Pending-request expiry | 🟢 | `PendingExpiryService` (cron sweep) expires requests older than `pending_expiry_minutes` or whose start time passed — version-checked, event history + `appointment_expired` customer notice. | — |
| 1.6 | Deposits at booking | 🟡 | `deposit_mode` / `deposit_value` / `deposit_due_minutes` exist, unused; STK push exists only at the till. Add: deposit rule per service/branch → booking created `pending_payment` with a short expiry → STK prompt on the booking page → confirmed on the M-Pesa callback. "Pay at venue, no deposit" stays the default. | P1 |
| 1.7 | Customer phone verification (OTP) | 🟢 | Settings → Booking Rules → "Confirm the customer's phone". 6-digit SMS code (HMAC-stored, 10 min, 5 tries, 3 codes/phone/15 min, 10/IP/hour), signed 30-min token required by the booking page **and** the public API. Skipped automatically when SMS can't deliver, so booking never breaks. | — |
| 1.8 | Don't merge customers on an unverified phone | 🟢 | Online bookings join an existing customer only with a verified phone, or a matching first name / e-mail; otherwise a separate customer is created (shows as a duplicate to merge). Staff bookings still match on phone. | — |
| 1.9 | Multiple services in one booking | 🟡 | `appointment_items` supports several services; booking page, dashboard modal and API book one. Salons routinely book "cut + colour". Add sequential multi-service booking (one staff or per-service staff) on top of the existing item model. | P1 |
| 1.10 | "Any available" assignment strategy | 🟡 | Today: first available staff. `staff_assignment_strategy` exists, unused. Add least-busy / round-robin so work is shared fairly. | P2 |
| 1.11 | New-customer booking policy | ⚪ | Allow a business (e.g. a clinic) to accept online bookings from existing customers only. Column `new_customers_allowed` exists, unused. | P2 |
| 1.12 | Manage-booking links | 🟢 | Links stop working 30 days after the visit; customers can no longer cancel or reschedule once the visit has started (no-show dodge closed). | — |

## 2. Business onboarding

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 2.1 | Owner sign-up, email verification, rate limits | 🟢 | Registration, verification, trial subscription, login and 2FA throttling. | — |
| 2.2 | Guided setup wizard | 🟢 | `/setup` after business creation: location & opening hours → first service → who takes bookings ("I work alone" links the owner) → working hours → booking rules → go live. Each step saves through the normal APIs, so leaving and resuming works; driven by `SetupReadiness`. | — |
| 2.3 | Dashboard setup checklist | 🟢 | `SetupReadiness` items on the dashboard summary for members who can act, each linking to its screen, hidden once all done. | — |
| 2.4 | Publish readiness gate | 🟢 | `SetupReadiness::blockers()` refuses `is_enabled` / publish (422 `NOT_READY` with reasons) unless email verified, active branch + hours, ≥1 online-bookable service with an eligible bookable staff member on a valid schedule. | — |

## 3. Schedules & team

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 3.1 | Branch opening hours, staff weekly schedules | 🟢 | Editable in Branches / Staff. | — |
| 3.2 | Breaks, time off/leave, closures & holidays, blocked time | 🟢 | `/time-off` page + `/api/availability/*`: weekly breaks, leave with approve/decline (`leave.approve`), branch closures/special hours, and block time for a staff member or whole branch (`calendar.manage_blocks`). | — |
| 3.3 | Team member lifecycle | 🟢 | `/team/members/{id}`: change roles (bounded by the actor's own permissions), set branch scope, deactivate (revokes sessions immediately), reactivate — all audited via `TeamMemberService`. | — |
| 3.4 | Staff see only their own appointments | 🟡 | `appointments.view_own` permission exists; confirm every list, calendar, report and export honours it, with tests. | P1 |

## 4. Customer booking experience

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 4.1 | Direct booking link, guest checkout, holds, confirmation, calendar file | 🟢 | `book.albabook.co.ke/{slug}`, English/Kiswahili, widget, public API. | — |
| 4.2 | Lightweight public page | 🟡 | The booking page loads all 25 dashboard scripts. Give the public shell its own minimal bundle (booking JS only, no dashboard pages) and measure on a 3G profile. | P1 |
| 4.3 | Clear review step | 🟡 | Review shows service, staff, time, price; add the cancellation policy and any deposit due before confirming (needed once 1.6 ships). | P1 |

## 5. Communication

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 5.1 | Event-driven notifications with outbox and retries | 🟢 | Booking writes outbox + queue rows in its transaction; workers send with backoff; a messaging outage never rolls back a booking. | — |
| 5.2 | SMS / email / WhatsApp senders, platform vs own number, Meta templates | 🟢 | Built; WhatsApp needs the 11 templates approved by Meta, SMS needs a registered sender ID with the provider. | — |
| 5.3 | Business-facing alerts | 🟡 | `AppointmentEventService::alertBusiness` emails every branch-scoped member with `appointments.confirm` on new online bookings and customer cancellations, plus optional SMS to the alert phone in Settings → Notifications. **Left:** alerts for failed payments. | P1 |
| 5.4 | Delivery receipts | 🟡 | `notification_logs.provider_message_id` exists, nothing writes it; status stops at "sent". Store provider message ids and accept delivery webhooks (Africa's Talking, Twilio, Meta) → delivered / failed / read in the delivery log. | P1 |
| 5.5 | Customer channel opt-out | 🟡 | `customer_consents` exists but is not checked before sending. Honour per-channel opt-out (e.g. SMS "STOP") for reminders; transactional confirmations still go out. | P2 |

## 6. Payments & tax

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 6.1 | Till: manual payments, split tender, refunds, discounts with permission, receipts | 🟢 | Idempotent, overpayment-capped, branch-scoped. | — |
| 6.2 | M-Pesa STK push from the till | 🟢 | Callback (HMAC URL), STK Query fallback, idempotent. Needs one end-to-end Safaricom sandbox run. | — |
| 6.3 | M-Pesa reconciliation of direct till/paybill payments | ⚪ | Customers often pay the till number directly. Add the C2B confirmation callback → `unmatched` payments (schema ready: `match_state`) → a "match to ticket" screen. | P1 |
| 6.4 | eTIMS tax invoices | 🟡 | Invoicing, submission, receipt block built. Needs KRA third-party-integrator certification in the sandbox; credit notes for refunds not built. | P1 |
| 6.5 | AlbaBook subscription billing | 🟢 | `SubscriptionBillingService` (cron sweep): trial reminder → expiry (read-only via FeatureGate) or activation invoice when a plan was chosen, renewal invoices before period end, past_due grace → overdue → expired. M-Pesa STK on the platform's Daraja app (signed callback, STK Query fallback, replay-safe settle); upgrade = prorated invoice, downgrade scheduled to next renewal. Billing page: plan picker + pay flow. Needs one Safaricom sandbox payment end to end before launch. | — |

## 7. Dashboard & reporting

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 7.1 | Overview, calendar day/week, appointments, activity timeline, customer history & notes | 🟢 | — | — |
| 7.2 | Pending-requests queue | 🟢 | Dashboard approvals panel lists pending requests with one-tap confirm/decline (transition API, `appointments.confirm`); `/appointments?status=pending` for the full queue. | — |
| 7.3 | Reports | 🟡 | Have: sales, bookings, top services, no-shows, punctuality, cancellations by reason, resource utilisation, commission, CSV export. Add **staff utilisation** (booked vs available hours) and **repeat-customer rate**. | P2 |

## 8. Platform (AlbaBook admin)

| # | Feature | Status | What exists / what to do | Priority |
|---|---|---|---|---|
| 8.1 | Tenant directory, lifecycle, plans, overrides, audited support access, leads | 🟢 | — | — |
| 8.2 | Operations dashboard | 🟡 | Only `/health`. Add a platform page for queue backlog, failed notifications/webhooks, pending M-Pesa prompts, unsent eTIMS invoices — the things that silently hurt businesses. | P1 |

## 9. Quality gates

| # | Item | Status | What to do | Priority |
|---|---|---|---|---|
| 9.1 | Test suite green on MySQL 8 | 🟢 | Full suite on MySQL 8.4 (Docker, port 33060): 305 tests, 2455 assertions — only failures were 9 stale `pending`→`confirmed` expectations (auto-confirm default), now fixed and re-verified on both engines. | P0 |
| 9.2 | Mobile & low-bandwidth check | 🟢 | Public shells now load only their page scripts via `page_scripts` — booking page is ~147KB total (18KB HTML + 43KB JS + 86KB CSS), down from ~400KB+ JS. Physical-device 3G spot-check still recommended pre-launch. | P0 |

## 10. Growth (after direct booking is proven with real businesses)

| # | Feature | Status | Why it fits | Priority |
|---|---|---|---|---|
| 10.1 | Marketplace discovery (search by service, area, date) | ⚪ | Reuses the same booking engine; only worth it once enough businesses are live to make search useful. | P3 |
| 10.2 | Packages & gift vouchers | ⚪ | Common salon/spa revenue (e.g. "5 massages"); builds on the till and payments. | P3 |
| 10.3 | Promo codes on the booking page | ⚪ | Lets businesses run their own offers; reuses discount rules from the till. | P3 |

---

## Summary

| Status | Count |
|---|---|
| 🟢 Green | 26 |
| 🟡 Yellow | 12 |
| ⚪ White | 5 |

**Do first (P0):** ~~1.4 auto-confirm / manual approval~~ ✓, ~~1.5 pending expiry~~ ✓, ~~2.2 setup wizard~~ ✓, ~~2.3 checklist~~ ✓, ~~2.4 publish gate~~ ✓, ~~3.2 breaks/leave/closures screens~~ ✓, ~~3.3 team member lifecycle~~ ✓, ~~5.3 business alerts~~ ✓ (payment-failure alerts moved to P1), ~~7.2 pending queue~~ ✓, ~~6.5 subscription billing~~ ✓ (sandbox payment run pending), ~~9.2 mobile check~~ ✓ (bundle now lean per shell), ~~9.1 tests on MySQL 8~~ ✓ (305 tests green on MySQL 8.4). **All P0 items are done.**
