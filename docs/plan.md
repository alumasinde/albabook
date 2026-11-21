AlbaBook — Complete Product & Technical Implementation Guide

Product: AlbaBook
Company: AlbaTech Solutions
Product type: Multi-tenant appointment booking and business management SaaS
Primary market: Kenya
Architecture: Modular monolith
Backend: Core PHP
Database: MySQL 8+
Database access: PDO
Templating: Twig
Frontend: HTML5, Tailwind CSS, JavaScript
Icons: Font Awesome
Dependency management: Composer
Public booking: Booking links, embeddable widgets and REST API

Related specifications (see "decisions.md" for precedence and the decision log):

- "booking-engine.md" — availability, holds, concurrency, appointment lifecycle, booking payments. Authoritative over §9–§10 of this guide.
- "multi-tenancy.md" — tenant resolution, context, status policy, isolation.
- "auth.md" — authentication, sessions, MFA, invitations, support access.
- "dashboard.md" — tenant portal UI/UX and metric definitions.
- "decisions.md" — document precedence, decisions D-001…, open questions.

---

1. Project Overview

1.1 Product vision

AlbaBook is a configurable, multi-tenant appointment booking and business management platform developed by AlbaTech Solutions.

It enables appointment-based businesses to manage their operations while allowing customers to discover services and book appointments online.

The platform should support businesses such as:

- Beauty salons.
- Barbershops.
- Spas.
- Massage centres.
- Nail studios.
- Wellness centres.
- Makeup artists.
- Hair stylists.
- Consultants.
- Other appointment-based service providers.

The system must not be designed exclusively for salons.

Business categories, services, resources, booking policies, branding, pricing and operational settings must be configurable.

1.2 Core objectives

1. Provide businesses with an independent management workspace.
2. Allow customers to book appointments without creating mandatory accounts.
3. Support multiple branches per business.
4. Support multiple employees and granular permissions.
5. Support resource-based bookings such as rooms, massage beds and equipment.
6. Provide a real-time availability and scheduling engine.
7. Allow tenants to customize their public booking pages.
8. Support WhatsApp, SMS and email notifications.
9. Provide direct booking links, website embeds and REST API access.
10. Support self-service and AlbaTech-assisted onboarding.
11. Enforce strict tenant data isolation.
12. Provide a configurable subscription and feature-entitlement system.
13. Remain maintainable and scalable without introducing unnecessary microservices.

1.3 Core architectural principle

Build one shared application and one shared database schema.

Every business is a tenant.

Every tenant has its own:

- Business profile.
- Categories.
- Branches.
- Services.
- Employees.
- Customers.
- Appointments.
- Resources.
- Branding.
- Booking page.
- Notification configuration.
- Integrations.
- Operational settings.
- Subscription and feature entitlements.

One tenant must never access another tenant's private data.

---

2. Product Architecture

2.1 Three primary interfaces

AlbaBook has three distinct interfaces.

A. Tenant portal

Private workspace used by business owners and authorized employees.

URL:

"https://app.albabook.co.ke"

Responsibilities:

- Manage appointments.
- Manage services.
- Manage staff.
- Manage customers.
- Manage branches.
- Configure schedules.
- Manage notifications.
- Customize the customer booking page.
- View reports.
- Manage business settings.

B. Customer booking portal

Public-facing interface used by customers.

URL:

"https://book.albabook.co.ke/{business-slug}"

Responsibilities:

- Display business information.
- Display services.
- Display prices and durations.
- Allow branch selection.
- Allow staff selection where applicable.
- Display available dates and times.
- Accept bookings.
- Display booking confirmations.
- Allow customers to manage their bookings securely.

Customers should not be forced to register before booking.

C. Platform administration

Private interface used by AlbaTech Solutions.

URL:

"https://admin.albabook.co.ke"

Responsibilities:

- Manage tenants.
- Approve or suspend accounts.
- Create and manage subscriptions.
- Manage platform plans.
- Manage feature entitlements.
- Monitor platform activity.
- Manage integrations.
- Monitor notification delivery.
- View audit logs.
- Manage platform-level configuration.

Platform administrators must use a separate authorization boundary from tenant users.

---

3. Technology Stack

3.1 Backend

Use core PHP with a modular, service-oriented architecture.

Requirements:

- PHP 8.3 or newer, using a currently supported PHP release.
- Strict typing where practical.
- PDO for all database access.
- Prepared statements.
- Composer autoloading.
- Dependency injection through a lightweight application container.
- Centralized exception handling.
- Request and response abstractions.
- Middleware-based request processing.
- Clean URLs through a front controller.

Do not introduce Laravel, Symfony Framework, or another full-stack framework.

Individual Composer libraries are permitted.

3.2 Database

MySQL 8+ with InnoDB.

Requirements:

- Foreign keys.
- Composite tenant-aware foreign keys.
- Proper indexing.
- Transactions.
- UTC timestamp storage.
- JSON columns only where structured configuration is appropriate.
- Database migrations.
- Seeders.
- Query optimization.

3.3 Frontend

Use:

- Twig for server-side templates.
- Tailwind CSS compiled for production.
- JavaScript for interactive components.
- Font Awesome for icons.
- Responsive, mobile-first layouts.

Do not use Tailwind through its browser CDN in production.

Do not use React or Vue for the initial application.

3.4 Composer dependencies

Keep dependencies limited to libraries that solve specific problems.

Recommended initial "composer.json":

{
    "name": "albatech/albabook",
    "description": "Multi-tenant appointment booking and management platform",
    "type": "project",
    "require": {
        "php": "^8.3",
        "ext-pdo": "*",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-openssl": "*",
        "twig/twig": "^3.0",
        "nikic/fast-route": "^1.3",
        "vlucas/phpdotenv": "^5.6",
        "monolog/monolog": "^3.0",
        "ramsey/uuid": "^4.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "phpstan/phpstan": "^2.0",
        "friendsofphp/php-cs-fixer": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        },
        "files": [
            "app/Shared/Helpers/functions.php"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true,
        "optimize-autoloader": true,
        "platform-check": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}

Install:

composer install
composer dump-autoload

Recommended additional dependencies only when required:

Package| Purpose
"symfony/mailer"| SMTP and email transport
"symfony/console"| Structured CLI commands and scheduled tasks
"intervention/image"| Image resizing and optimization
"phpoffice/phpspreadsheet"| Spreadsheet exports
"dompdf/dompdf"| PDF generation
"league/flysystem"| Local and cloud storage abstraction
"giggsey/libphonenumber-for-php"| Phone number parsing and E.164 normalisation (needed by Phase 3)
"spomky-labs/otphp"| TOTP for MFA (Phase 2), or an equivalent maintained library

Do not install all optional dependencies in advance.

Select packages when the corresponding module is implemented.

---

4. Application Folder Structure

Use a modular architecture with clear separation between HTTP handling, business logic, data access and presentation.

albabook/
│
├── app/
│   ├── Core/
│   │   ├── Application.php
│   │   ├── Container.php
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Database.php
│   │   ├── View.php
│   │   ├── Session.php
│   │   ├── Config.php
│   │   ├── Validator.php
│   │   ├── EventDispatcher.php
│   │   └── ExceptionHandler.php
│   │
│   ├── Middleware/
│   │   ├── AuthenticationMiddleware.php
│   │   ├── PlatformAdminMiddleware.php
│   │   ├── PermissionMiddleware.php
│   │   ├── CsrfMiddleware.php
│   │   ├── RateLimitMiddleware.php
│   │   └── MaintenanceMiddleware.php
│   │
│   ├── Tenancy/
│   │   ├── TenantContext.php
│   │   ├── TenantResolver.php
│   │   ├── TenantDomainResolver.php
│   │   ├── TenantRepository.php
│   │   ├── TenantMembershipRepository.php
│   │   ├── TenantAccessChecker.php
│   │   ├── TenantMiddleware.php
│   │   ├── TenantSettingsService.php
│   │   └── Exceptions/
│   │
│   ├── Modules/
│   │   ├── Auth/
│   │   ├── Dashboard/
│   │   ├── Platform/
│   │   ├── Tenants/
│   │   ├── Users/
│   │   ├── Roles/
│   │   ├── Branches/
│   │   ├── Categories/
│   │   ├── Services/
│   │   ├── Staff/
│   │   ├── Customers/
│   │   ├── Resources/
│   │   ├── Appointments/
│   │   ├── Availability/
│   │   ├── Payments/
│   │   ├── BookingPages/
│   │   ├── Notifications/
│   │   ├── Integrations/
│   │   ├── Subscriptions/
│   │   ├── Reports/
│   │   ├── Audit/
│   │   └── Assets/
│   │
│   └── Shared/
│       ├── Contracts/
│       ├── DTOs/
│       ├── Exceptions/
│       ├── Helpers/
│       ├── Enums/
│       ├── Traits/
│       └── Support/
│
├── bootstrap/
│   ├── app.php
│   ├── container.php
│   ├── routes.php
│   └── providers.php
│
├── config/
│   ├── app.php
│   ├── database.php
│   ├── security.php
│   ├── tenancy.php
│   ├── booking.php
│   ├── notifications.php
│   ├── integrations.php
│   ├── storage.php
│   └── features.php
│
├── database/
│   ├── migrations/
│   ├── seeders/
│   └── factories/
│
├── public/
│   ├── index.php
│   ├── _/                     # platform-host static roots (booking host: /_/widget.js)
│   │   ├── widget.js
│   │   └── assets/
│   │       ├── css/
│   │       ├── js/
│   │       ├── images/
│   │       └── fonts/
│   └── favicon.ico
│
├── resources/
│   ├── views/
│   │   ├── layouts/
│   │   ├── components/
│   │   ├── partials/
│   │   ├── public/
│   │   ├── dashboard/
│   │   ├── booking/
│   │   ├── admin/
│   │   └── emails/
│   │
│   ├── css/
│   │   └── app.css
│   └── js/
│       ├── app.js
│       ├── calendar.js
│       ├── booking.js
│       └── theme-editor.js
│
├── routes/
│   ├── web.php
│   ├── public.php
│   ├── api.php
│   ├── admin.php
│   └── webhooks.php
│
├── workers/
│   ├── outbox.php
│   ├── notifications.php
│   ├── reminders.php
│   ├── webhooks.php
│   └── cleanup.php
│
├── cron/
│   └── scheduler.php
│
├── storage/
│   ├── logs/
│   ├── cache/
│   ├── exports/
│   ├── temporary/
│   └── private/
│
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Feature/
│   ├── Security/
│   └── Concurrency/
│
├── .env.example
├── .gitignore
├── composer.json
├── composer.lock
├── package.json
├── phpunit.xml
├── README.md
└── GUIDE.md

Each module should have a consistent internal structure.

Example:

Appointments/
├── Controllers/
├── Services/
├── Repositories/
├── Models/
├── DTOs/
├── Validators/
├── Policies/
├── Events/
└── Exceptions/

Responsibilities:

- Controllers: HTTP input and output.
- Services: business rules and workflows.
- Repositories: database queries.
- Models: domain entities where useful.
- DTOs: structured data transfer.
- Validators: input validation.
- Policies: authorization rules.
- Events: domain event definitions.
- Exceptions: module-specific errors.

Avoid putting business logic in controllers or Twig templates.

---

5. Multi-Tenant Architecture

5.1 Tenant model

A tenant represents one independent business account.

The architecture uses:

- One shared codebase.
- One shared MySQL database.
- Shared application infrastructure.
- Tenant-scoped business records.

Every tenant-owned record must include "tenant_id".

Examples:

- branches
- services
- staff
- customers
- appointments
- resources
- booking page settings
- notification preferences
- integration configurations

5.2 Tenant resolution

Resolve tenants using trusted context.

Supported resolution methods:

1. Public booking slug.
2. Verified custom domain.
3. Authenticated tenant membership.
4. Tenant-bound API credential.
5. Verified webhook endpoint (inbound provider callbacks).
6. Trusted tenant ID stored in a background job record.

Slugs share a namespace with platform paths on the booking host, so reserved slugs must be rejected and renamed slugs must keep redirecting (see "decisions.md" D-005 and "multi-tenancy.md").

Never trust a submitted "tenant_id" from a browser request.

Example:

book.albabook.co.ke/royal-beauty

Resolves the tenant with slug "royal-beauty".

The dashboard resolves tenant membership from the authenticated user's session.

API requests resolve tenants from verified API credentials.

5.3 Tenant context

Implement an immutable request-scoped "TenantContext".

It should be initialized once by trusted middleware.

Repository methods must receive tenant context explicitly or use an injected tenant-aware repository.

Never permit arbitrary tenant switching through request parameters.

Background workers must initialize a trusted tenant context from the job record and reset it after processing.

5.4 Tenant isolation

Use several layers:

- Middleware-level tenant resolution.
- Tenant-aware authorization.
- Tenant-scoped repositories.
- Tenant-aware SQL queries.
- Composite foreign keys.
- Cross-tenant security tests.

Example:

SELECT *
FROM appointments
WHERE tenant_id = :tenant_id
AND id = :appointment_id
LIMIT 1;

Never retrieve a private record by ID alone.

5.5 Tenant lifecycle

The set of statuses is a fixed state machine in code. What each status allows is configurable data managed by platform administrators ("decisions.md" D-010).

Statuses:

- pending
- trial
- active
- suspended
- cancelled
- archived

Suspended tenants should not create new bookings or use restricted features.

Preserve historical data according to the retention policy.

---

6. Tenant Portal — Business Management

6.1 Dashboard

URL:

"/dashboard"

Display:

- Today's appointments.
- Pending requests.
- Confirmed appointments.
- Cancelled appointments.
- Upcoming appointments.
- Booked revenue and collected revenue, labelled separately (definitions in "dashboard.md" §2).
- Staff availability.
- Recent customers.
- Notification delivery status.
- Quick actions.

Dashboard statistics must be tenant-scoped.

Use efficient aggregate queries and cache expensive reports where appropriate.

6.2 Main navigation

Recommended navigation:

Overview

Appointments
  Calendar
  All Appointments
  Booking Requests

Business
  Services
  Service Categories
  Staff
  Customers
  Branches
  Resources

Booking Page
  Page Overview
  Appearance
  Page Sections
  Booking Settings
  Preview

Communication
  Notifications
  Message Templates
  Delivery Logs

Reports
  Overview
  Appointments
  Revenue
  Staff Performance

Integrations
  Booking Widget
  API Credentials
  Webhooks
  WhatsApp
  SMS
  Email

Settings
  Business Profile
  Working Hours
  Booking Policies
  User Accounts
  Roles & Permissions
  Billing

Only show menu items permitted by the authenticated user's permissions and subscription entitlements.

6.3 Business profile

Allow the tenant to configure:

- Business name.
- Business description.
- Business category.
- Logo.
- Cover image.
- Contact information.
- Email.
- Phone.
- Address.
- County and town.
- GPS coordinates where needed.
- Website.
- Social media links.
- Business timezone.
- Public booking slug.

Do not hardcode Kenyan locations.

Use configurable location records or a maintained location dataset.

6.4 Branch management

A tenant can manage multiple branches.

Each branch may have:

- Name.
- Address.
- Contact details.
- Location.
- Opening hours.
- Services.
- Assigned staff.
- Resources.
- Booking policies.
- Branch-specific service pricing.

Do not assume all branches operate under identical schedules.

6.5 Service management

Services must be configurable.

Service properties:

- Name.
- Category.
- Description.
- Images.
- Price.
- Currency.
- Duration.
- Buffer before.
- Buffer after.
- Deposit requirement.
- Active/inactive status.
- Eligible branches.
- Eligible staff.
- Eligible resources.
- Booking visibility.

Support:

- Fixed prices.
- Starting-from prices.
- Price ranges.
- Service variants.
- Optional add-ons.
- Branch-specific pricing.
- Staff-specific pricing where necessary.

Service variants may include different durations or prices.

Example: Hair Styling may have short, medium and long hair options.

Do not hardcode services or service categories.

6.6 Staff management

Staff profiles should include:

- First name.
- Middle name.
- Last name.
- Email.
- Phone.
- Profile image.
- Job title.
- Branch assignments.
- Service eligibility.
- Working schedules.
- Breaks.
- Leave and blocked dates.
- Account status.

Support staff-specific permissions.

A receptionist should not automatically receive permission to change billing or integrations.

6.7 Customer management

Customer records are tenant-specific.

Store:

- First name.
- Middle name.
- Last name.
- Phone.
- Email.
- Notes.
- Communication preferences.
- Booking history.
- Relevant consent records.

Do not enforce globally unique phone numbers.

A customer may use the same phone number with different businesses.

Allow configurable tenant-level customer matching policies.

6.8 Resource management

Support bookable resources.

Examples:

- Treatment rooms.
- Massage beds.
- Salon chairs.
- Equipment.
- Meeting rooms.

A booking may require:

- One staff member.
- One room.
- One equipment item.

Availability must validate all required resources.

---

7. Customer Booking Experience

7.1 Customer-facing principles

The customer booking interface must be:

- Mobile-first.
- Fast.
- Simple.
- Accessible.
- Visually consistent with tenant branding.
- Easy to navigate.
- Clear about prices and durations.
- Clear about booking policies.

Use readable typography.

Recommended starting sizes:

- Body text: 16px or larger.
- Main instructions: 18px.
- Headings: 24–32px.
- Metadata: preferably 14px or larger.

Target WCAG 2.2 AA accessibility.

7.2 Booking journey

The normal booking flow:

1. Discover the business.
2. Browse services.
3. Select service.
4. Select branch when applicable.
5. Select preferred staff member or automatic assignment.
6. Select date.
7. Select available time.
8. Enter customer information.
9. Review booking.
10. Confirm appointment.
11. Receive confirmation.

Do not require customer registration.

Allow guest bookings subject to business policies.

7.3 Customer landing page

Display configurable sections:

- Hero/banner.
- Business introduction.
- Services.
- Service categories.
- Staff profiles.
- Gallery.
- Testimonials.
- Branch locations.
- Working hours.
- Contact details.
- Social media.
- Frequently asked questions.

Tenants should control which sections are visible and their display order.

7.4 Customer booking management

Customers should be able to:

- View their booking.
- Request cancellation.
- Request rescheduling.
- Receive reminders.
- Book again.
- Contact the business.

Use unguessable booking references and secure management tokens.

A booking reference identifies a booking but never grants access to it. Public access requires a random management token, stored only as a hash, delivered in the confirmation link. Customers who lost the link enter reference + phone/email and receive a fresh link at the contact on file. See "booking-engine.md" §15.

Do not expose appointments through sequential database IDs.

Cancellation and rescheduling must respect business policies ("booking-engine.md" §10.3 and §11).

---

8. Configurable Booking Page and Theme System

This is a core AlbaBook feature.

Each tenant must be able to customize the public customer-facing booking page without modifying source code.

8.1 Customization dashboard

Location:

"Dashboard → Booking Page → Appearance"

The editor should provide:

- Theme selection.
- Brand colours.
- Logo and cover image.
- Layout selection.
- Section visibility.
- Section ordering.
- Booking-flow settings.
- Desktop preview.
- Tablet preview.
- Mobile preview.
- Draft saving.
- Publishing.
- Version history.

8.2 Brand identity settings

Allow tenants to customize:

Property| Description
Primary colour| Main brand colour
Secondary colour| Supporting colour
Accent colour| Highlight colour
Background| Main page background
Text colour| Main text colour
Logo| Business logo
Cover image| Main hero image
Favicon| Browser icon
Typography| Supported font choices
Appearance| Light or dark where supported

Use validated colour values.

Do not permit arbitrary tenant CSS or JavaScript.

Automatically validate text contrast and provide safe defaults.

8.3 Layout templates

Provide predefined templates.

Initial templates:

Modern

- Clean interface.
- Spacious cards.
- Clear sections.
- Prominent booking actions.

Minimal

- Simple design.
- Less visual decoration.
- Focus on services and booking.

Premium

- Larger imagery.
- Elegant typography.
- Refined spacing.
- More prominent business branding.

Compact

- Reduced scrolling.
- Focused service selection.
- Compact booking interface.

Templates must use shared components and the same booking engine.

Do not create four separate booking applications.

8.4 Configurable sections

Tenants can enable, disable and reorder supported sections:

- Hero.
- Service categories.
- About.
- Services.
- Staff.
- Gallery.
- Testimonials.
- Locations.
- Opening hours.
- Contact information.
- Frequently asked questions.
- Social media.

Store ordering using a numeric sort position.

Validate all section identifiers against supported application components.

Do not render arbitrary HTML from tenant settings.

Section content (about text, FAQs, testimonials, gallery captions) is structured plain text, not HTML ("decisions.md" D-002):

- Text fields: plain text with line breaks, length-limited, autoescaped and rendered with "nl2br".
- FAQs: rows of question, answer and sort position.
- Testimonials: rows of author name, text, optional rating, source and sort position, confirmed by the tenant as genuine.
- Links: separate validated "https://" URL fields.

Content is part of the booking page draft and published version, so it previews, publishes and rolls back together with the theme.

8.5 Booking-flow preferences

Support configurable preferences:

Setting| Possible values
Booking flow| Step-by-step, compact
Staff selection| Required, optional, hidden
Branch selection| Required, optional, hidden
Service presentation| Cards, list
Calendar presentation| Standard, compact
Time-slot presentation| Grid, list
Show prices| Yes/no
Show duration| Yes/no
Customer account| Guest, optional account
Language| English and Swahili at launch ("decisions.md" D-017)

Visual settings must never override operational rules.

For example, a business requiring branch selection cannot allow customers to skip branch selection by hiding the field.

The backend must validate every booking independently of frontend configuration.

8.6 Live preview

Implement an editor with two main areas:

Left panel:

- Customization controls.
- Colour pickers.
- Layout selector.
- Section controls.
- Booking preferences.

Right panel:

- Live customer-facing preview.

Support desktop, tablet and mobile preview modes.

The preview should use the same Twig components and theme resolver as the published booking page.

Save changes as drafts.

Publishing should atomically activate a validated configuration version.

8.7 Theme storage

Recommended tables:

- "tenant_branding"
- "booking_page_settings"
- "booking_page_sections"
- "booking_page_versions"
- "booking_page_faqs"
- "booking_page_testimonials"
- "tenant_assets"

"booking_page_versions" stores the complete validated snapshot (theme, sections, booking-flow settings and content) for each published version. The public page renders only from the active published version.

Example theme configuration:

{
  "theme": {
    "primary_color": "#7655D9",
    "secondary_color": "#F3EFFF",
    "accent_color": "#16A085",
    "background_color": "#FFFFFF",
    "text_color": "#202124",
    "appearance": "light",
    "layout": "modern",
    "font_family": "inter"
  },
  "sections": {
    "hero": true,
    "service_categories": true,
    "about": true,
    "services": true,
    "staff": true,
    "gallery": false,
    "testimonials": true,
    "locations": true,
    "opening_hours": true,
    "contact": true,
    "faqs": true,
    "social": false
  },
  "booking": {
    "flow": "step_by_step",
    "staff_selection": "optional",
    "service_display": "cards",
    "show_prices": true,
    "show_duration": true
  }
}

This is a configuration example, not a fixed application configuration.

Validate it against a versioned application schema.

8.8 Theme rendering

Use CSS custom properties.

Example:

.booking-page {
    --brand-primary: #7655D9;
    --brand-secondary: #F3EFFF;
    --brand-accent: #16A085;
    --brand-background: #FFFFFF;
    --brand-text: #202124;
}

.booking-page .btn-primary {
    background-color: var(--brand-primary);
}

.booking-page .section-highlight {
    background-color: var(--brand-secondary);
}

The example values are defaults only.

The actual values must come from the published tenant theme.

Validate colours before placing them into CSS variables.

Avoid dynamically constructing Tailwind class names from database values because Tailwind may not compile unknown classes.

Use static classes and CSS variables for dynamic colours.

8.9 Draft and published versions

Separate unpublished settings from the active public configuration.

Recommended workflow:

1. Tenant opens appearance editor.
2. Application loads the current draft or published configuration.
3. Tenant changes settings.
4. Preview renders the draft.
5. Tenant saves the draft.
6. Application validates the complete configuration.
7. Tenant publishes.
8. The new version becomes active.
9. Public booking pages load the published version.
10. Previous versions remain available for rollback according to retention policy.

Do not allow partially saved configuration to break the public booking page.

8.10 White-label controls

Support subscription-based branding features.

Examples:

- Show or hide "Powered by AlbaBook".
- Custom booking domains.
- Advanced typography.
- Additional templates.
- More gallery images.
- Advanced branding options.

These must be controlled by subscription entitlements, not hardcoded plan-name checks.

---

9. Booking Engine and Availability

The booking engine is the most critical part of the platform.

It must prevent double bookings and account for every relevant scheduling constraint.

This section is a summary. "booking-engine.md" is the authoritative specification: invariants, data model, availability algorithm, staff assignment, holds, the exact lock protocol, idempotency, lifecycle, rescheduling, overrides, pricing, deposits, references and the public booking API. Where they differ, "booking-engine.md" wins.

9.1 Availability factors

Availability calculations must consider:

- Tenant working hours.
- Branch working hours.
- Date-specific overrides (public holidays, special hours).
- Service duration, variant duration and add-on time.
- Service buffers.
- Staff schedules.
- Staff breaks.
- Staff service eligibility.
- Existing appointments.
- Resource availability.
- Blocked dates.
- Leave.
- Booking holds.
- Minimum advance notice.
- Maximum advance booking window.
- Tenant booking policies.

9.2 Timezone handling

Store appointment timestamps in UTC.

Store tenant timezone using an IANA identifier. Branches may override it; the engine always uses the branch timezone.

Example:

"Africa/Nairobi"

Store opening hours, shifts and breaks as local wall-clock times, not UTC.

Run the database session with "time_zone = '+00:00'" and PHP with "date.timezone = UTC".

Convert timestamps for customer and tenant display.

Compute "today" and other day boundaries in the branch's local day.

Do not assume every tenant operates in the same timezone.

9.3 Availability calculation

Use interval overlap logic.

Two intervals overlap when:

existing.start_at < requested.end_at
AND
existing.end_at > requested.start_at

Include buffers when calculating occupied time.

Never rely on frontend availability alone.

Recalculate availability immediately before creating the appointment.

9.4 Booking holds

Implement temporary booking holds.

A hold is an occupancy: it blocks time exactly like an appointment and must be created under the same lock protocol (see 9.5).

A hold should:

- Reserve required staff and resources temporarily.
- Have an expiration time.
- Be tenant-scoped.
- Be released after expiry.
- Be released after failed or abandoned checkout.
- Be validated during final booking confirmation.

A five-minute hold is a possible initial default, but it must be configurable.

9.5 Concurrency protection

A booking must be created inside a database transaction.

Suggested workflow:

1. Validate the tenant and input outside the transaction.
2. Set isolation level READ COMMITTED and begin the transaction.
3. Claim the idempotency key.
4. As the first locking statements, lock the involved "staff" rows, then "resources" rows, "FOR UPDATE", each in ascending ID order. These rows are the availability guard locks.
5. Recalculate availability after the locks are held.
6. Validate the booking hold and booking policy.
7. Match or create the customer.
8. Insert appointment, appointment items (with snapshots), resource allocations.
9. Insert appointment event and outbox event.
10. Convert the hold, bump the schedule version, complete the idempotency record.
11. Commit.

If any validation fails, roll back. Retry deadlocks and lock wait timeouts up to three times.

Under MySQL's default REPEATABLE READ, a re-check made after waiting for a lock can read a stale snapshot and miss a concurrent booking. That is why the isolation level and statement order above are mandatory ("booking-engine.md" §9, "decisions.md" D-009).

Do not rely on a simple availability SELECT followed by INSERT.

Do not keep database transactions open while calling external APIs.

Use idempotency keys for booking creation.

---

10. Appointment Management

10.1 Appointment lifecycle

Statuses:

- pending (blocks time)
- confirmed (blocks time)
- checked_in (blocks time, optional step)
- completed
- no_show
- cancelled
- rejected (business declined a pending request)
- expired (pending request or unpaid deposit timed out)

"Rescheduled" is an event, not a status. Allowed transitions, actors and conditions are defined in "booking-engine.md" §10.

Every status change uses optimistic concurrency (a "version" column) and writes an appointment event and outbox event in the same transaction.

Keep payment status separate.

Keep notification delivery status separate.

Do not use one status field to represent unrelated workflows.

10.2 Appointment information

Store:

- Tenant.
- Branch.
- Customer, plus a snapshot of the customer's name and contact as entered.
- Assigned staff (per item; the appointment keeps a primary staff member for display).
- Start time.
- End time.
- Appointment status.
- Payment status (derived from payment records).
- Total, deposit amount and deposit due time.
- Version (optimistic concurrency).
- Booking source (public_page, widget, api, dashboard, walk_in, import).
- Customer notes.
- Internal notes.
- Reference.
- Created timestamp.
- Updated timestamp.

10.3 Appointment items

An appointment may contain one or more services.

Store historical snapshots:

- Service name.
- Description where necessary.
- Unit price.
- Currency.
- Duration.
- Quantity.
- Add-on details.

Historical appointment values must not change when the tenant updates a service later.

10.4 Calendar

Provide:

Customer calendar

- Available dates.
- Available time slots.
- Disabled unavailable dates.
- Clear selected date and time.
- Morning, afternoon and evening grouping where appropriate.

Tenant calendar

- Day view.
- Week view.
- Month view.
- Staff filters.
- Branch filters.
- Appointment status filters.
- Service filters.
- Search.
- Appointment details drawer.

FullCalendar may be used for the management calendar.

Review its licensing before using commercial resource/timeline functionality.

Use the same backend scheduling engine regardless of calendar library.

---

11. Notification System

Support three independent communication channels:

1. WhatsApp.
2. SMS.
3. Email.

Tenants configure the channels used for each notification event.

11.1 Notification events

Initial events:

- Booking received.
- Booking confirmed.
- Booking rejected.
- Appointment reminder.
- Appointment rescheduled.
- Appointment cancelled.
- Appointment expired.
- Appointment completed.
- Deposit/payment update.

Reminders are scheduled rows tied to the appointment version. The worker re-checks the appointment at send time and skips reminders for cancelled, expired or rescheduled appointments ("booking-engine.md" §17).

11.2 Provider architecture

Use adapters.

Example contract:

interface NotificationProvider
{
    public function send(
        string $recipient,
        string $template,
        array $variables
    ): NotificationResult;
}

Implement independent adapters:

Notifications/
├── Contracts/
│   └── NotificationProvider.php
├── Providers/
│   ├── WhatsApp/
│   ├── Sms/
│   └── Email/
├── Services/
│   ├── NotificationRouter.php
│   ├── NotificationDispatcher.php
│   ├── NotificationPreferenceService.php
│   └── NotificationTemplateService.php
├── Queue/
└── Webhooks/

11.3 WhatsApp

Support two modes:

- AlbaTech-managed WhatsApp account.
- Tenant-owned WhatsApp Business account.

The tenant must explicitly choose and authorize the account used.

Never silently use AlbaTech's messaging account as a fallback.

Use the official WhatsApp Business Platform.

Store access credentials encrypted at rest.

Support approved templates for business-initiated messages outside the customer service window.

Capture and respect customer opt-in and opt-out preferences.

11.4 SMS

Launch provider: Africa's Talking. Infobip is added later as a failover adapter. Twilio is not used for Kenyan traffic ("decisions.md" D-014).

Launch on one shared registered sender ID ("AlbaBook"); tenant-specific sender IDs are a later entitlement. Start sender ID registration at the beginning of Phase 8.

Select the provider through configuration and provider adapters.

Evaluate:

- Kenyan network coverage.
- Pricing.
- Sender ID requirements.
- Delivery receipts.
- API reliability.
- Regulatory requirements.

Normalize Kenyan phone numbers.

Track multipart SMS segments and costs.

11.5 Email

Support:

- Booking confirmations.
- Reminders.
- Cancellation notifications.
- Account invitations.
- Password reset.
- Business notifications.

Use a provider abstraction so SMTP or HTTP email providers can be changed without rewriting business logic.

11.6 Channel preferences

Tenants should configure channels per event.

Example:

Event| WhatsApp| SMS| Email
Booking confirmation| On| Optional| On
24-hour reminder| On| Optional| Optional
2-hour reminder| Optional| On| Off
Cancellation| On| On| Optional

These are examples, not fixed rules.

11.7 Fallback logic

Example:

1. Attempt WhatsApp.
2. Process provider response and delivery webhook.
3. If terminal delivery failure occurs, check tenant fallback policy.
4. If SMS is enabled, send SMS.
5. Record both attempts.

Do not send SMS simply because a delivery receipt is delayed.

An accepted API request does not necessarily mean a message has been delivered.

11.8 Queue and outbox

Use an outbox pattern.

When an appointment is created:

- Save appointment.
- Save appointment items.
- Save outbox event.

All within the same database transaction.

A worker processes the outbox after commit.

Notification delivery occurs outside the appointment transaction.

Outbox delivery is at-least-once. Every consumer must be idempotent, keyed by the outbox event ID.

Use bounded retries, exponential backoff and idempotency keys. After the final retry, mark the job dead and surface it in delivery logs and operational alerts.

---

12. Booking Integrations

12.1 Direct booking link

Every tenant receives a public booking URL.

Example:

"https://book.albabook.co.ke/royal-beauty"

The tenant can copy and share the link through:

- WhatsApp.
- Instagram.
- Facebook.
- Google Business Profile.
- Email.
- Their website.

12.2 Embeddable widget

Allow tenants to embed a booking experience in their own website.

Example:

<div id="albabook-widget"></div>

<script
    src="https://book.albabook.co.ke/_/widget.js"
    data-business="royal-beauty"
    data-theme="light"
    defer>
</script>

The widget should load a sandboxed or appropriately isolated iframe.

Support:

- Responsive sizing.
- Theme configuration.
- Branch preselection.
- Service preselection.
- Language settings.
- Verified origin policies.
- Versioning.

Use Content Security Policy "frame-ancestors" and strict "postMessage" validation.

Never place private API secrets in widget JavaScript.

12.3 REST API

Base URL:

"https://api.albabook.co.ke/v1"

Public and private endpoints are separated by path ("decisions.md" D-008).

Public endpoints (no credentials; tenant from slug; full contract in "booking-engine.md" §18):

GET    /public/businesses/{slug}
GET    /public/businesses/{slug}/services
GET    /public/businesses/{slug}/branches
GET    /public/businesses/{slug}/availability/days
GET    /public/businesses/{slug}/availability/slots
POST   /public/businesses/{slug}/holds
DELETE /public/businesses/{slug}/holds/{holdToken}
POST   /public/businesses/{slug}/bookings
POST   /public/businesses/{slug}/booking-lookup
GET    /public/bookings/{manageToken}
POST   /public/bookings/{manageToken}/cancel
POST   /public/bookings/{manageToken}/reschedule

Private tenant endpoints (tenant from session or API credential; IDs are UUIDs):

GET    /appointments
POST   /appointments
GET    /appointments/{uuid}
PATCH  /appointments/{uuid}
POST   /appointments/{uuid}/confirm
POST   /appointments/{uuid}/cancel
POST   /appointments/{uuid}/reschedule

GET    /services
POST   /services
PATCH  /services/{uuid}

GET    /staff
POST   /staff
PATCH  /staff/{uuid}

GET    /customers
GET    /payments
POST   /payments
GET    /reports/summary

Private endpoints require authenticated sessions or scoped API credentials.

All "POST" endpoints that create bookings, holds or payments require an "Idempotency-Key" header.

Errors use one JSON shape: { "error": { "code", "message", "details" } }. Codes are listed in "booking-engine.md" §18.

Version the API in the path ("/v1"). Breaking changes require "/v2"; additive changes do not.

12.4 API credentials

Each credential should have:

- Credential ID.
- Tenant ID.
- Public identifier.
- Hashed secret.
- Scopes.
- Expiration.
- Revocation status.
- Last-used timestamp.

Show the secret only once.

Never store the plaintext secret.

Support key rotation and revocation.

12.5 Webhooks

Support tenant-configurable webhooks.

Example events:

- appointment.created
- appointment.confirmed
- appointment.cancelled
- appointment.rescheduled
- appointment.completed

Sign webhook payloads. Each endpoint has a per-endpoint signing secret, stored encrypted in `webhook_endpoints.secret_encrypted` and shown once at creation.

Include timestamps.

Prevent replay attacks.

Track delivery attempts and responses.

---

13. Database Architecture

Use MySQL InnoDB.

Recommended conventions:

- "BIGINT UNSIGNED" primary keys for internal joins.
- A "uuid" column on any entity exposed in a URL or API.
- "tenant_id" on tenant-owned tables, with "UNIQUE (tenant_id, id)" so children can use composite foreign keys.
- "BIGINT" minor units for money ("amount_minor"), never floats ("decisions.md" D-006).
- Explicit ISO 4217 currency codes.
- UTC timestamps. The DB connection sets "time_zone = '+00:00'" so "CURRENT_TIMESTAMP" defaults are UTC.
- "utf8mb4".
- Foreign keys.
- Composite tenant-aware relationships.
- Every foreign key to "tenants" is "ON DELETE RESTRICT"; tenants are archived and anonymised, never deleted ("decisions.md" D-024).
- Coded values that are shared, carry metadata, are extended without a deploy, or differ per tenant use lookup tables; small single-table internal states stay "ENUM" ("decisions.md" D-023).
- Migrations for every schema change.

The following is the recommended logical schema. Implement it as ordered migrations, reviewing nullability, deletion policies and indexes for each relationship. The migrations live in "database/migrations/" ("000_reference_data.sql" … "010_audit.sql").

13.0 Reference data (lookup tables, seeded in "000_reference_data.sql")

currencies                   (with minor_unit_exponent)
locales
tenant_statuses
appointment_statuses         (with blocks_time, is_terminal)
communication_channels
notification_events
payment_methods
payment_providers
features                     (subscription features and metered usage)
booking_page_section_types

Tenant-scoped lookup: "staff_leave_types" (13.3).

13.1 Platform and tenancy tables

tenants                      (includes "default_locale", VAT fields and KRA PIN)
tenant_domains
tenant_slug_history
tenant_status_policies
tenant_settings
tenant_branding
tenant_users
platform_users
platform_user_mfa_factors
platform_sessions
platform_user_permissions
support_access_sessions
tenant_subscriptions
plans
plan_features
tenant_feature_overrides
usage_records
subscription_events
subscription_invoices
subscription_payments

"tenant_settings" is a narrow store for low-traffic preferences only, one row per tenant with typed columns and a schema-validated JSON column. Booking policies, notification preferences and branding have their own tables ("decisions.md" D-003).

"subscription_invoices" and "subscription_payments" record what tenants pay AlbaTech. They are separate from customer payments in 13.5.

"platform_user_permissions" grants platform-scope permissions directly to a "platform_users" row. It is never used for tenant memberships ("decisions.md" D-020).

13.2 Authentication and authorization

users
user_sessions
remember_tokens
user_mfa_factors
user_recovery_codes
email_verification_tokens
email_change_tokens
password_reset_tokens
tenant_invitations
tenant_invitation_roles
login_attempts
rate_limit_buckets
roles
permissions
role_permissions
membership_roles
membership_branches

Keep platform administrators and tenant access boundaries separate. "platform_users" are a separate table with separate credentials and sessions.

A user may have membership in more than one tenant.

Roles are assigned per membership ("membership_roles" references "tenant_users"), never per user, so a role in one tenant cannot apply in another. "membership_branches" restricts a membership to specific branches; no rows means all branches ("decisions.md" D-007).

13.3 Business operations

business_categories
tenant_categories
branches                     (includes nullable "timezone" overriding the tenant)
business_hours
schedule_overrides
blocked_periods              (replaces "blocked_dates"; scoped to branch, staff or resource)
booking_policies             (tenant row + optional branch rows)
service_categories
services
service_variants
service_addons
service_branches
service_resource_requirements
staff                        (optional link to a tenant membership)
staff_branches
staff_services
staff_schedules
staff_breaks
staff_leave_types            (tenant-scoped lookup, defaults copied at tenant creation)
staff_leave
customers
customer_match_keys           (policy-driven unique identity lookups)
customer_consents
resource_types
resources                    (includes "capacity")
resource_schedules

13.4 Appointments

appointments
appointment_items            (per-item staff, customer time and occupied time)
appointment_resources
appointment_events
appointment_access_tokens
booking_verification_codes   (hashed OTPs for booking and manage lookup)
booking_holds
booking_hold_items
booking_hold_resources
booking_idempotency_keys
schedule_versions
scheduled_notifications

Columns, indexes and rules for these tables are in "booking-engine.md" §4–§15.

13.5 Customer payments

payments
refunds
payment_provider_events
tenant_payment_accounts

These record what customers pay the tenant. MVP supports manually recorded payments; automated M-Pesa writes to the same tables later ("booking-engine.md" §14, "decisions.md" D-001).

13.6 Booking page configuration

tenant_branding
booking_page_settings
booking_page_sections
booking_page_faqs
booking_page_testimonials
booking_page_versions
tenant_assets

13.7 Notifications

notification_providers
tenant_notification_accounts
notification_preferences
notification_templates
notification_queue
notification_logs
notification_delivery_events

13.8 Integrations

api_credentials
webhook_endpoints
webhook_deliveries
integration_events
outbox_events

13.9 Auditing

audit_logs

Each audit row records: tenant (nullable for platform events), actor type (tenant_user, platform_user, support_session, api_credential, system, customer), actor ID, action, target type and ID, before/after values for changed fields (personal data masked), IP, user agent, request ID and UTC timestamp. Audit rows are append-only.

13.10 Important indexes

Examples:

CREATE INDEX idx_appointments_tenant_branch_start
ON appointments (tenant_id, branch_id, start_at);

CREATE INDEX idx_appointments_tenant_status_start
ON appointments (tenant_id, status, start_at);

CREATE INDEX idx_items_staff_occupancy
ON appointment_items (tenant_id, staff_id, occupied_start_at, occupied_end_at);

CREATE INDEX idx_alloc_resource_occupancy
ON appointment_resources (tenant_id, resource_id, occupied_start_at, occupied_end_at);

CREATE UNIQUE INDEX uq_appointments_tenant_reference
ON appointments (tenant_id, reference);

CREATE INDEX idx_customers_tenant_phone
ON customers (tenant_id, phone_e164);

CREATE INDEX idx_service_branches_branch
ON service_branches (tenant_id, branch_id, service_id);

CREATE INDEX idx_staff_branches_branch
ON staff_branches (tenant_id, branch_id, staff_id);

CREATE INDEX idx_booking_holds_expiry
ON booking_holds (tenant_id, status, expires_at);

CREATE INDEX idx_payments_tenant_received
ON payments (tenant_id, status, received_at);

Services and staff can belong to several branches, so branch filtering goes through "service_branches" and "staff_branches", not a "branch_id" column on "services" or "staff".

CREATE INDEX idx_notification_queue_processing
ON notification_queue (status, scheduled_at, id);

Use composite foreign keys where possible.

For example:

FOREIGN KEY (tenant_id, customer_id)
REFERENCES customers (tenant_id, id)

This prevents an appointment from referencing a customer belonging to another tenant.

Create the necessary composite unique key on the referenced table.

Do not create indexes indiscriminately.

Use "EXPLAIN ANALYZE" against actual application queries.

---

14. Authentication and Permissions

14.1 Authentication

Use:

- "password_hash()".
- "password_verify()".
- Secure session cookies.
- Session ID regeneration after authentication.
- HTTP-only cookies.
- Secure cookies over HTTPS.
- Appropriate SameSite settings.
- Session expiration.
- Login rate limiting.
- Password reset tokens.
- CSRF protection.
- Email verification.
- TOTP multi-factor authentication with recovery codes: mandatory for platform administrators, optional for tenant users, and enforceable by tenants for privileged roles ("decisions.md" D-004).
- Step-up re-authentication for sensitive actions (billing, API credentials, role changes, data export).
- Database-backed sessions so sessions can be listed and revoked.
- Host-only cookies (no "Domain=.albabook.co.ke"), separate cookie names for "app." and "admin.".

Never store plaintext passwords.

"auth.md" is authoritative for authentication details.

14.2 Granular permissions

This is the canonical permission catalogue. Other documents reference these identifiers; they do not define their own.

dashboard.view

appointments.view
appointments.view_own          (staff: only appointments assigned to their staff record)
appointments.create
appointments.update
appointments.confirm
appointments.reschedule
appointments.cancel
appointments.override_policy
appointments.override_conflict

calendar.manage_blocks         (blocked periods, leave approval)

customers.view
customers.create
customers.update
customers.delete
customers.view_notes
customers.export

payments.view
payments.record
payments.refund

services.view
services.manage

staff.view
staff.manage

branches.view
branches.manage

resources.view
resources.manage

booking_page.view
booking_page.manage
booking_page.publish

reports.view
reports.view_financial
reports.export

notifications.view
notifications.manage

integrations.view
integrations.manage

settings.view
settings.update

users.manage
roles.manage

billing.view
billing.manage

Platform administrators use a separate catalogue stored in "permissions" with "scope = 'platform'". These identifiers never grant tenant permissions and are never assigned to tenant roles. Administrators receive them through "platform_user_permissions"; a composite foreign key on "(permission_scope, permission_id)" plus a "CHECK" on each link table rejects a platform-scope permission in "role_permissions" and a tenant-scope permission in "platform_user_permissions" ("decisions.md" D-020):

platform.tenants.manage
platform.support.read
platform.support.write
platform.billing.manage
platform.integrations.manage
platform.audit.view
platform.config.manage

Branch scope applies on top of permissions: a membership limited to certain branches only sees and acts on those branches.

Permissions must be checked on the server.

Hiding a button is not authorization.

14.3 Suggested default roles

- Owner.
- Business administrator.
- Branch manager.
- Receptionist.
- Staff member.
- Accountant.

Roles should be configurable.

Do not assume every tenant uses the same organizational structure.

---

15. Subscription and Feature Entitlements

Implement subscriptions as a separate module.

Suggested tables:

plans
plan_features
tenant_subscriptions
tenant_feature_overrides
usage_records
subscription_events
subscription_invoices
subscription_payments

Implement an "EntitlementService".

Example:

$entitlements->allows(
    $tenantId,
    'custom_booking_domain'
);

Do not write:

if ($plan === 'premium') {
    // Enable feature
}

Feature availability should be data-driven.

Possible billable features:

- Number of staff.
- Number of branches.
- Monthly bookings.
- Custom domains.
- Advanced themes.
- White-label branding.
- Messaging usage.
- API access.
- Advanced reports.

Keep subscription enforcement separate from business logic.

Usage limits:

- Count usage from authoritative records ("usage_records" or the source tables), not from client-side counters.
- Check count-based limits (staff, branches) when the resource is created.
- Do not reject a customer's public booking because the tenant exceeded a monthly booking limit without warning. Use soft limits: warn the tenant at 80% and 100%, allow a configurable grace margin, then disable new public bookings with a clear message to the tenant, never a cryptic error to the customer.

M-Pesa integration can be introduced after the initial booking MVP is stable. Customer payments are recorded manually until then (13.5). Customer payments always go to the tenant's own till or paybill; AlbaTech never holds customer funds ("decisions.md" D-016).

AlbaTech's own subscription invoices to tenants must be KRA eTIMS-compliant from launch ("decisions.md" D-015).

---

16. Reusable UI Component System

Build reusable Twig components.

Recommended structure:

resources/views/components/
├── layout/
│   ├── app-shell.twig
│   ├── public-shell.twig
│   ├── admin-shell.twig
│   ├── sidebar.twig
│   ├── header.twig
│   └── breadcrumbs.twig
│
├── forms/
│   ├── input.twig
│   ├── select.twig
│   ├── textarea.twig
│   ├── checkbox.twig
│   ├── radio.twig
│   ├── date-picker.twig
│   ├── time-picker.twig
│   └── form-error.twig
│
├── buttons/
│   ├── primary.twig
│   ├── secondary.twig
│   ├── danger.twig
│   └── icon.twig
│
├── feedback/
│   ├── alert.twig
│   ├── toast.twig
│   ├── modal.twig
│   ├── empty-state.twig
│   ├── loading.twig
│   └── status-badge.twig
│
├── data/
│   ├── table.twig
│   ├── pagination.twig
│   ├── stat-card.twig
│   ├── service-card.twig
│   ├── staff-card.twig
│   └── appointment-card.twig
│
└── booking/
    ├── business-header.twig
    ├── service-selector.twig
    ├── branch-selector.twig
    ├── staff-selector.twig
    ├── calendar.twig
    ├── time-slots.twig
    ├── customer-form.twig
    └── booking-summary.twig

16.1 Component rules

Every reusable component should:

- Have a clear responsibility.
- Receive explicit data.
- Avoid database queries.
- Avoid business logic.
- Support appropriate variants.
- Use consistent spacing.
- Support accessible labels.
- Escape untrusted content.
- Avoid inline event-handler JavaScript.

16.2 Reusable backend components

Also build reusable services:

- PaginationService.
- AuditLogService.
- FileUploadService.
- PhoneNumberService.
- CurrencyFormatter.
- DateTimeService.
- PermissionService.
- NotificationRouter.
- TenantSettingsService.
- ThemeResolver.
- BookingReferenceGenerator.
- IdempotencyService.

Avoid duplicating these across modules.

---

17. Configurable Application Design

Do not hardcode business decisions into controllers or templates.

The following must be configurable:

Area| Configuration source
Business categories| Database
Services| Tenant records
Service pricing| Tenant records
Appointment duration| Service configuration
Working hours| Business/branch schedules
Booking intervals| Booking policies
Timezone| Tenant/branch settings
Booking policies| "booking_policies" (tenant row, branch overrides)
Notification channels| Tenant preferences
Notification providers| Integration configuration
Theme colours| Tenant branding
Page layouts| Supported template definitions
Visible page sections| Booking page configuration
Subscription features| Entitlement tables
Currency| Tenant/business configuration
Supported languages| Platform configuration (English everywhere; Swahili on customer-facing pages and messages)
Tax (VAT registration, rate)| Tenant tax settings ("decisions.md" D-015)
M-Pesa till/paybill| "tenant_payment_accounts" ("decisions.md" D-016)
File size limits| Application configuration
Rate limits| Configuration and policy
Custom domains| Verified domain records

17.1 Configuration hierarchy

Use a predictable precedence model:

1. Platform defaults.
2. Platform-approved configuration.
3. Tenant-level settings.
4. Branch-level overrides where supported.
5. Request-specific validated preferences where permitted.

Never allow a lower-level configuration to override security restrictions or mandatory platform rules.

17.2 Configuration validation

Validate configuration when saving, not only when rendering.

Use typed DTOs or dedicated configuration validators.

Reject unknown fields where appropriate.

Support configuration schema versions and safe migrations.

17.3 Avoid configuration sprawl

Do not put every setting into one generic key/value table.

Use dedicated relational tables for frequently queried business entities.

Use JSON for structured settings such as theme configuration and section preferences where appropriate.

Anything the booking engine reads on every request, or that the application filters or joins on, gets typed columns in a dedicated table. "tenant_settings" is reserved for low-traffic preferences ("decisions.md" D-003).

---

18. Clean URLs and Routing

Use a front controller.

Public entry point:

"public/index.php"

Routes are grouped by host. Each host has its own route file and middleware stack.

Tenant portal — app.albabook.co.ke (routes/web.php):

/
/register
/login
/logout
/forgot-password
/reset-password/{token}
/verify-email
/invite/{token}
/two-factor
/workspaces                   (workspace picker for multi-membership users)

Public booking — book.albabook.co.ke (routes/public.php), canonical form ("decisions.md" D-005):

/{slug}
/{slug}/services
/{slug}/book                      (booking flow: service, branch, staff, date, time, details, review)
/{slug}/confirmation/{manageToken}
/{slug}/manage/{token}
/{slug}/lookup                    (reference + contact → resend manage link)
/_/widget.js
/_/assets/...

On a verified custom domain the same routes are served without the "{slug}" segment.

Slugs are lowercase "a-z", "0-9" and hyphens, 3–60 characters, and must not match the reserved list (for example: "_", "admin", "api", "app", "assets", "book", "help", "login", "manage", "static", "support", "widget", "www"). Renamed slugs redirect permanently via "tenant_slug_history".

Tenant portal continued:

/dashboard
/calendar
/appointments
/appointments/{uuid}
/services
/services/create
/staff
/customers
/branches
/resources

/booking-page
/booking-page/appearance
/booking-page/sections
/booking-page/settings
/booking-page/preview
/booking-page/publish

/settings
/settings/notifications
/settings/integrations
/settings/billing

Platform admin — admin.albabook.co.ke (routes/admin.php):

/login
/dashboard
/tenants
/subscriptions
/plans
/integrations
/audit-logs
/support-sessions

API — api.albabook.co.ke (routes/api.php):

/v1/public/...
/v1/...

Inbound provider webhooks (routes/webhooks.php):

/webhooks/{provider}/{endpointUuid}

Protect routes with middleware.

Do not expose sequential database IDs in public booking URLs.

---

19. Security Requirements

Security is a release-blocking requirement.

19.1 Database security

- Use PDO prepared statements.
- Never concatenate untrusted input into SQL.
- Enforce tenant scopes.
- Use foreign keys.
- Apply least-privilege database credentials.
- Avoid exposing database errors to users.

19.2 Cross-site scripting

- Enable Twig autoescaping.
- Escape output according to context.
- Never render untrusted tenant content as raw HTML.
- Do not accept tenant-provided executable JavaScript.
- Sanitize any rich-text content with a maintained HTML sanitizer if rich text is introduced.

19.3 CSRF

Protect state-changing browser requests.

Use unpredictable CSRF tokens and verify them server-side.

19.4 File uploads

Validate:

- MIME type.
- File extension.
- File size.
- Image dimensions.
- File contents.
- Upload authorization.

Generate random filenames.

Do not allow uploaded files to execute.

Store private documents outside the public web root.

19.5 API security

- Scoped API credentials.
- Secret hashing.
- Rate limiting.
- Expiration.
- Revocation.
- Request validation.
- Tenant isolation.
- Audit logging.

19.6 Webhook security

- Verify signatures.
- Validate timestamps.
- Prevent replay attacks.
- Record delivery attempts.
- Enforce request size limits.
- Do not trust the webhook source without verification.

19.7 Theme editor security

Never allow:

- Arbitrary PHP.
- Arbitrary JavaScript.
- Arbitrary CSS.
- Unrestricted HTML injection.
- Unverified remote scripts.

Use application-defined templates and supported configuration options.

---

20. Performance and Scalability

Start with a modular monolith.

Do not introduce microservices prematurely.

20.1 Database performance

- Index tenant-scoped queries.
- Avoid N+1 queries.
- Use pagination.
- Select only required columns.
- Use aggregate queries for dashboard statistics.
- Cache frequently accessed configuration.
- Analyze slow queries.

20.2 Caching

Tenant-specific cache keys must include tenant identity.

Examples:

tenant:12:services
tenant:12:branding
tenant:12:booking-settings
tenant:12:branch:3:avail:2026-11-21:v42

Availability keys include a schedule version that increments in the same transaction as any write affecting that branch and date, so stale entries are never read after a change ("booking-engine.md" §6).

Invalidate relevant cache entries when data changes.

Do not share private tenant cache entries.

Use cache carefully for availability because appointment data changes frequently.

20.3 Background workers

Implement CLI workers for:

- Outbox processing.
- Notifications.
- Appointment reminders.
- Webhook delivery.
- Booking-hold cleanup.
- Temporary file cleanup.
- Reports.

Use MySQL queue claiming with "FOR UPDATE SKIP LOCKED" where supported.

Keep database locks short.

Never hold a transaction open while sending an external HTTP request.

20.4 Future scaling

Potential evolution:

1. Single application server.
2. Dedicated background workers.
3. Redis caching and queue support.
4. Object storage for assets.
5. Read replicas where justified.
6. Separate reporting infrastructure if required.

Scale based on observed load.

---

21. Testing Strategy

21.1 Unit tests

Test:

- Availability calculations.
- Appointment lifecycle rules.
- Pricing calculations.
- Notification routing.
- Theme validation.
- Permission checks.
- Subscription entitlements.
- Booking reference generation.

21.2 Integration tests

Test:

- Database repositories.
- Tenant resolution.
- Booking transactions.
- Notification adapters.
- File uploads.
- API authentication.
- Webhook signature verification.

21.3 Security tests

Test:

- Tenant A cannot access Tenant B's appointments.
- Tenant A cannot access Tenant B's customers.
- Tenant A cannot modify Tenant B's services.
- Invalid API keys are rejected.
- Revoked credentials are rejected.
- Unauthorized users cannot change billing.
- Customers cannot access another customer's booking.
- Invalid booking tokens are rejected.
- Unverified domains cannot resolve tenants.
- Uploaded executable files are rejected.
- Theme settings cannot inject executable content.

21.4 Concurrency tests

Run concurrency tests against real MySQL using separate PHP processes, not mocked repositories or a single connection.

Test two customers attempting to book the same staff member and time simultaneously.

Only one conflicting booking should succeed.

The full required matrix (20-way races, hold vs booking, reschedule races, resource capacity, idempotency replays, deadlock retry, integrity check) is in "booking-engine.md" §20.

Also test:

- Shared resource conflicts.
- Expired booking holds.
- Duplicate booking requests.
- Duplicate notification jobs.
- Rescheduling conflicts.

21.5 End-to-end tests

Create at least two tenants:

- Royal Beauty Hub.
- Beauty Studio.

Give them different:

- Services.
- Staff.
- Branches.
- Branding.
- Notification preferences.
- Booking policies.

Verify complete data isolation and independent booking experiences.

A cross-tenant isolation failure must block release.

---

22. UI and UX Design Standards

AlbaBook should have a premium, modern SaaS appearance.

Suggested initial platform identity:

- Deep navy: "#171A2C"
- Violet: "#6D5CE8"
- Teal: "#16A085"
- Light background: "#F3F4F8"

These are platform defaults, not mandatory tenant colours.

22.1 Design requirements

- Mobile-first.
- Responsive.
- Spacious layouts.
- Consistent spacing.
- Readable text.
- Strong contrast.
- Clear visual hierarchy.
- Large touch targets.
- Accessible form labels.
- Visible keyboard focus.
- Consistent button variants.
- Helpful empty states.
- Loading indicators.
- Clear error messages.

22.2 Avoid

- Tiny text.
- Crowded forms.
- Excessive decorative elements.
- Unnecessary gradients.
- Icon-only critical actions.
- Inconsistent spacing.
- Excessive modal dialogs.
- Overloaded dashboards.

22.3 Responsive behavior

Tenant dashboard:

- Desktop: full sidebar and content.
- Tablet: collapsible sidebar.
- Mobile: compact navigation and mobile-friendly tables/cards.

Customer booking:

- Mobile: optimized single-column flow.
- Desktop: wider content and optional summary panel.
- All booking steps must remain usable on smaller screens.

---

23. SEO and Public Booking Pages

Each public booking page should have configurable metadata.

Support:

- Page title.
- Meta description.
- Canonical URL.
- Open Graph title.
- Open Graph description.
- Open Graph image.
- Favicon.
- Structured data where applicable.

Example:

Title: Royal Beauty Hub | Book an Appointment

Description: Explore beauty treatments and book your next
appointment at Royal Beauty Hub.

Generate metadata from tenant configuration and business information.

The tenant-editable values (title, description, Open Graph fields, favicon asset, indexing permission) are stored on "booking_page_settings" and published with the booking page version. Canonical URL and structured data are generated by the application, never entered by the tenant.

Avoid duplicate or empty metadata.

Use appropriate canonical URLs.

Ensure public pages are crawlable where the tenant permits indexing.

Do not expose private customer or appointment information to search engines.

---

24. Custom Domains

Initially use AlbaBook booking URLs.

Later support verified custom domains.

Example:

"booking.royalbeauty.co.ke"

Implementation requirements:

1. Tenant submits domain.
2. Generate verification token.
3. Tenant adds required DNS record.
4. Verify DNS ownership.
5. Associate domain with tenant.
6. Provision TLS.
7. Activate domain.
8. Validate host against verified domain records on each request.

Never trust the HTTP Host header without verification.

Enforce unique active domain ownership.

---

25. Onboarding

Support two onboarding methods.

25.1 Self-service registration

Workflow:

1. Register account.
2. Verify email.
3. Create business profile.
4. Select business category.
5. Select timezone.
6. Generate unique booking slug.
7. Add branches.
8. Add services.
9. Add staff.
10. Configure working hours.
11. Configure booking policies.
12. Configure notification channels.
13. Customize booking page.
14. Test booking.
15. Publish.

25.2 AlbaTech-assisted onboarding

Platform administrator:

1. Creates tenant.
2. Assigns owner.
3. Selects initial subscription or trial.
4. Configures required business details.
5. Sends owner invitation.
6. Owner sets password.
7. Owner completes onboarding.
8. Business publishes booking page.

Both onboarding methods must use the same tenant creation services and database structure.

---

26. Recommended Development Phases

Do not attempt to implement every module simultaneously.

Build in controlled phases.

Phase 1 — Project foundation

- Composer setup.
- Application bootstrap.
- Environment configuration.
- PDO database connection.
- Router.
- Request and response.
- Twig setup.
- Logging.
- Exception handling.
- Database migration system.
- Tailwind build setup.
- Reusable UI foundation.

Deliverable: Running application foundation.

Phase 2 — Authentication and tenancy

- User registration.
- Login/logout.
- Password reset.
- Session security.
- Email verification.
- Invitations.
- MFA (mandatory for platform administrators).
- Tenant registration.
- Tenant resolution.
- Tenant context.
- Tenant middleware.
- Reserved slugs and slug history.
- RBAC with membership-scoped roles and branch scope.
- Platform administrator boundary.
- Audited support access sessions.

Deliverable: Secure multi-tenant authentication.

Phase 3 — Business management

- Business profile.
- Categories.
- Branches.
- Services.
- Service variants.
- Staff.
- Staff schedules.
- Customers.
- Resources.
- Business hours.
- Schedule overrides and blocked periods.
- Booking policies.

Deliverable: Complete business configuration.

Phase 4 — Booking engine

- Availability engine.
- Calendar.
- Booking holds.
- Booking transactions.
- Idempotency.
- Appointment lifecycle and state machine.
- Appointment items.
- Resource allocation.
- Staff assignment.
- Booking references and management tokens.
- Rescheduling.
- Cancellation.
- Staff bookings, walk-ins and overrides.
- Manually recorded payments and deposit fields.
- Concurrency protection and integrity check.

Phase 4 is done only when the full concurrency matrix in "booking-engine.md" §20 passes.

Deliverable: Reliable end-to-end appointment booking.

Phase 5 — Tenant dashboard

- Dashboard statistics.
- Appointment management.
- Calendar management.
- Staff management.
- Customer management.
- Service management.
- Branch management.
- Reports.

Deliverable: Functional business workspace.

Phase 6 — Customer booking pages

- Public business page.
- Service selection.
- Branch selection.
- Staff selection.
- Date selection.
- Time-slot selection.
- Customer information.
- Booking confirmation.
- Booking management.

Deliverable: Complete customer booking experience.

Phase 7 — Theme and customization system

- Branding settings.
- Colour editor.
- Logo and image uploads.
- Predefined templates.
- Section visibility.
- Section ordering.
- Booking-flow preferences.
- Live preview.
- Draft/publish workflow.
- Theme versioning.
- Mobile preview.

Deliverable: Configurable tenant-branded booking pages.

Phase 8 — Notifications

- Notification contracts.
- WhatsApp adapter.
- SMS adapter.
- Email adapter.
- Notification preferences.
- Templates.
- Queue.
- Outbox.
- Delivery logs.
- Reminder scheduler.
- Fallback policies.

Deliverable: Reliable communication system.

Phase 9 — Integrations

- Booking widget.
- Public API.
- Private API.
- API credentials.
- Webhooks.
- Domain verification.

Deliverable: External booking access.

Phase 10 — SaaS management

- Plans.
- Subscriptions.
- Entitlements.
- Usage tracking.
- Billing records.
- Platform administration.

Deliverable: Commercial SaaS functionality.

Phase 11 — Production hardening

- Security testing.
- Concurrency testing.
- Performance testing.
- Data-protection review against the Kenya Data Protection Act, 2019, including consent wording, retention periods and erasure/anonymisation (`decisions.md` D-012).
- Backup and restore.
- Monitoring.
- Logging.
- Error handling.
- Deployment automation.

Deliverable: Production-ready platform.

---

27. MVP Scope

The first public release should focus on a reliable booking workflow.

Tenant MVP

- Registration and onboarding.
- Business profile.
- Service management.
- Staff management.
- Branch management.
- Working hours.
- Customer management.
- Appointment calendar.
- Appointment management.
- Public booking page.
- Basic appearance customization.
- WhatsApp/SMS/email configuration.
- Dashboard statistics.

Customer MVP

- Business landing page.
- Service browsing.
- Branch selection where applicable.
- Staff selection where applicable.
- Date and time selection.
- Guest booking.
- Confirmation.
- Secure booking lookup.
- Cancellation and rescheduling requests.

Platform admin MVP

- Tenant management.
- Tenant activation/suspension.
- Owner management.
- Basic plan management.
- Audit logs.
- Platform configuration.

Defer until later

- Advanced analytics.
- Complex staff commissions.
- Advanced marketing automation.
- Loyalty programmes.
- Gift cards.
- Advanced custom-domain features.
- Extensive white-label customization.
- Multi-currency payment processing.
- Complex accounting integrations.

Do not compromise the core booking engine to add optional features early.

---

28. Environment Configuration

Example ".env.example":

APP_NAME=AlbaBook
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

APP_KEY=

# The application, PHP and the database session always run in UTC.
# This is only the default timezone assigned to new tenants.
DEFAULT_TENANT_TIMEZONE=Africa/Nairobi
DEFAULT_TENANT_CURRENCY=KES

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=albabook
DB_USERNAME=root
DB_PASSWORD=

SESSION_SECURE_COOKIE=false

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=AlbaBook

WHATSAPP_APP_ID=
WHATSAPP_APP_SECRET=

SMS_PROVIDER=
SMS_API_KEY=

ENCRYPTION_KEY=

LOG_LEVEL=debug

Keep real secrets outside version control.

Validate required configuration during application startup.

Do not expose ".env" files through the web server.

---

29. Deployment Architecture

Recommended initial deployment:

Internet
   |
   v
Nginx / Apache
   |
   v
PHP-FPM
   |
   v
AlbaBook Application
   |
   +---- MySQL
   |
   +---- Storage
   |
   +---- External APIs
   |
   +---- Background Workers
   |
   +---- Scheduler / Cron

Use:

- HTTPS.
- Supported PHP runtime.
- MySQL 8+.
- Nginx or Apache.
- PHP-FPM.
- CLI background workers.
- Cron scheduler.
- Encrypted database backups.
- Tested restore procedures.
- Error monitoring.
- Log rotation.

The web server document root must point to "public/".

Never expose application source, configuration files, logs or private uploads through the public document root.

---

30. Non-Negotiable Development Rules

The following rules apply throughout development.

1. Do not hardcode tenant IDs.
2. Do not hardcode business categories.
3. Do not hardcode services.
4. Do not hardcode prices.
5. Do not hardcode opening hours.
6. Do not hardcode staff.
7. Do not hardcode branch information.
8. Do not hardcode tenant branding.
9. Do not hardcode booking policies.
10. Do not hardcode notification channels.
11. Do not hardcode provider credentials.
12. Do not hardcode subscription features.
13. Do not hardcode theme layouts into business logic.
14. Do not hardcode currency or timezone assumptions.
15. Do not use browser-only validation as a security boundary.
16. Do not place business logic in Twig templates.
17. Do not put SQL queries in controllers.
18. Do not duplicate reusable functionality across modules.
19. Do not bypass authorization in API endpoints.
20. Do not trust submitted tenant IDs.
21. Do not perform external HTTP calls inside booking transactions.
22. Do not allow a booking to bypass availability validation.
23. Do not expose customer information through public endpoints.
24. Do not introduce microservices without demonstrated need.
25. Do not add dependencies without a clear technical justification.
26. Do not write to appointment, hold or allocation tables outside "BookingService".
27. Do not treat a booking reference as proof of access.
28. Do not store money as floats.
29. Do not write personal data (phone numbers, emails, tokens) to application logs unmasked.
30. Do not leave two documents giving different answers; record the decision in "decisions.md".

Use platform defaults where no tenant configuration exists.

Allow tenant overrides only where the feature and security policy permit them.

---

31. Definition of Done

A feature is not complete merely because its interface works.

Each feature must have:

- Database migration.
- Server-side validation.
- Authorization.
- Tenant isolation.
- Business logic in the correct service.
- Repository implementation.
- Error handling.
- Audit logging where appropriate.
- Responsive UI.
- Accessible interactions.
- Unit tests.
- Integration tests where necessary.
- Security tests where relevant.
- Documentation.

A booking feature is complete only when concurrent requests cannot create conflicting appointments.

A customization feature is complete only when draft changes can be previewed, validated, published and rolled back safely.

A notification feature is complete only when retries, failures, delivery status and duplicate prevention are handled.

A tenant management feature is complete only when cross-tenant access is prevented and tested.

---

32. Final Product Direction

AlbaBook should be developed as a configurable business platform, not a collection of hardcoded salon pages.

The platform should provide:

For tenants

- A complete business management workspace.
- Calendar and appointment management.
- Staff, branches and resources.
- Customer management.
- Notification management.
- Customizable customer booking pages.
- Integrations and reporting.

For customers

- Business-branded booking pages.
- Simple service discovery.
- Clear availability.
- Guest booking.
- Secure booking management.
- Reliable confirmations and reminders.

For AlbaTech Solutions

- Centralized platform administration.
- Multi-tenant management.
- Subscription management.
- Usage monitoring.
- Integration management.
- Platform security and audit controls.

The core architectural objective is to maintain one shared booking engine and application while allowing each tenant to configure its own business operations, branding, customer experience, communication preferences and integrations.

Build a modular, configurable, secure and maintainable SaaS platform. Prioritize correctness of scheduling, tenant isolation and booking reliability before adding advanced features.