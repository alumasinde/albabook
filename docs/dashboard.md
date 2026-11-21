### AlbaBook — Tenant Dashboard UI & UX Specification

The tenant dashboard is the private workspace where each business manages its appointments, services, staff, branches, customers, resources and daily operations.

It should feel like a premium SaaS application: clean, modern, responsive, information-rich without being crowded, and easy to operate on both desktop and mobile.

The design should use one shared dashboard system for every tenant. Business-specific branding should influence the public booking page, while the tenant dashboard maintains consistent AlbaBook application branding.

### 1\. Overall dashboard layout

![Yow | Dribbble](https://images.openai.com/static-rsc-4/JBzFmFq2Y0MoO0MRqyD9NZrbyhz5LrsDNGJvKqV0NlwnO5gWMxLwsXdDNi2XuEzuc4kLzI8UVt-dlu3Kj69ZlbUsmm2TTGHeTy8WBOQl_3OefXJ_ZoK5oGW4gjdHn87oiKkZkkEMSlNuhQeba49PZEGAmvpZpf-jP_DEEo6ldGvh8EEF1jOIZqZRAfkF6_S6?purpose=fullsize)

![Appointment Booking Dashboard — Clean UI for Fast, Effortless Sc by Aswin Das R on Dribbble](https://images.openai.com/static-rsc-4/F8bJHA9xk2qduZ4f5bqhZ-saFKPFo3w94H19YkSacJog3mQuOGYtETZFHXwRzzikk9hPslxgsCJFOzsDVanRddPf9uYi2DFGYmuFU2uwE3PSZkmS49XNWyYdLdLGQUdSEgVvZz67ESPIltdnRV3RNGBLSc079q-mO_vl3qi1er7GiqVUUBonWhVvpTFv96Hx?purpose=fullsize)

![Schedulo — Appointment Creation in SaaS Booking Management by Bagus Fikri for Fikri Studio on Dribbble](https://images.openai.com/static-rsc-4/gzmeXHgkC08Rkwe65VdqnDAkz8pZGWzbvXEcLSvclrRuWmCIZzIvXa6Ttc_JL0zrs2gahwo1yilfREpbMSLG1h2pLTcXyDCyu4fPIROJd-mQmZaSWRHuO3UVm7kXUWplgffkga32v3a1JxD9udxi90IkCPO1jFnlpVq7j7uvMC5ycjoYWWDw-doqxcva-kjb?purpose=fullsize)

Desktop layout

AlbaBook

Active workspace

WORKSPACE

Royal Beauty Studio

Overview

Calendar

Appointments

Services

Staff

Customers

Branches

Resources

MANAGEMENT

Reports

Notifications

Booking page

Settings

Overview

Saturday, 21 November 2026

Good morning

Here's what's happening with your business today.

Appointments

### 24

Revenue

### KSh 38,500

Customers

### 18

Pending

### 5

Today's appointments

09:00 — Jane W.

Confirmed

Hair styling · Sarah

10:30 — Mary K.

Pending

Manicure · Grace

Illustrative dashboard structure, not live application data.

### Main navigation

| Section       | Pages                                         |
| ------------- | --------------------------------------------- |
| Overview      | Dashboard home                                |
| Calendar      | Day, week, month, staff views                 |
| Appointments  | All, pending, confirmed, completed, cancelled |
| Services      | Categories, services, variants, add-ons       |
| Staff         | Staff members, schedules, leave, availability |
| Customers     | Customer directory, profiles, history         |
| Branches      | Locations, hours, branch settings             |
| Resources     | Rooms, chairs, equipment, availability        |
| Reports       | Revenue, appointments, staff performance      |
| Notifications | Preferences, templates, delivery logs         |
| Booking page  | Appearance, content, preview, publishing      |
| Integrations  | WhatsApp, SMS, email, API, webhooks           |
| Billing       | Subscription, invoices, usage                 |
| Settings      | Business, users, roles, permissions, security |

Use collapsible navigation groups and highlight the active page. On mobile, replace the permanent sidebar with a drawer and provide a compact bottom navigation for the most frequently used pages.

### 2\. Dashboard home — Overview

![BookingBee.ai 2026 Pricing, Features, Reviews & Alternatives | GetApp](https://images.openai.com/static-rsc-4/4gA56T0ahQd6UqAsD41KuFzW_fWkKFocR9uVAikakM_lbsC8YbAw_vOfEr9HbdmwOIMz8mgBexzXvtX2idA-o-R60q-XtSVRnI2ZYvtRguxyJ3xWHbsY1shLKr-Cm13C9sToVqvLGBQmsSwVvBASQeb--nkjHCjpj6QtJL_vihRPmWGNY7B1wjUn9DF7o_hE?purpose=fullsize)

The overview should answer four questions immediately:

* How is the business performing?

* What appointments need attention?

* What is happening today?

* Are there operational issues?

### A. Header

* Page title: Overview

* Current branch selector or All branches

* Date range selector

* Notifications

* User profile menu

* Quick action: New appointment

The branch selector must respect the user's branch permissions.

### B. KPI cards

Today's appointments

### 24

Compared with previous period

Revenue today

### KSh 38,500

Based on recorded payments

Pending bookings

### 5

Require attention

New customers

### 12

Within selected period

These are example values. Every KPI must be calculated from actual database records, selected date range and branch scope. Revenue should distinguish booked revenue from payments actually received.

### Metric definitions

Every KPI uses one definition everywhere (overview, reports, API, exports). Implement each once in a query class and reuse it.

| Metric | Definition |
| ------ | ---------- |
| Appointments (period) | Appointments with `start_at` in the period and status in `pending`, `confirmed`, `checked_in`, `completed`, `no_show`. |
| Pending bookings | Status `pending`, `start_at` in the future, regardless of period. |
| Booked revenue | Sum of `total_amount_minor` for appointments in the period with status `confirmed`, `checked_in` or `completed`. Labelled "Booked". |
| Collected revenue | Succeeded `payments.amount_minor` by `received_at` in the period minus succeeded `refunds.amount_minor` by `refunded_at` in the period. `payments.status = 'unmatched'` is excluded. Labelled "Collected". |
| Outstanding | Booked revenue of completed appointments minus payments allocated to them. |
| New customers | Customers whose first time-blocking appointment falls in the period. |
| Returning customers | Customers with an appointment in the period and at least one completed appointment before it. |
| No-show rate | `no_show` ÷ (`completed` + `no_show`) in the period. |
| Cancellation rate | `cancelled` ÷ all created appointments in the period; late cancellations shown separately. |

Rules:

* "Today" and every period boundary are computed in the **branch** timezone (or the tenant timezone for "All branches") and converted to a UTC range before querying. Never use `CURDATE()` or the server timezone.

* All amounts are integer minor units (`decisions.md` D-006) and are formatted only by `CurrencyFormatter`.

* "Compared with previous period" compares with the immediately preceding period of equal length.

* Users without `reports.view_financial` see no revenue cards.

* Payment tables and rules are defined in `booking-engine.md` §14.

### C. Charts and analytics

![Free Salon Software | AI-Powered Salon Management | Salonia](https://images.openai.com/static-rsc-4/iJFmoUWWhUlt_J2oLGJMI6JFi0e80-KHoz-_-eEO3smqauSm2WB2yF61TWC39C0NQBKgCpR4Sjm6tUCALhv2mxPOhM71IQTMSuphgXsvAVUVDuDT6vvaJt1D9nDyJFPjbPjWU2vFj3x_kf8nYjejkengtRfjr27op9qYaZlaiKECBbQ2hhts1hWmTlLglp-B?purpose=fullsize)

Include:

* Revenue trends.

* Appointment volume.

* Booking status distribution.

* Most popular services.

* Staff performance.

* Customer acquisition and returning customers.

Do not overload the overview with every possible report. Keep advanced analytics under Reports.

### D. Today's appointments

![Recordatorios de turnos por WhatsApp | Daturno](https://images.openai.com/static-rsc-4/6xxG-QKNUPhC6fvEpDLvDldxkuzqDmNLC5KiRbrImTBrgm_awfqVMonXZjMecE2N4v8MuteY6r7ZUPhfj-NdYon1T3Fh_VK8hSVTUKadXW_mIs-pOZw6wCd4WBkGQYd7CV2aJYS6GEQU6Lm9-HGCjnGeBtCmPOCnM-sDjTdD69HMGjPDFBTNW70BIowIUCBm?purpose=fullsize)

Display:

* Time

* Customer

* Service

* Staff

* Branch

* Appointment status

* Payment status

* Quick actions

Actions should include viewing details, confirming, rescheduling, cancelling and marking completed, subject to permissions and business rules.

### E. Operational alerts

Examples:

* Five appointments awaiting confirmation.

* Staff member unavailable tomorrow.

* WhatsApp integration disconnected.

* Booking page changes awaiting publication.

* Subscription approaching expiry.

* Notification delivery failures.

Only show relevant alerts and make each actionable.

### 3\. Core page designs

### Calendar

![Beauty Salon Software — All-in-One Platform | Salonify](https://images.openai.com/static-rsc-4/mjeGd-IhjqSFHLfRs8iVxgqefqrnD03C4dU3f63Q4yWCpcD8rxYtMKeSbWN4dIM_nS-CrJnX_3ka0AVMsX5OeJAx_qrnLTsuk8Ka1e6nJ2owN0LVjsUyl5mBagOpO-7PWcui2De3OyujCxrmA_x-7v9Sin-97L7YRkSwufhzWs-qKQxYZkWT7b6BERFSuaUW?purpose=fullsize)

Required features:

* Day, week and month views.

* Staff-based calendar columns.

* Branch filtering.

* Status-based appointment indicators.

* Drag-and-drop rescheduling with server-side validation.

* Appointment creation from an available time slot.

* Block time and manage staff leave.

* Resource availability.

* Conflict detection before saving.

Calendar interactions must never bypass the booking engine's availability validation.

Calendar behaviour:

* Drag-and-drop calls the reschedule endpoint. The card moves to its new position only after the server confirms; on `SLOT_UNAVAILABLE` or `STALE_APPOINTMENT` it snaps back and shows the reason.

* Creating an appointment from an empty slot opens the booking drawer pre-filled with staff, branch and time, and submits through the same `BookingService` as public bookings.

* Overrides are explicit: booking outside hours needs `appointments.override_policy`; a deliberate overlap needs `appointments.override_conflict`, a reason and a confirmation dialog. Overlaps are shown with a warning stripe.

* Adding leave or a block that conflicts with existing appointments lists them and asks the user to reschedule, cancel or keep them before saving.

* The calendar polls (or refreshes on focus) every 60 seconds so receptionists see public bookings without reloading.

* Times are displayed in the branch timezone, with the timezone shown when "All branches" spans more than one.

### Appointments

![The 10 best WordPress booking plugins in 2026](https://images.openai.com/static-rsc-4/KCGCSH9M-kExCeF1d-WDiIV2nOeK9WL4PaI37CAkIGiaPQvgAX7uZ_tcqdNdEH44CboRaVMiAT2c6UqPQb9sHRmGjOrxT3Jr2rEkF0u17munWzizE6emVLkgMpfkTtFx6QnRPRokHJmGENZp2BKhrPf-0bVglM2kFF9k1kKX9LW6LKJf0DZtRADdJhNg2JJ_?purpose=fullsize)

Provide:

* Search by customer name, phone or booking reference.

* Filter by branch, staff, date, service and status.

* Create manual booking.

* Export permitted records.

* View appointment timeline and history.

* View payment and notification history.

* Bulk actions only where safe and permission-controlled.

Appointment details should have separate sections for customer, services, assigned staff, resources, payment, notifications and event history.

The payment section lets users with `payments.record` record a payment (amount, method, reference such as an M-Pesa code, received time) and users with `payments.refund` record a refund. Payment status updates immediately from those records.

### Services

![About Salonify — All-in-One Salon Management Software | Online Booking, POS & Marketing for Beauty Businesses | Salonify](https://images.openai.com/static-rsc-4/aHKzpmJhjbV7DmhlYtoNU3m6LVr8c0BrBKxxfzzWp5i8tTb7rgP3NW19FU0l6R-NDdGtTkwWNcCltJYb5Ft72IQIeRMenhH7P61VmpMvejdHZwz8hfRCB2MOV9Kwslzg1omQdNV0K4Ab-owktvGIIlfBWiDDAkaThBjqil5SXyc7s_S1mmKI3uBjFpCRQQ0Z?purpose=fullsize)

Support:

* Service categories.

* Services and variants.

* Price and duration.

* Preparation and cleanup buffers.

* Eligible staff.

* Required resources.

* Branch-specific availability and pricing.

* Add-ons.

* Active/inactive status.

* Booking visibility.

Never assume all services have fixed prices or identical durations.

### Staff

![Free Hotel Scheduling Software for Hospitality Teams](https://images.openai.com/static-rsc-4/l7weohnnCr78VM4MlFg5mpjgUfrn0TpxIHRwvpvS-o6cVLNxJRVN-Lq-v4w-TTctKpUnIY7gQv7DvN8swtxM7dXDcnDRt1_Ef479KwL-mbcfifEPZ7hQuv9Tswpgz2oYXH42-ZAg_MalXQDmsMNRh68qm1cBgAyUjM2XshXMsr1yoGXN7JRePHeOg5wnagtX?purpose=fullsize)

Each staff profile should include:

* Personal details.

* Assigned branches.

* Services offered.

* Working schedules.

* Breaks and leave.

* Booking eligibility.

* User account and permissions, where applicable.

* Appointment history and performance.

Keep staff records separate from user accounts. A staff member does not automatically need dashboard login access.

When a staff member does log in, `staff.tenant_user_id` links their staff record to their membership. That link is what `appointments.view_own` uses to show "my appointments" (`decisions.md` D-007).

### Customers

![【保存版】外見コンサルのビフォーアフター徹底解説｜外見を別人に変える思考ロジック｜ラテス＠No.1外見コンサルタント](https://images.openai.com/static-rsc-4/-jGkd0F9ediK3vfKEswlPazyeBdQ6DoW02z4IQqJVjGHy-VYoncxst38vdQEM0BP29G_5VgJzYKWB_GiZZd_3SSqrnqko2J5s0V26RNd6SpVK8weOqZt_zOL-wQdX-37DzQdNBVTjaJo9eynB18Dd0RRYkejiV8nOxTkTCuyVg9it-_6syngZJpayvhpcO8c?purpose=fullsize)

Customer profiles should include:

* First name, middle name and last name.

* Phone and email.

* Booking history.

* Total completed appointments.

* Customer notes with permission restrictions.

* Preferred staff or services.

* Communication preferences and consent.

* No-show and cancellation history.

Avoid exposing sensitive customer notes to users who do not have the appropriate permissions.

### Branches and resources

![Complete Store Management - Locator to Dashboard](https://images.openai.com/static-rsc-4/qfPP8-ZRs1lyBhAx3F42T9Z83E8AAzvgi3jU8DhK1wL6amrVFBR0lwi4h-PcB1A3_hAV70so_RBrg80J_shuMGZXBo3Wv7OoBnJdw8FrBt5HVwkSNMBa6ZgKpaS_mc07CGjZ4yg9E8gxAVLCwtuxM9rVwFQuc8N6iK77V9oUUkdX4IGBwhni_NNEZel5B7Do?purpose=fullsize)

Branch management:

* Branch name and address.

* County, town and location details.

* Contact information.

* Working hours.

* Assigned staff.

* Services available.

* Branch-specific booking policies.

Resource management:

* Resource name and type.

* Branch assignment.

* Capacity.

* Availability schedule.

* Required service associations.

* Maintenance and blocked periods.

### 4\. Booking page management

![Create Your Website For Free | Oniva.io](https://images.openai.com/static-rsc-4/-rYQ4ubHRfY07f6u-4Oa2kFX6TIrQSl-JFRNPUvvXxFkYU3W-oH4zlomki_OcX07929nLNLI6dXkobheDjShnUeDCgUeZFFfgOyDXsZFVm-Ep_0ifP9z5AelPMHsuGpyULUw2FCMM0oIcyZPrNiRmED_EscsxMEqnwSZ1Xec4EhHsmWhl-jtFszAF-r3bNCX?purpose=fullsize)

This should be a dedicated area rather than a simple settings form.

Recommended navigation:

* Overview: Public booking URL, views and bookings.

* Appearance: Colors, fonts, layout and logo.

* Content: Business description, gallery, FAQs and testimonials, as structured plain-text fields and list rows — no HTML editor (`decisions.md` D-002).

* Sections: Enable, disable and reorder.

* Booking settings: Booking flow and selection rules.

* Preview: Desktop, tablet and mobile.

* Publishing: Draft, published version and rollback.

Include a visible copy-link action for the public booking page.

### 5\. Global UI components

Build reusable components instead of designing each module independently.

| Component        | Responsibility                          |
| ---------------- | --------------------------------------- |
| DashboardLayout  | Main application shell                  |
| Sidebar          | Navigation and permission-aware links   |
| Topbar           | Branch selector, notifications, profile |
| PageHeader       | Title, description and actions          |
| StatCard         | KPI display                             |
| DataTable        | Search, sort, filters, pagination       |
| StatusBadge      | Consistent status indicators            |
| FilterBar        | Shared filtering controls               |
| DateRangePicker  | Reporting date ranges                   |
| EmptyState       | Useful first-use experience             |
| ConfirmDialog    | Destructive action confirmation         |
| FormField        | Consistent form inputs                  |
| Toast            | Success and error feedback              |
| Drawer           | Mobile navigation and detail panels     |
| ActivityTimeline | Audit and appointment events            |

### 6\. Visual design system

AlbaBook dashboard palette

Navy

#171A2C

Primary

#6D5CE8

Success

#16A085

Surface

#F3F4F8

Typography

* Inter

* Page heading: 24–28px

* Section heading: 16–18px

* Body: 14–16px

* Supporting text: 12–13px

    Interface principles

* White or subtle grey surfaces.

* Restrained shadows and borders.

* Consistent spacing.

* Clear visual hierarchy.

* Accessible contrast.

* No unnecessary gradients or decorative elements.

* Responsive tables and forms.

These colors are platform defaults, not tenant-controlled dashboard colors.

### 7\. Technical implementation using core PHP and Twig

Recommended view structure:

```text
resources/views/
├── layouts/
│   ├── dashboard.twig
│   └── dashboard-authenticated.twig
├── dashboard/
│   ├── overview.twig
│   ├── partials/
│   │   ├── stats.twig
│   │   ├── revenue-chart.twig
│   │   ├── appointments-today.twig
│   │   └── alerts.twig
├── appointments/
│   ├── index.twig
│   ├── show.twig
│   ├── create.twig
│   └── edit.twig
├── calendar/
├── services/
├── staff/
├── customers/
├── branches/
├── resources/
├── reports/
├── notifications/
├── booking-page/
├── integrations/
├── billing/
├── settings/
└── components/
    ├── sidebar.twig
    ├── topbar.twig
    ├── page-header.twig
    ├── stat-card.twig
    ├── data-table.twig
    ├── status-badge.twig
    ├── empty-state.twig
    ├── pagination.twig
    └── modal.twig
```

### Backend module structure

```text
app/Modules/Dashboard/
├── Controllers/
│   └── DashboardController.php
├── Services/
│   ├── DashboardAnalyticsService.php
│   ├── DashboardMetricsService.php
│   └── DashboardAlertService.php
├── Repositories/
│   └── DashboardRepository.php
├── DTOs/
│   └── DashboardSummaryDTO.php
└── Queries/
    ├── AppointmentMetricsQuery.php
    └── RevenueMetricsQuery.php
```

### Example routes

```php
GET /dashboard
GET /dashboard/metrics
GET /dashboard/revenue
GET /dashboard/appointments/today
GET /dashboard/alerts

GET /appointments
POST /appointments
GET /appointments/{uuid}
PATCH /appointments/{uuid}
POST /appointments/{uuid}/confirm
POST /appointments/{uuid}/reschedule
POST /appointments/{uuid}/cancel
POST /appointments/{uuid}/payments

GET /calendar
GET /calendar/events?from=&to=&branch=&staff=
```

Dashboard URLs use UUIDs rather than auto-increment IDs, so the same identifiers work in the private API.

### Backend implementation rules

* Resolve tenant context through trusted middleware.

* Apply tenant scope to every tenant-owned query.

* Apply user permissions and branch access before retrieving data.

* Use PDO prepared statements.

* Aggregate metrics in SQL rather than loading all records into PHP.

* Cache expensive dashboard metrics with tenant and branch identifiers in cache keys.

* Invalidate relevant caches after appointment, payment and service changes.

* Use pagination for large datasets.

* Return structured JSON for asynchronous dashboard widgets.

* Never calculate revenue from appointment prices alone when actual payment records are available.

### 8\. Important database and performance considerations

The dashboard should use existing operational tables rather than introduce duplicate data unnecessarily.

Suggested indexes to validate against actual query patterns:

```sql
CREATE INDEX idx_appointments_tenant_start
ON appointments (tenant_id, start_at);

CREATE INDEX idx_appointments_tenant_branch_start
ON appointments (tenant_id, branch_id, start_at);

CREATE INDEX idx_appointments_tenant_status_start
ON appointments (tenant_id, status, start_at);

-- Staff-filtered views read appointment_items occupancy, which is the
-- authoritative per-item staff assignment (booking-engine.md §5):
CREATE INDEX idx_items_staff_occupancy
ON appointment_items (tenant_id, staff_id, occupied_start_at, occupied_end_at);

CREATE INDEX idx_customers_tenant_phone
ON customers (tenant_id, phone_e164);
```

For financial reporting, maintain proper payment records and use payment status, refunds and transaction amounts. Do not treat a confirmed appointment as automatically paid.

For performance:

* Load the initial dashboard summary in one optimized request.

* Load charts asynchronously.

* Cache expensive aggregates.

* Use a background process for periodic reporting aggregates when volume justifies it.

* Avoid N+1 queries.

* Use `EXPLAIN ANALYZE` before adding or changing indexes.

### 9\. Permissions and access control

The dashboard navigation and actions must be permission-aware.

| Role example     | Access                                  |
| ---------------- | --------------------------------------- |
| Business owner   | Full permitted tenant access            |
| Business manager | Operational management                  |
| Branch manager   | Assigned branch operations              |
| Receptionist     | Bookings and customer management        |
| Staff member     | Own appointments and permitted schedule |
| Accountant       | Financial records and reports           |

Use the canonical permission catalogue in `plan.md` §14.2. Do not invent identifiers here. Examples used by the dashboard: `dashboard.view`, `appointments.view_own`, `appointments.confirm`, `appointments.reschedule`, `payments.record`, `reports.view_financial`, `booking_page.publish`.

These are permission identifiers, not fixed roles. Tenant administrators should be able to configure roles without bypassing platform-level restrictions.

Branch scope (`membership_branches`) applies on top of permissions: a branch manager's branch selector, calendar, lists and metrics only include their branches, and the server enforces this in every query, not just in the selector.

### 10\. Recommended development order

* Dashboard shell

    Sidebar, topbar, responsive layout, page headers, navigation and shared components.

* Overview

    KPI cards, today's appointments, alerts and revenue chart.

* Appointments and calendar

    Booking management, filters, calendar views and appointment details.

* Business management

    Services, staff, branches, resources and customers.

* Booking page editor

    Appearance, content, preview and publishing.

* Reports and integrations

    Analytics, notifications, WhatsApp, SMS, email and API settings.

* Billing and settings

    Subscription management, users, roles, permissions and security.

My recommendation: Build the dashboard shell and Overview first, then use the same design system for every subsequent module. Avoid creating separate visual systems for appointments, customers, staff and services. This will make AlbaBook easier to maintain and give the entire application a consistent enterprise SaaS experience.