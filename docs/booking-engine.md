# AlbaBook — Booking Engine Specification

Status: **Authoritative** for scheduling, availability, holds, appointments, booking payments and booking references.
Where this document and `plan.md` §9–§10 differ, this document wins (see `decisions.md`).

The booking engine is the highest-risk component of AlbaBook. Every rule below exists to make one promise true:

> A customer who receives a confirmation has a real appointment, with a real staff member and real resources, that nobody else holds.

---

## 1. Invariants

These are release-blocking. Each one must have automated tests (§20).

| ID | Invariant |
| -- | --------- |
| I1 | No two time-blocking occupancies of the same staff member overlap, buffers included, unless an authorised user explicitly overrode the conflict (§12) and the override was audited. |
| I2 | At any instant, the number of time-blocking allocations of a resource never exceeds its `capacity`. |
| I3 | Every write that creates or moves time (public booking, dashboard booking, API, calendar drag-and-drop, reschedule, import) goes through `BookingService`. No other code inserts into `appointment_items` or `appointment_resources`. |
| I4 | Availability shown to a user is advisory. The booking transaction is the only authority. |
| I5 | Repeating a request with the same idempotency key returns the original result and never creates a second appointment. |
| I6 | Appointment item snapshots (name, price, duration, currency) never change because a service, variant or price changed later. |
| I7 | Instants are stored in UTC. Schedule rules (opening hours, shifts, breaks) are stored as local wall-clock times in the branch timezone. |
| I8 | A booking reference is an identifier, never a credential. Access to a booking from the public side requires a management token (§15). |

---

## 2. Vocabulary

| Term | Meaning |
| ---- | ------- |
| Booking request | The customer's selection: branch, ordered list of services (variant + add-ons), staff preference, start time, customer details. |
| Item | One service inside an appointment. Items run back-to-back. |
| Occupancy | The time a staff member or resource is unavailable because of an item: `[start_at − buffer_before, end_at + buffer_after)`. |
| Hold | A short-lived occupancy reserved while the customer finishes checkout. Holds block time exactly like appointments. |
| Slot | A start time at which the whole booking request fits. |
| Lock anchor | A `staff` or `resources` row locked `FOR UPDATE` to serialise writers on that entity (§9). |
| Time-blocking status | An appointment status whose occupancy blocks availability (§10). |

All intervals are half-open: `[start, end)`. An appointment ending at 10:00 does not conflict with one starting at 10:00.

---

## 3. Time model

- Each branch has `timezone` (IANA, e.g. `Africa/Nairobi`). `NULL` inherits the tenant timezone. The engine always works in the **branch** timezone; `TenantContext.timezone` is only the default.
- Opening hours, staff shifts and breaks are stored as `weekday + local TIME`. They are never converted to UTC at rest, because the UTC offset of a wall-clock time can change (DST, government changes).
- Appointment, hold, leave and block instants are stored as UTC `DATETIME`. The DB connection runs `SET time_zone = '+00:00'` on connect and PHP runs with `date.timezone = UTC`. `DATETIME DEFAULT CURRENT_TIMESTAMP` columns are only correct because of that session setting — the bootstrap must enforce it and a test must assert it.
- All conversions go through `DateTimeService`. No `date()`/`strtotime()` in modules.
- DST handling (for tenants outside Kenya): a local time that does not exist is skipped; an ambiguous local time resolves to its first occurrence.
- Overnight hours (`closes_at <= opens_at`, e.g. 18:00–02:00) are valid and span midnight. They belong to the weekday on which they open.
- "Today", "tomorrow" and day-level reports use the branch's local day, converted to a UTC range: `[local 00:00, next local 00:00)`.
- Time is read through an injectable `Clock` interface so tests can freeze and move time.

---

## 4. Scheduling data model

Column lists are the minimum. All tables carry `tenant_id`, a `UNIQUE (tenant_id, id)` key, and composite foreign keys to their tenant-owned parents.

### 4.1 Working time

| Table | Key columns | Notes |
| ----- | ----------- | ----- |
| `business_hours` | `branch_id, weekday, opens_at, closes_at, effective_from, effective_to` | Several rows per weekday allowed (split hours). |
| `staff_schedules` | `staff_id, branch_id, weekday, starts_at, ends_at, effective_from, effective_to` | A staff member can work at different branches on different days. |
| `staff_breaks` | `staff_id, weekday, starts_at, ends_at, effective_from, effective_to` | Recurring breaks. |
| `schedule_overrides` | `branch_id NULL, staff_id NULL, resource_id NULL, local_date, is_closed, opens_at, closes_at` | Date-specific hours: public holidays, late openings, one-off shifts. Replaces the recurring rule for that date. Exactly one scope column is set (`CHECK` constraint), and each is a composite tenant FK. |
| `blocked_periods` | `branch_id NULL, staff_id NULL, resource_id NULL, starts_at, ends_at (UTC), reason, created_by_user_id` | Ad-hoc blocks, maintenance. Replaces the separate `blocked_dates` idea. Exactly one scope column is set, same as overrides. |
| `staff_leave` | `staff_id, starts_at, ends_at (UTC), type, status (requested, approved, rejected, cancelled)` | Only `approved` leave blocks time. |

### 4.2 What a service needs

| Table | Key columns | Notes |
| ----- | ----------- | ----- |
| `services` | `duration_minutes, buffer_before_minutes, buffer_after_minutes, price_type, …` | Base definition. |
| `service_variants` | `service_id, duration_minutes, price_amount, …` | Overrides duration/price. |
| `service_addons` | `service_id, extra_minutes, price_amount` | Adds time and price to the item. |
| `service_branches` | `service_id, branch_id, price_amount NULL, is_bookable_online` | Where a service is offered; optional branch price. |
| `staff_services` | `staff_id, service_id, price_amount NULL, duration_minutes NULL` | Eligibility; optional staff-specific price/duration. |
| `resource_types` | `name` | e.g. "Treatment room", "Massage bed". |
| `resources` | `branch_id, resource_type_id, capacity (default 1), status` | Lock anchor. |
| `service_resource_requirements` | `service_id, variant_id NULL, resource_type_id, quantity, occupancy (item, item_with_buffers)` | A service requires a resource **type**; the engine picks a concrete resource. |

### 4.3 Booking policy

Booking policies are queried on every availability call, so they live in a dedicated typed table, not in `tenant_settings` JSON.

`booking_policies` — one row with `branch_id IS NULL` per tenant, optional rows per branch. A `NULL` column on a branch row inherits the tenant row; a `NULL` on the tenant row inherits `config/booking.php`.

| Column | Default | Meaning |
| ------ | ------- | ------- |
| `slot_interval_minutes` | 15 | Grid for candidate start times. |
| `min_notice_minutes` | 60 | Earliest bookable start relative to now. |
| `max_advance_days` | 60 | Latest bookable start. |
| `hold_ttl_seconds` | 300 | Hold lifetime. |
| `confirmation_mode` | `auto` | `auto` → confirmed on creation; `manual` → pending until the business confirms. |
| `pending_expiry_minutes` | 1440 | `manual` mode: unconfirmed requests expire and release time. |
| `staff_assignment_strategy` | `least_booked_day` | §7. |
| `customer_changes` | `self_service` | `self_service`, `request`, `disabled` for customer cancel/reschedule. |
| `cancellation_cutoff_minutes` | 1440 | Later customer cancellations are blocked or flagged `late`. |
| `reschedule_cutoff_minutes` | 1440 | |
| `max_reschedules` | 2 | Per appointment, customer-initiated. |
| `deposit_mode` | `none` | `none`, `fixed`, `percent`. |
| `deposit_value` | NULL | Minor units for `fixed`, basis points for `percent`. |
| `deposit_due_minutes` | 30 | Unpaid deposit after this → appointment expires. |
| `customer_verification` | `none` | `none`, `otp_phone`, `otp_email` before a booking is accepted. |
| `max_active_bookings_per_contact` | 3 | Future time-blocking bookings per phone/email. Anti-abuse. |
| `customer_match` | `phone` | `phone`, `email`, `phone_or_email`, `none` (§16). |
| `policy_version` | — | Incremented on change; snapshotted on each appointment. |

The resolved policy is a typed `BookingPolicy` DTO produced by `BookingPolicyResolver`. Nothing else reads the table.

---

## 5. Occupancy model

Each appointment item stores both customer time and occupied time:

```text
appointment_items
  start_at, end_at                     -- what the customer sees
  occupied_start_at, occupied_end_at   -- start − buffer_before, end + buffer_after
  staff_id
```

Each resource allocation stores its own window (`item` or `item_with_buffers`, from the requirement):

```text
appointment_resources
  appointment_item_id, resource_id, occupied_start_at, occupied_end_at
```

Holds mirror this in `booking_hold_items`.

Staff is assigned **per item**. `appointments.staff_id` is the primary staff member for list views only; the engine never reads it.

Every availability and conflict query uses the `occupied_*` columns:

```sql
SELECT 1
FROM appointment_items ai
JOIN appointments a
  ON a.tenant_id = ai.tenant_id AND a.id = ai.appointment_id
WHERE ai.tenant_id = :tenant_id
  AND ai.staff_id IN (:staff_ids)
  AND a.status IN ('pending', 'confirmed', 'checked_in')
  AND ai.occupied_start_at < :requested_end
  AND ai.occupied_end_at   > :requested_start
  AND a.id <> :excluded_appointment_id      -- reschedule only
LIMIT 1;
```

Required indexes:

```sql
CREATE INDEX idx_items_staff_occupancy
  ON appointment_items (tenant_id, staff_id, occupied_start_at, occupied_end_at);
CREATE INDEX idx_alloc_resource_occupancy
  ON appointment_resources (tenant_id, resource_id, occupied_start_at, occupied_end_at);
CREATE INDEX idx_hold_items_staff_occupancy
  ON booking_hold_items (tenant_id, staff_id, occupied_start_at, occupied_end_at);
```

Buffers must fit inside the staff member's working time. A 30-minute cleanup cannot run past closing.

---

## 6. Availability algorithm

`AvailabilityService::findSlots(AvailabilityQuery $q): SlotCollection`

Input: tenant (from `TenantContext`), branch, ordered items (service, variant, add-ons), staff preference (`specific` id or `any`), local date range, channel (`public`, `staff`, `api`), optional `exclude_appointment_id` (reschedule).

1. **Resolve definitions.** Load the policy, and for each item compute duration (variant or staff override, plus add-ons) and buffers. Reject inactive or not-bookable-online services on the `public` channel.
2. **Eligible staff.** Active, scheduled at the branch, eligible for every item, bookable online (public channel). MVP: one staff member performs the whole sequence (§22).
3. **Load busy data in bulk.** One query each for appointment occupancies, active hold occupancies, approved leave, blocked periods and resource allocations, covering the whole range. No per-slot queries.
4. **Build free time per staff per local day** using an `IntervalSet` value object (union, intersection, subtraction). Do not use minute arrays.

   ```text
   free = (branch hours ∩ staff schedule, after date overrides)
          − breaks − approved leave − blocked periods
          − appointment occupancies − active hold occupancies
   ```
5. **Generate candidates** on the `slot_interval_minutes` grid, anchored at local midnight (so a 15-minute grid gives 09:00, 09:15… even if a shift starts at 09:10 → first slot 09:15).
6. **Fit.** A candidate fits a staff member when the occupancy of the whole sequence (first item's `buffer_before` to last item's `buffer_after`) lies inside one free interval.
7. **Resources.** For each item requirement, at least one resource of the type at the branch has spare capacity for the item's resource window.
8. **Policy filters.** `start ≥ now + min_notice`, `start ≤ now + max_advance`, per-contact limits are checked at booking, not here.
9. **Output.** Slots in branch-local time with their UTC instant. The internal result keeps candidate staff/resources per slot; the public response never includes them unless the customer chose a specific staff member, and never includes any other customer data.

Two read endpoints:

- **Days summary** — for a date range (max 62 days), which local dates have at least one slot. Used to disable dates in the calendar.
- **Slots** — all slots for one local date.

### Caching

Correctness never depends on a cache. Optionally cache computed slots for 30–60 seconds under a versioned key:

```text
tenant:{tenantId}:branch:{branchId}:avail:{localDate}:v{scheduleVersion}
```

`scheduleVersion` is a counter per `(tenant, branch, local_date)` incremented in the same transaction as any write that changes time on that date (booking, hold, cancellation, reschedule, schedule edit, leave approval). Stale cache costs a "slot just taken" message, never a double booking.

---

## 7. Staff assignment ("any available")

Applied when the customer picks "any staff". Strategy from policy:

| Strategy | Rule |
| -------- | ---- |
| `least_booked_day` (default) | Fewest booked minutes on that local day. Spreads work. |
| `round_robin` | Least recently assigned staff for this service at this branch. |
| `priority` | Lowest `staff.booking_priority`, then the above. |

Ties break by `staff.id` ascending so results are deterministic and testable.

Staff is chosen when the hold is created and re-validated at commit. If the chosen staff member is no longer free at commit and the customer chose "any", the engine may pick another eligible staff member (bounded retries, §9.4). If the customer chose a specific staff member, it fails with `SLOT_UNAVAILABLE` and offers alternatives.

---

## 8. Booking holds

A hold is an occupancy. It must be created under the same lock protocol as an appointment (§9) — otherwise two holds can collide, and the hold guarantees nothing.

```text
booking_holds
  id, tenant_id, branch_id
  token_hash            -- SHA-256 of the random token returned once to the client
  status                -- active, converted, released, expired
  expires_at            -- UTC
  request_hash          -- hash of the normalised booking request
  client_fingerprint    -- hash of IP + user agent, for abuse limits
  extended_count
  created_at

booking_hold_items
  tenant_id, hold_id, item_position, staff_id
  occupied_start_at, occupied_end_at

booking_hold_resources
  tenant_id, hold_id, item_position, resource_id
  occupied_start_at, occupied_end_at
```

Rules:

- **Active** means `status = 'active' AND expires_at > UTC_TIMESTAMP()`. Expiry is evaluated in every query. The cleanup worker only marks expired rows and deletes old ones — correctness never waits for it.
- TTL comes from `hold_ttl_seconds`. A hold may be extended **once** (e.g. to cover an M-Pesa STK prompt), up to a platform maximum.
- A client may hold at most 2 active holds per tenant. Creating a new hold for the same client releases its previous hold.
- Hold creation is rate limited per IP and per tenant.
- On confirmation, the request must match the hold's `request_hash` (same branch, items, staff, start). A different request needs a new hold.
- Explicit release (customer goes back, closes checkout) sets `released`.
- Holds are optional for staff bookings in the dashboard; the booking transaction is authoritative either way.

---

## 9. Concurrency protocol

### 9.1 Why plain SQL is not enough

MySQL has no exclusion constraint, and no unique index can express "intervals must not overlap". A `SELECT` overlap check followed by `INSERT` lets two transactions both see "free" and both insert. Gap locks on range queries are fragile and deadlock-prone.

AlbaBook therefore **serialises writers per schedulable entity** by locking that entity's row.

### 9.2 Isolation level — the subtle trap

Under MySQL's default `REPEATABLE READ`, a plain `SELECT` reads the snapshot taken at the transaction's first read. If any read happens before the lock is acquired, the overlap check after the lock wait reads a stale snapshot and misses the row the other transaction just committed. Result: a double booking, even with locks.

Rules:

- Booking-write transactions run at `READ COMMITTED`: `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` before `BEGIN`.
- The lock anchors are the **first** statements inside the transaction.
- Overlap re-checks happen **after** the locks are held.
- `innodb_lock_wait_timeout` is lowered for the session (e.g. 5 seconds) so a stuck writer fails fast.

### 9.3 Transaction steps

Outside the transaction: validate input, resolve tenant and policy, fast-path idempotency lookup, compute the candidate staff/resources.

```text
SET TRANSACTION ISOLATION LEVEL READ COMMITTED
BEGIN

1. Claim the idempotency key
   INSERT INTO booking_idempotency_keys
     (tenant_id, scope, idempotency_key, request_hash, status)
   VALUES (?, 'booking.create', ?, ?, 'processing')
   -- duplicate key → see §9.5

2. Lock anchors, globally ordered: staff ascending id, then resources ascending id
   SELECT id FROM staff
    WHERE tenant_id = ? AND id IN (...) ORDER BY id FOR UPDATE
   SELECT id FROM resources
    WHERE tenant_id = ? AND id IN (...) ORDER BY id FOR UPDATE

3. Re-validate
   - tenant status allows booking (TenantAccessChecker)
   - policy: notice, window, per-contact limit, verification
   - hold: active, same tenant, request_hash matches (if a hold token was given)
   - schedule: effective business hours, staff schedules, date overrides
     (branch, staff and resource scopes), breaks still permit the window
   - staff unavailability: approved leave, blocked periods (staff and branch
     scopes) and closed overrides do not intersect the requested occupancy
   - staff overlap: appointments + other active holds (excluding own hold,
     excluding the appointment being rescheduled)
   - resource capacity: count overlapping allocations < capacity, and the
     resource is not on leave/blocked/maintenance for its window
   - staff/resource still active and eligible

4. Write
   - match or create the customer (§16)
   - appointments, appointment_items (snapshots), appointment_resources
   - appointment_events ('created')
   - outbox_events ('appointment.created')
   - hold → 'converted'
   - schedule version counter(s) + 1
   - idempotency key → 'completed' with response body

COMMIT
```

Any failed validation rolls back. No external HTTP call happens between `BEGIN` and `COMMIT` — notifications, webhooks and payment prompts are outbox events.

### 9.4 Retries

- Deadlock (`1213`) or lock wait timeout (`1205`): roll back, wait with jitter, retry the whole transaction. Maximum 3 attempts.
- "Any staff" conflict at step 3: roll back and retry with the next candidate staff member. Never lock an extra staff row mid-transaction — that breaks the global lock order.

### 9.5 Idempotency

`booking_idempotency_keys (tenant_id, scope, idempotency_key, request_hash, status, response_code, response_body, expires_at)`, `UNIQUE (tenant_id, scope, idempotency_key)`, kept 24 hours.

| Situation | Result |
| --------- | ------ |
| Key unseen | Proceed. |
| Same key, same hash, `completed` | Return stored response, create nothing. |
| Same key, still `processing` | `409 IDEMPOTENCY_IN_PROGRESS`, client retries. |
| Same key, different hash | `422 IDEMPOTENCY_CONFLICT`. |

Because the key is inserted inside the transaction, a rolled-back attempt leaves no key and can be retried. A concurrent duplicate blocks on the unique index until the first commits, then sees `completed`.

The public booking page generates the key in the browser when checkout starts. The API requires an `Idempotency-Key` header on `POST`.

### 9.6 Who else takes lock anchors

Any operation that changes a staff member's or resource's time takes the same anchors in the same order: holds, bookings, reschedules, cancellations, adding leave, adding blocked periods, editing schedules. When leave or a schedule change conflicts with existing appointments, the operation returns the conflicting appointments and requires an explicit decision; it never silently orphans bookings.

### 9.7 Defence in depth

A scheduled `BookingIntegrityCheck` job scans recent and future occupancies for any I1/I2 violation and alerts platform operators. It should never find anything; if it does, it is a release-blocking bug.

---

## 10. Appointment lifecycle

### 10.1 Statuses

| Status | Blocks time | Meaning |
| ------ | ----------- | ------- |
| `pending` | Yes | Awaiting business approval (`manual` mode) and/or deposit. |
| `confirmed` | Yes | Accepted. |
| `checked_in` | Yes | Customer arrived (optional step). |
| `completed` | No (past) | Service delivered. |
| `no_show` | No (past) | Customer did not arrive. |
| `cancelled` | No | Cancelled by customer, business or system. |
| `rejected` | No | Business declined a pending request. |
| `expired` | No | Pending request not confirmed in time, or deposit not paid by `deposit_due_at`. |

`rescheduled` is an **event**, not a status. The reference stays the same.

Separate columns, separate concerns:

- `status` — lifecycle above.
- `payment_status` — `not_required`, `unpaid`, `deposit_paid`, `partially_paid`, `paid`, `partially_refunded`, `refunded` (derived from payments, §14).
- Notification state — lives in notification logs, never on the appointment.

### 10.2 Transitions

| From | To | Actor | Conditions |
| ---- | -- | ----- | ---------- |
| — | `pending` / `confirmed` | Booking | `confirmation_mode`, deposit policy. |
| `pending` | `confirmed` | Business, system (deposit paid in auto mode) | Permission `appointments.confirm`. |
| `pending` | `rejected` | Business | Reason required. |
| `pending` | `expired` | System | Pending expiry or deposit due time passed. |
| `pending`, `confirmed` | `cancelled` | Customer, business, system | Customer: policy + cutoff. |
| `confirmed` | `checked_in` | Business | From `start_at − 60 min`. |
| `confirmed`, `checked_in` | `completed` | Business, system | After `start_at`. Optional auto-complete job. |
| `confirmed` | `no_show` | Business | After `start_at + grace`. |
| `completed`, `no_show` | `confirmed` | Business | Correction only, within 7 days, audited. |

Every transition:

- Goes through `AppointmentStateMachine`; invalid transitions throw.
- Uses optimistic concurrency: `UPDATE … WHERE tenant_id = ? AND id = ? AND version = ?`; zero rows updated → `409 STALE_APPOINTMENT`.
- Writes an `appointment_events` row (actor type, actor id, from, to, reason, metadata) and an outbox event in the same transaction.

### 10.3 Cancellation

Store `cancelled_by` (`customer`, `business`, `system`), `cancellation_reason`, `cancelled_at`, and `is_late` (inside the cutoff). Late customer cancellations are blocked or allowed-and-flagged depending on policy. Deposit forfeiture is a policy decision recorded on the appointment; refunds are separate payment records (§14).

---

## 11. Rescheduling

Rescheduling is a booking write and follows §9 exactly:

- Lock the anchors for the old **and** new staff/resources together, in the global order.
- Re-check the new time excluding the appointment's own occupancy (`exclude_appointment_id`).
- Update item times and occupancies in place; prices are not recalculated (a change of service is an edit, not a reschedule).
- Increment `reschedule_count` (customer-initiated) and `version`; write an event with old and new times.
- Customer reschedules respect `customer_changes`, `reschedule_cutoff_minutes` and `max_reschedules`. In `request` mode the customer creates a reschedule request that the business accepts or declines; the original time stays held until then.
- Calendar drag-and-drop calls the same service. The UI moves the card only after the server confirms.

---

## 12. Staff bookings and overrides

Dashboard bookings use the same `BookingService` with channel `staff`. Two explicit permissions allow overriding rules — never implicitly:

| Permission | Allows |
| ---------- | ------ |
| `appointments.override_policy` | Ignoring min notice, max window, online visibility, booking outside opening hours. |
| `appointments.override_conflict` | Creating a deliberate overlap (double-booking). Requires a reason, shows a warning, is audited and flagged on the calendar. Granted to the Owner role only by default. |

Walk-ins are staff bookings with `booking_source = 'walk_in'` and `start_at = now` (rounded).

`booking_source`: `public_page`, `widget`, `api`, `dashboard`, `walk_in`, `import`.

---

## 13. Pricing and snapshots

`PricingService` resolves price per item in this order: staff-specific → branch-specific → variant → service base, then adds add-ons.

Price types:

- `fixed` — price is final.
- `from` — shown as "From KSh 1,500"; final amount set when completing the appointment.
- `range` — shown as "KSh 1,500 – 3,000"; final amount set on completion.

Each `appointment_items` row snapshots: service id, variant id, service name, variant name, add-ons (JSON: name, minutes, amount), `price_type`, `unit_amount_minor`, `min_amount_minor`, `max_amount_minor`, `currency`, `duration_minutes`, buffers, `quantity`, and the `policy_version` in force. The appointment stores `total_amount_minor`, `deposit_amount_minor`, `deposit_due_at` and `currency`.

All items in one appointment share the tenant currency.

Tax (`decisions.md` D-015): prices are VAT-inclusive. When the tenant is VAT-registered, each item also snapshots `tax_rate_bp` and `tax_amount_minor`, computed as `round(gross × rate / (10000 + rate))`. Customer documents are labelled "Not a tax invoice"; tenants issue tax receipts through their own eTIMS.

The appointment also stores `locale` (the language the customer booked in), which notifications use (`decisions.md` D-017).

Money is stored as `BIGINT` minor units with an explicit ISO 4217 currency (see `decisions.md` D-006). Formatting happens only in `CurrencyFormatter`.

---

## 14. Booking payments and deposits

Customer payments to a tenant are a different domain from the tenant's subscription payments to AlbaTech. They never share tables.

### 14.1 Tables

```text
payments
  id, uuid, tenant_id, branch_id
  appointment_id NULL, customer_id NULL
  kind                  -- deposit, balance, full, other
  method                -- cash, mpesa, card, bank_transfer, other
  provider NULL         -- e.g. 'mpesa_daraja' once automated
  provider_reference NULL
  amount_minor, currency
  status                -- pending, succeeded, failed, cancelled, unmatched
  received_at NULL      -- UTC
  recorded_by_user_id NULL
  idempotency_key NULL
  match_state           -- n/a, unmatched, matched, dismissed (C2B only)
  matched_by_user_id NULL, matched_at NULL
  notes
  UNIQUE (tenant_id, provider, provider_reference)
  UNIQUE (tenant_id, id)
  UNIQUE (tenant_id, idempotency_key)   -- NULLs allowed; manual/API writes only

refunds
  id, tenant_id, payment_id, amount_minor, currency
  status, reason, refunded_at, recorded_by_user_id

payment_provider_events
  id, tenant_id NULL, provider, provider_event_id
  signature_valid, payload (JSON), received_at, processed_at
  UNIQUE (provider, provider_event_id)
```

An unmatched C2B payment is a normal `payments` row with `status = 'unmatched'`, `appointment_id NULL` and `match_state = 'unmatched'`. The review queue is a query (`WHERE status = 'unmatched'`), not a separate table. Matching sets the appointment, `status = 'succeeded'` and `match_state = 'matched'`; dismissing sets `match_state = 'dismissed'` and `status = 'cancelled'`. Unmatched rows never count toward collected revenue.

### 14.2 Rules

- MVP supports **manually recorded** payments (cash, M-Pesa till/paybill entered by staff, card terminal). This makes revenue reporting correct from day one.
- `appointments.payment_status` is recalculated in the same transaction as any payment or refund write. It is a cache of the payment rows, never edited directly.
- Revenue "collected" = succeeded payments by `received_at` − succeeded refunds by `refunded_at`, over the same period. The two sides use their own timestamps so a refund of an earlier payment lands in the period it happened. Revenue "booked" = `total_amount_minor` of time-blocking and completed appointments, by `start_at`. Dashboards label which one they show.
- `payments.status = 'unmatched'` rows are excluded from collected revenue and from `appointments.payment_status` until matched or dismissed.
- Permissions: `payments.view`, `payments.record`, `payments.refund`.

### 14.2a M-Pesa (`decisions.md` D-016)

Customer money always goes to the tenant's own till or paybill. AlbaTech never collects or settles customer funds.

```text
tenant_payment_accounts
  id, tenant_id, branch_id NULL
  provider              -- mpesa
  account_type          -- till, paybill
  shortcode, display_name
  credentials_encrypted NULL   -- Daraja consumer key/secret + passkey, only for STK push
  status                -- active, pending_golive, disabled
```

1. **MVP:** checkout and confirmation show the till/paybill and instructions; staff record the M-Pesa code as a manual payment.
2. **Paybill auto-matching:** the customer uses the booking reference as the account number. A verified C2B confirmation callback creates a `payments` row and matches it by `(tenant_id, reference)`. Payments that match no booking, or whose amount does not match, are written with `status = 'unmatched'` and surface in the review queue; they are never attached by guesswork. Tills have no account number, so till payments stay manual until step 3.
3. **STK push:** initiated by an outbox worker using the tenant's own Daraja credentials, outside any booking transaction (§14.3).

### 14.3 Deposit flow (when `deposit_mode ≠ none`)

1. Booking commits with `status = pending`, `payment_status = unpaid`, `deposit_due_at = now + deposit_due_minutes`. The appointment blocks time.
2. Outbox event triggers the payment request (later: M-Pesa STK push) **outside** the transaction.
3. A verified provider callback (or staff recording the payment) inserts a `payments` row. Duplicate callbacks are ignored via `payment_provider_events`.
4. Deposit covered → `payment_status = deposit_paid`; in `auto` mode the appointment moves to `confirmed`.
5. `deposit_due_at` passes unpaid → system moves the appointment to `expired`, releasing the time and notifying the customer.
6. A payment that arrives after expiry is recorded and flagged for refund or manual rebooking. It never silently revives the appointment, because the time may already be taken.

---

## 15. References and management links

### Booking reference

- 8 characters of Crockford Base32 from a CSPRNG, displayed as `AB-7K3Q-9XPM`. No `I`, `L`, `O`, `U`; case-insensitive input.
- `UNIQUE (tenant_id, reference)`; regenerate on collision.
- It is what customers read over the phone. It is **not** a credential.

### Management token

```text
appointment_access_tokens
  id, tenant_id, appointment_id
  token_hash          -- SHA-256 of 32 random bytes, base64url in the link
  purpose             -- manage
  expires_at          -- appointment end + 30 days
  revoked_at NULL
  last_used_at NULL
```

- Link: `https://book.albabook.co.ke/{slug}/manage/{token}`, sent in confirmations.
- The plaintext token is never stored or logged. Access logs and error logs redact the token segment of `/manage/*` and `/v1/public/bookings/*`.
- Manage pages send `Referrer-Policy: no-referrer`, `Cache-Control: no-store` and `X-Robots-Tag: noindex`.
- **Lookup without the link:** the customer enters reference + phone or email. If they match, a fresh manage link is sent to the contact on file. The response is identical whether or not they matched — no booking details are ever shown on the lookup page.
- Business staff can resend or revoke tokens.

---

## 16. Customer identity and abuse protection

- Phone numbers are normalised to E.164 by `PhoneNumberService` (country default from branch). Store `phone_e164` and the raw input. `customers.phone_e164` and `customers.email` are searchable but **not unique**; one person may legitimately use the same contact at several businesses, and a tenant may hold duplicate records it has not merged.
- `customer_match` policy decides how a guest booking attaches to an existing customer. Matching never overwrites the stored customer's name; the name as entered is snapshotted on the appointment (`customer_name_snapshot`) and differences are flagged for staff.
- Identity uniqueness is enforced by `customer_match_keys`, not by partial unique indexes:

  ```text
  customer_match_keys
    tenant_id, customer_id
    match_type            -- phone, email
    match_value           -- phone_e164 or lowercased email
    UNIQUE (tenant_id, match_type, match_value)
    FOREIGN KEY (tenant_id, customer_id) REFERENCES customers (tenant_id, id)
  ```

  - `phone`: only phone keys are written.
  - `email`: only email keys are written.
  - `phone_or_email`: both keys are written; a booking matches if either key exists.
  - `none`: no keys are written, duplicates are allowed.
- A booking transaction looks up keys first. Creating a customer inserts the customer and its keys in the same transaction; a duplicate-key error means another transaction created the same identity, so the whole transaction is retried (§9.4) and the retry matches the existing customer. Keeping keys in a separate table means no nullable-unique generated columns and a policy change is a deliberate staff reconciliation, never a schema migration.
- Changing `customer_match` does not retroactively rewrite keys. Staff can merge duplicates; a merge moves keys, repoints appointments, and keeps the losing record as a soft-deleted alias so historical references still resolve.
- The public flow never reveals whether a phone or email is already known.
- Abuse controls: rate limits on holds and bookings per IP and per tenant, a honeypot field, `max_active_bookings_per_contact`, optional OTP verification (`customer_verification`). OTPs are stored hashed in `booking_verification_codes` (`auth.md` §11), scoped to the tenant and purged after expiry. Third-party challenge widgets are optional and need a privacy review.
- Consent is captured per channel and purpose: transactional messages vs marketing, and WhatsApp opt-in, stored in `customer_consents` with timestamp and source.

---

## 17. Notifications and reminders

- The engine emits outbox events only: `appointment.created`, `.confirmed`, `.rejected`, `.rescheduled`, `.cancelled`, `.expired`, `.completed`, `.no_show`, `payment.recorded`.
- Reminders are rows in `scheduled_notifications (tenant_id, appointment_id, appointment_version, kind, send_at, status)`.
- At send time the worker reloads the appointment and **skips** if it is no longer time-blocking or its `version` changed. Rescheduling creates fresh reminder rows.
- A reminder whose `send_at` is already in the past at booking time is not created.
- Quiet hours are evaluated in the branch timezone.

---

## 18. Public booking API contract

Public and private endpoints are separated by path so they can never collide (`decisions.md` D-008).

```text
GET    /v1/public/businesses/{slug}
GET    /v1/public/businesses/{slug}/services
GET    /v1/public/businesses/{slug}/branches
GET    /v1/public/businesses/{slug}/availability/days?branch=&items=&staff=&from=&to=
GET    /v1/public/businesses/{slug}/availability/slots?branch=&items=&staff=&date=
POST   /v1/public/businesses/{slug}/holds                (Idempotency-Key)
DELETE /v1/public/businesses/{slug}/holds/{holdToken}
POST   /v1/public/businesses/{slug}/bookings             (Idempotency-Key, hold_token)
POST   /v1/public/businesses/{slug}/booking-lookup       (reference + contact → emails/texts link)

GET    /v1/public/bookings/{manageToken}
POST   /v1/public/bookings/{manageToken}/cancel
POST   /v1/public/bookings/{manageToken}/reschedule      (Idempotency-Key)
GET    /v1/public/bookings/{manageToken}/calendar.ics
```

Public ids in URLs and payloads are UUIDs or references, never auto-increment ids.

Slots response:

```json
{
  "branch": "4f1c…",
  "date": "2026-11-23",
  "timezone": "Africa/Nairobi",
  "slots": [
    { "start": "09:00", "start_utc": "2026-11-23T06:00:00Z", "end": "10:15" },
    { "start": "09:15", "start_utc": "2026-11-23T06:15:00Z", "end": "10:30" }
  ]
}
```

Error contract (JSON `{ "error": { "code", "message", "details" } }`):

| HTTP | Code | When |
| ---- | ---- | ---- |
| 409 | `SLOT_UNAVAILABLE` | Taken at commit. `details.alternatives` lists up to 5 nearest slots. |
| 410 | `HOLD_EXPIRED` | Hold no longer active. |
| 422 | `POLICY_VIOLATION` | Notice, window, cutoff, limit. `details.rule` names the rule. |
| 422 | `VALIDATION_FAILED` | Field errors. |
| 409 | `IDEMPOTENCY_IN_PROGRESS` | Duplicate still processing. |
| 422 | `IDEMPOTENCY_CONFLICT` | Same key, different body. |
| 409 | `STALE_APPOINTMENT` | Version mismatch on a change. |
| 404 | `BUSINESS_NOT_FOUND` | Unknown slug, or tenant not publicly bookable (same response for both). |
| 429 | `RATE_LIMITED` | With `Retry-After`. |

---

## 19. Booking UX rules

- Show times in the branch timezone. If the visitor's browser timezone differs, label the times ("Nairobi time").
- When a slot is lost at commit, keep everything the customer typed, show the alternatives from the error, and let them pick one in a single tap.
- Show a hold countdown only in the last 60 seconds.
- Disable dates with no availability using the days summary; never show an empty day after a tap.
- Confirmation shows the reference, date/time with timezone, branch address, staff (if chosen), price summary, deposit instructions if any, "Add to calendar" (`.ics`) and the manage link.
- Every step works without JavaScript errors on a low-end Android phone on 3G; target the slot request under 300 ms server time at p95 for one day.

---

## 20. Testing requirements

### Unit

- `IntervalSet` union/intersection/subtraction, half-open edges, adjacency.
- Overnight hours, DST gaps and overlaps (test with a DST timezone, e.g. `Europe/London`), date overrides, effective-dated schedules.
- Buffers at shift edges, slot grid anchoring, min notice / max window.
- Policy inheritance tenant → branch → platform default.
- Pricing resolution order and snapshots.
- State machine: every allowed and forbidden transition.
- Reference generator alphabet and uniqueness handling.

### Property-based

Generate random schedules, services and existing bookings; assert that no returned slot overlaps any busy interval and that every returned slot is bookable by the transaction.

### Concurrency (real MySQL, separate PHP processes)

- 20 parallel bookings for the same staff and time → exactly 1 succeeds, 19 get `SLOT_UNAVAILABLE`.
- Hold vs booking race on the same time.
- Reschedule into a slot while another customer books it.
- Resource with `capacity = 2`, 3 concurrent bookings → exactly 2 succeed.
- Same idempotency key sent 10 times in parallel → 1 appointment, 10 identical responses.
- Expired hold cannot be converted; a released hold frees time immediately.
- Two bookings for the same new phone number run in parallel under `customer_match = phone` → exactly one customer and one set of match keys, or the loser retries and attaches to it.
- Forced deadlock is retried and succeeds or fails cleanly.
- Leave approved or a block created concurrently with a booking for the same staff → the booking is rejected or the leave operation reports the conflicting appointment.
- `BookingIntegrityCheck` finds zero violations after every concurrency suite.

### Security

- A management token for tenant A's booking returns 404 under tenant B's slug.
- A reference alone never returns booking details.
- Holds and bookings respect per-IP and per-contact limits.

---

## 21. Platform defaults (`config/booking.php`)

```php
return [
    'slot_interval_minutes'           => 15,
    'min_notice_minutes'              => 60,
    'max_advance_days'                => 60,
    'hold_ttl_seconds'                => 300,
    'hold_max_extension_seconds'      => 600,
    'max_active_holds_per_client'     => 2,
    'confirmation_mode'               => 'auto',
    'pending_expiry_minutes'          => 1440,
    'staff_assignment_strategy'       => 'least_booked_day',
    'customer_changes'                => 'self_service',
    'cancellation_cutoff_minutes'     => 1440,
    'reschedule_cutoff_minutes'       => 1440,
    'max_reschedules'                 => 2,
    'deposit_mode'                    => 'none',
    'deposit_due_minutes'             => 30,
    'max_active_bookings_per_contact' => 3,
    'customer_match'                  => 'phone',
    'customer_verification'           => 'none',
    'availability_max_range_days'     => 62,
    'idempotency_ttl_hours'           => 24,
    'booking_tx_max_attempts'         => 3,
    'booking_lock_wait_timeout_s'     => 5,
    'no_show_grace_minutes'           => 15,
];
```

Tenants may only override keys exposed in `booking_policies`, within platform-set bounds (e.g. `hold_ttl_seconds` between 120 and 900).

---

## 22. Scope by phase

| MVP (Phase 4) | Later |
| ------------- | ----- |
| Single staff per appointment, multiple sequential services | Different staff per item; parallel items |
| Resources by type with capacity | Resource preferences by customer |
| Holds, idempotency, lock protocol, integrity check | — |
| `auto` and `manual` confirmation | Approval rules per service |
| Customer self-service cancel/reschedule | `request` mode workflows |
| Manually recorded payments, deposit policy fields, tenant till/paybill display | Paybill C2B auto-matching, then STK push with tenant-owned Daraja credentials |
| Walk-ins and staff overrides | Waitlist, recurring appointments, group/class bookings (`capacity > 1` services) |

Design for the "later" column (per-item staff, capacity columns) without building it: the schema above already supports it.

---

## 23. Module layout

```text
app/Modules/Availability/
├── Services/AvailabilityService.php
├── Services/BookingPolicyResolver.php
├── Services/StaffAssignmentService.php
├── Support/IntervalSet.php
├── Support/WorkingTimeBuilder.php
├── Repositories/OccupancyRepository.php
├── Repositories/ScheduleRepository.php
└── DTOs/AvailabilityQuery.php, Slot.php, BookingPolicy.php

app/Modules/Appointments/
├── Services/BookingService.php           -- §9 protocol; the only writer of time
├── Services/HoldService.php
├── Services/RescheduleService.php
├── Services/AppointmentStateMachine.php
├── Services/PricingService.php
├── Services/BookingIntegrityCheck.php
├── Repositories/AppointmentRepository.php
├── Repositories/HoldRepository.php
├── Repositories/IdempotencyRepository.php
└── Exceptions/SlotUnavailableException.php, HoldExpiredException.php,
               PolicyViolationException.php, StaleAppointmentException.php

app/Modules/Payments/
├── Services/PaymentService.php
├── Services/PaymentStatusCalculator.php
└── Repositories/PaymentRepository.php
```
