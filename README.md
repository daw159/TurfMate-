<div align="center">

<!-- Animated header images are rendered by third-party SVG services; the README still works if they are unavailable. -->
<img
  src="https://capsule-render.vercel.app/api?type=waving&amp;height=210&amp;color=0:0F5132,50:198754,100:65C18C&amp;text=TurfMate&amp;fontSize=65&amp;fontColor=ffffff&amp;animation=fadeIn&amp;section=header"
  alt="TurfMate"
  width="100%"
/>

<a href="https://git.io/typing-svg"><img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=19&pause=1200&color=198754&center=true&vCenter=true&width=760&lines=Find+a+Turf.+Book+a+Slot.+Play+Your+Game.;One+Platform.+Four+Roles.+Connected+Workflows.;Built+with+PHP+%7C+MySQL+%7C+Apache+%7C+Ubuntu" alt="Animated TurfMate tagline" /></a>

<p><strong>Your Game. Your Time. Your Turf.</strong></p>

<p>
  <a href="#-quick-start"><img src="https://img.shields.io/badge/Get_Started-198754?style=for-the-badge&logo=rocket&logoColor=white" alt="Get started" /></a>
  <a href="#-features-by-role"><img src="https://img.shields.io/badge/Explore_Features-14532D?style=for-the-badge&logo=readme&logoColor=white" alt="Explore features" /></a>
  <a href="#-how-turfmate-works"><img src="https://img.shields.io/badge/View_Workflow-1C7C54?style=for-the-badge&logo=mermaid&logoColor=white" alt="View workflows" /></a>
</p>

![PHP](https://img.shields.io/badge/PHP-Backend-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=flat-square&logo=mysql&logoColor=white)
![Apache](https://img.shields.io/badge/Apache-Web_Server-D22128?style=flat-square&logo=apache&logoColor=white)
![Ubuntu](https://img.shields.io/badge/Ubuntu-Linux-E95420?style=flat-square&logo=ubuntu&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-Frontend-F7DF1E?style=flat-square&logo=javascript&logoColor=black)
![Git](https://img.shields.io/badge/Git-Version_Control-F05032?style=flat-square&logo=git&logoColor=white)

**A full-stack Web Technologies project** connecting turf discovery, reservations, facility operations, and platform administration in one LAMP-based application.

[Overview](#-overview) Â· [Features](#-features-by-role) Â· [Demo flow](#-end-to-end-demo-walkthrough) Â· [Database](#-database-design) Â· [Installation](#-quick-start) Â· [Security](#-security-and-data-privacy)

</div>

---

## ðŸŒŸ Overview

**TurfMate** is a role-based online turf booking and sports facility management system built with **PHP, MySQL, Apache, HTML, CSS, and JavaScript**. Instead of managing bookings through calls, scattered messages, or handwritten records, it brings customers and venue teams into one connected system.

- **Customers** discover venues, check availability, receive price quotations, reserve time slots, and track their bookings.
- **Owners** manage facilities, turfs, pricing, bookings, employees, inventory, maintenance, expenses, and business reports.
- **Staff** handle daily bookings and facility operations, limited to the venues assigned to them.
- **Super Admins** oversee users, owners, facility approvals, and platform-level reporting.

**Supported sports:** cricket, football, padel, and extensible sport categories.

> [!NOTE]
> TurfMate is currently designed for an **academic/local deployment**. Payment and financial modules record transactions and operating figures; they do **not** claim to process real payments through a live gateway.

### The problem we solve

| Without TurfMate | With TurfMate |
|:--|:--|
| Customers call or message venues to ask for availability | Customers browse turfs and inspect bookable time slots |
| Booking requests are scattered between phone and chat | Centralized booking records and status tracking |
| Double-bookings are difficult to prevent | Availability checks and conflict detection |
| Owners track costs, equipment, and payments separately | Inventory, expenses, maintenance, and reports in one panel |
| Staff may have unclear access to multiple venues | Facility-specific staff assignments and role permissions |
| New venues may be published without review | Super Admin facility approval workflow |

---

## ðŸ§­ Explore the Project

<details open>
<summary><strong>ðŸ“š Jump to a section</strong></summary>

- [Features by role](#-features-by-role)
- [How TurfMate works](#-how-turfmate-works)
- [End-to-end demo walkthrough](#-end-to-end-demo-walkthrough)
- [Screenshots and video](#-screenshots-and-video)
- [Technology stack](#-technology-stack)
- [System architecture](#-system-architecture)
- [Project folder structure](#-project-folder-structure)
- [Database design](#-database-design)
- [Quick start](#-quick-start)
- [Security and data privacy](#-security-and-data-privacy)
- [Testing and verification](#-testing-and-verification)
- [Git collaboration](#-git-collaboration)
- [Roadmap](#-roadmap)

</details>

## âœ¨ Features by Role

<table>
<tr>
<td width="50%" valign="top">

### ðŸ‘¤ Customer Portal
**Discover â€¢ Reserve â€¢ Track**

- Customer registration, login, and profile
- Browse sports facilities and turfs
- Turf details, pricing, and availability
- Date/time selection and live quotation
- Multi-hour online bookings
- Overnight online reservations via booking groups
- Booking history and status tracking

**Booking states:** `pending` â†’ `confirmed` â†’ `completed`, with rejection/cancellation when applicable.

</td>
<td width="50%" valign="top">

### ðŸŸï¸ Owner Portal
**Manage â€¢ Analyze â€¢ Grow**

- Overview dashboard and booking metrics
- Facility and turf management
- Base prices and time-based pricing rules
- Booking review, confirmation, rejection, and completion
- Manual bookings for walk-in/phone/WhatsApp requests
- Staff accounts and facility assignments
- Inventory items, stock levels, and stock movements
- Maintenance scheduling and status tracking
- Expenses, payment records, and financial views
- Booking, inventory, and financial reports/exports

</td>
</tr>
<tr>
<td width="50%" valign="top">

### ðŸ‘· Staff Portal
**Operate â€¢ Assist â€¢ Update**

- Dashboard for assigned venues
- Booking lists and authorized status updates
- Walk-in/manual booking creation
- Facility information and pricing visibility
- Inventory operations within assigned facilities
- Maintenance operations for assigned turfs
- Access limited by **staff assignment** and venue status

**Important:** Staff do not receive owner-level financial reports or platform administration rights.

</td>
<td width="50%" valign="top">

### ðŸ›¡ï¸ Super Admin Portal
**Review â€¢ Approve â€¢ Oversee**

- Platform-level dashboard
- Owner and user administration
- Review pending facility submissions
- Approve, reject, or suspend facilities
- Platform reporting and oversight
- Separate administrative permissions

**Important:** Super Admin registration is not a public sign-up option.

</td>
</tr>
</table>

### ðŸ” Access and responsibility matrix

| Capability | Customer | Owner | Staff | Super Admin |
|---|:---:|:---:|:---:|:---:|
| Browse available turfs | âœ… | â€” | Assigned | Oversight |
| Create online reservations | âœ… | â€” | â€” | â€” |
| Manage booking requests | Own history | Own facilities | Assigned active facilities | â€” |
| Make manual bookings | â€” | âœ… | âœ… | â€” |
| Create/edit facilities and turfs | â€” | âœ… | Limited/read-only | Approval |
| Configure pricing | â€” | âœ… | Read-only | â€” |
| Manage staff assignments | â€” | âœ… | â€” | â€” |
| Inventory and maintenance | â€” | âœ… | Assigned facilities | â€” |
| Financial and operational reports | â€” | âœ… | â€” | Platform reports |
| Approve facilities / administer users | â€” | â€” | â€” | âœ… |

*Access is enforced server-side, not just by hiding navigation links.*

---

## ðŸ”„ How TurfMate Works

### 1. The complete booking lifecycle

```mermaid
flowchart TD
    A[Customer browses approved, active turfs] --> B[Select date and time]
    B --> C[Check availability and calculate price]
    C --> D[Submit booking request]
    D --> E[Pending]
    E -->|Owner or authorized staff confirms| F[Confirmed]
    E -->|Request rejected| G[Rejected]
    F -->|Game completed| H[Completed]
    F -->|Booking cancelled| I[Cancelled]
    F --> J[Customer sees updated booking status]
    H --> J
    I --> J
    G --> J
    classDef active fill:#DCFCE7,stroke:#15803D,color:#14532D
    classDef warn fill:#FEF3C7,stroke:#D97706,color:#78350F
    classDef stop fill:#FEE2E2,stroke:#DC2626,color:#991B1B
    class F,H,J active
    class E warn
    class G,I stop
```

The booking engine validates the selected period, calculates pricing, and checks for overlapping reservations. Online reservations crossing midnight can use a logical booking group linked to multiple dated booking rows. **A group and its underlying bookings must maintain consistent statuses.**

### 2. Facility approval and visibility

```mermaid
flowchart LR
    A[Owner submits facility] --> B[Pending review]
    B --> C{Super Admin decision}
    C -->|Approve| D[Approved]
    C -->|Reject| E[Rejected]
    D --> F{Active and not deleted?}
    F -->|Yes| G[Eligible for public booking]
    F -->|No| H[Unavailable to new bookings]
    classDef green fill:#DCFCE7,stroke:#15803D,color:#14532D
    classDef red fill:#FEE2E2,stroke:#DC2626,color:#991B1B
    class D,G green
    class E,H red
```

Approval and operational status are separate checks. Owners may retain historical booking visibility when a venue is deactivated or soft-deleted; staff operational views exclude unavailable facilities.

### 3. Inventory and financial operations

```mermaid
flowchart TD
    A[Facility inventory] --> B[Purchase: stock increases]
    A --> C[Usage: stock decreases]
    A --> D[Adjustment: stock reconciled]
    B --> E[Current quantity and audit history]
    C --> E
    D --> E
    F[Bookings] --> G[Booking value]
    F --> H[Recorded payments]
    I[Operating expenses] --> J[Financial reporting]
    G --> J
    H --> J
```

**Booked revenue, collected payments, and operating expenses are distinct measurements.** Records are used to build reports, not to claim that money was transferred through an online payment provider.

---

## ðŸŽ¬ End-to-End Demo Walkthrough

A suggested sequence for a university demonstration or narrated video:

| Scene | Role | What to demonstrate |
|:--:|:--|:--|
| 01 | **Super Admin** | Review and approve a facility submitted by an Owner |
| 02 | **Owner** | Configure a turf, hourly price, and peak-time pricing rule |
| 03 | **Customer** | Browse approved turfs, inspect slots, and book a cricket turf |
| 04 | **Customer** | See the new reservation marked **Pending** |
| 05 | **Owner** | Open the booking and **Confirm** it |
| 06 | **Customer** | Refresh booking history and see **Confirmed** |
| 07 | **Staff** | Open the booking in an assigned facility and mark it **Completed** |
| 08 | **Owner** | Review updated booking statistics, inventory, expenses, and reports |

> [!TIP]
> For the clearest demo, use separate demo accounts for each role, perform a **fresh booking**, and show its status moving between the different portals. Avoid showing passwords, personal phone numbers, or database credentials in recordings.

## ðŸ“¸ Screenshots and Video

Screenshots and a demonstration video can be added without changing application code.

<!-- Add actual files before uncommenting these lines:
![TurfMate homepage](docs/screenshots/homepage.png)
![Customer booking](docs/screenshots/customer-booking.png)
![Owner dashboard](docs/screenshots/owner-dashboard.png)
![Staff dashboard](docs/screenshots/staff-dashboard.png)
![Super Admin dashboard](docs/screenshots/super-admin-dashboard.png)

[â–¶ Watch the TurfMate walkthrough](https://YOUR_DEMO_VIDEO_URL)
-->

| Showcase | What to capture |
|---|---|
| ðŸ  Home | Landing page and sports discovery |
| ðŸ Customer | Turf availability, quotation, and booking history |
| ðŸ“Š Owner | Dashboard, staff management, inventory, finance, and reports |
| ðŸ› ï¸ Staff | Assigned-facility bookings and daily operations |
| ðŸ›¡ï¸ Super Admin | Facility approval and platform overview |

**Suggested location for image files:** `docs/screenshots/`. Once added, uncomment the Markdown images above and replace the demo-video placeholder with your actual link. No screenshots or video links have been invented here.

---

## ðŸ§° Technology Stack

<div align="center">

| Layer | Technology | Purpose |
|---|---|---|
| Client | **HTML5, CSS3, JavaScript** | UI, forms, responsive pages |
| Server-side | **PHP** | Business logic, authentication, bookings, reports |
| Web server | **Apache HTTP Server** | Local web hosting and PHP execution |
| Data | **MySQL** | Relational data, constraints, transactions |
| Database access | **PDO** | Parameterized SQL operations |
| Development | **Ubuntu / LAMP** | Linux-based development environment |
| Collaboration | **Git + GitHub** | Version history and teamwork |
| Local VM (optional) | **VirtualBox** | Running Ubuntu on a host computer |

</div>

## ðŸ—ï¸ System Architecture

```mermaid
flowchart TB
    U[Browser: Customer / Owner / Staff / Super Admin]
    U --> A[Apache Web Server]
    A --> P[PHP Application]
    P --> AU[Authentication and Role Guards]
    P --> BK[Booking and Pricing Logic]
    P --> OP[Inventory / Maintenance / Expenses / Reports]
    AU --> DB[(MySQL Database)]
    BK --> DB
    OP --> DB
    P --> UI[HTML + CSS + JavaScript Response]
    UI --> U
```

**Architecture:** A traditional server-rendered PHP application using shared database access, role-specific modules, and session-based authorization. It does not require a separate frontend framework or microservice stack.

---

## ðŸ—‚ï¸ Project Folder Structure

<details open>
<summary><strong>ðŸ“ Expand the main source tree</strong></summary>

```text
TurfMate/
â”œâ”€â”€ index.php                   # Public entry / homepage
â”œâ”€â”€ auth/                       # Login, registration, logout
â”‚   â”œâ”€â”€ login.php
â”‚   â”œâ”€â”€ register.php
â”‚   â””â”€â”€ logout.php
â”œâ”€â”€ customer/                   # Customer browsing and booking
â”‚   â”œâ”€â”€ dashboard.php
â”‚   â”œâ”€â”€ turfs.php
â”‚   â”œâ”€â”€ turf-details.php
â”‚   â”œâ”€â”€ turf-availability.php
â”‚   â”œâ”€â”€ booking_engine.php
â”‚   â”œâ”€â”€ booking-quote.php
â”‚   â”œâ”€â”€ my-bookings.php
â”‚   â””â”€â”€ profile.php
â”œâ”€â”€ owner/                      # Venue/business administration
â”‚   â”œâ”€â”€ dashboard.php
â”‚   â”œâ”€â”€ facilities.php
â”‚   â”œâ”€â”€ turfs.php
â”‚   â”œâ”€â”€ pricing_rules.php
â”‚   â”œâ”€â”€ bookings.php
â”‚   â”œâ”€â”€ booking-actions.php
â”‚   â”œâ”€â”€ manual-booking.php
â”‚   â”œâ”€â”€ staff.php
â”‚   â”œâ”€â”€ inventory.php
â”‚   â”œâ”€â”€ inventory-transactions.php
â”‚   â”œâ”€â”€ maintenance.php
â”‚   â”œâ”€â”€ expenses.php
â”‚   â”œâ”€â”€ reports.php
â”‚   â”œâ”€â”€ reports-bookings.php
â”‚   â”œâ”€â”€ reports-inventory.php
â”‚   â”œâ”€â”€ reports-financial.php
â”‚   â””â”€â”€ reports-export.php
â”œâ”€â”€ staff/                      # Assigned facility operations
â”‚   â”œâ”€â”€ dashboard.php
â”‚   â”œâ”€â”€ bookings.php
â”‚   â”œâ”€â”€ manual-booking.php
â”‚   â”œâ”€â”€ facilities.php
â”‚   â”œâ”€â”€ inventory.php
â”‚   â””â”€â”€ maintenance.php
â”œâ”€â”€ super-admin/                # Platform-level administration
â”‚   â”œâ”€â”€ dashboard.php
â”‚   â”œâ”€â”€ facilities.php
â”‚   â”œâ”€â”€ owners.php
â”‚   â”œâ”€â”€ users.php
â”‚   â””â”€â”€ reports.php
â”œâ”€â”€ includes/                   # Auth and shared functions
â”‚   â”œâ”€â”€ auth.php
â”‚   â”œâ”€â”€ db.php
â”‚   â””â”€â”€ functions.php
â”œâ”€â”€ config/
â”‚   â””â”€â”€ database.php            # Loads local .env and creates PDO
â”œâ”€â”€ database/
â”‚   â”œâ”€â”€ schema.sql              # Database schema source
â”‚   â””â”€â”€ migrations/             # Incremental SQL migrations
â”œâ”€â”€ assets/
â”‚   â”œâ”€â”€ css/                    # Page / module styling
â”‚   â”œâ”€â”€ images/                 # Sports and site imagery
â”‚   â””â”€â”€ js/                     # Front-end scripts
â”œâ”€â”€ README.md
â””â”€â”€ .gitignore
```

</details>

<details>
<summary><strong>ðŸ§© What the most important files do</strong></summary>

| File | Responsibility |
|---|---|
| `config/database.php` | Reads local database settings and returns a PDO connection |
| `includes/auth.php` | Session handling and role access checks |
| `customer/booking_engine.php` | Validates bookings, quotes prices, and checks availability |
| `customer/my-bookings.php` | Displays customer booking history and status |
| `owner/booking-actions.php` | Handles allowed owner-side status transitions |
| `owner/_booking_helpers.php` | Shared owner booking lookups and locking helpers |
| `staff/_init.php` | Staff session and facility assignment checks |
| `super-admin/facilities.php` | Facility approval oversight |
| `database/schema.sql` | Schema definition used by the project |

</details>

---

## ðŸ—„ï¸ Database Design

TurfMate uses a relational schema that connects users, venues, reservations, payment records, operations, and reporting.

| Table | Purpose |
|---|---|
| `users` | Customer, Owner, Staff, and Super Admin accounts |
| `facilities` | Venue ownership, contact information, operational and approval status |
| `turfs` | Sports grounds, facilities, capacity, and base prices |
| `pricing_rules` | Day/time-based turf pricing |
| `bookings` | Individual reservation records and their status |
| `tm_booking_groups` | Logical online reservations, including multi-segment bookings |
| `tm_booking_group_items` | Links a booking group to underlying booking rows |
| `customer_booking_requests` | Legacy/additional customer request records |
| `payments` | Recorded booking payments and refunds |
| `staff_assignments` | Facility-specific staff access |
| `inventory_items` | Stock items and low-stock thresholds |
| `inventory_transactions` | Purchases, usage, and adjustments |
| `maintenance_records` | Turf repair and maintenance schedules |
| `expenses` | Facility operating costs |

### Entity relationships at a glance

```mermaid
erDiagram
    USERS ||--o{ FACILITIES : owns
    FACILITIES ||--o{ TURFS : contains
    TURFS ||--o{ PRICING_RULES : has
    TURFS ||--o{ BOOKINGS : receives
    BOOKINGS ||--o{ PAYMENTS : records
    USERS ||--o{ STAFF_ASSIGNMENTS : works_at
    FACILITIES ||--o{ STAFF_ASSIGNMENTS : employs
    USERS ||--o{ TM_BOOKING_GROUPS : requests
    TM_BOOKING_GROUPS ||--o{ TM_BOOKING_GROUP_ITEMS : contains
    BOOKINGS ||--o| TM_BOOKING_GROUP_ITEMS : linked_to
    FACILITIES ||--o{ INVENTORY_ITEMS : stocks
    INVENTORY_ITEMS ||--o{ INVENTORY_TRANSACTIONS : logs
    TURFS ||--o{ MAINTENANCE_RECORDS : requires
    FACILITIES ||--o{ EXPENSES : incurs
```

> [!IMPORTANT]
> The ERD is **conceptual**, not a complete column-by-column schema. The authoritative table and foreign-key definitions are in the SQL schema and migrations. The unused `operating_hours` table is intended to be removed; verify that your actual database and `database/schema.sql` are consistent before considering cleanup finished.

---

## ðŸš€ Quick Start

### Prerequisites

- Ubuntu (or another Apache + PHP + MySQL environment)
- Apache, PHP, MySQL and PHP's `pdo_mysql` extension
- Git
- A **private**, team-approved database schema or SQL dump

### 1. Install the LAMP dependencies (Ubuntu)

```bash
sudo apt update
sudo apt install apache2 mysql-server php libapache2-mod-php php-mysql php-mbstring git
sudo systemctl start apache2
sudo systemctl start mysql
```

### 2. Clone TurfMate

```bash
cd /var/www/html
git clone https://github.com/daw159/TurfMate-.git turfmate
cd turfmate
```

If you already have the repository, update it with `git pull --ff-only origin main` after checking `git status`.

### 3. Create and populate your local database

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS turfmate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

Import a SQL dump received **privately** from the team (example filename only):

```bash
sudo mysql turfmate < ~/Downloads/turfmate_final_database.sql
```

**Caution:** An imported dump may contain statements that overwrite existing tables. Back up any existing `turfmate` database before importing, and do not publish team dumps to GitHub.

If starting from `database/schema.sql` instead, check whether additional migrations and seed data are required. A schema-only setup may not provide the Super Admin account or sample records.

### 4. Configure local database credentials

Create a `.env` file in the project root:

```dotenv
DB_HOST=localhost
DB_NAME=turfmate
DB_USER=your_local_mysql_user
DB_PASS=your_local_mysql_password
```

Create/grant a local MySQL user with appropriate privileges for the application. The example credentials above are **placeholders**. `.env` must remain private and out of Git.

### 5. Launch the website

Open the application at:

**`http://localhost/turfmate/`**

Direct login URL: **`http://localhost/turfmate/auth/login.php`**

Public registration supports Customer and Owner accounts. Staff access is provisioned by an Owner; Super Admin access should be provisioned privately.

<details>
<summary><strong>ðŸ› ï¸ Troubleshooting installation</strong></summary>

```bash
# Verify services
sudo systemctl status apache2
sudo systemctl status mysql

# Check PHP extensions
php -m | grep -Ei 'PDO|pdo_mysql|mbstring'

# Check syntax across application modules
find auth customer owner staff super-admin -name '*.php' -print0 | xargs -0 -n1 php -l

# Inspect server errors
sudo tail -n 50 /var/log/apache2/error.log
```

If login redirects fail, verify the app directory is `/turfmate`, database connection settings are correct, and Apache is serving the expected checkout.

</details>

---

## ðŸ”’ Security and Data Privacy

**Existing design foundations:**

- Session-based authentication and role checks
- Password hashing and verification
- Server-side checks for Owner ownership and Staff assignments
- PDO prepared queries for database operations
- CSRF protection for relevant state-changing requests
- Facility approval and operational-status restrictions
- Transactions/locking in booking workflows to reduce conflicts

**Before a public deployment**, independently review CSRF/authorization on every action, HTTPS and cookie settings, error handling, upload security, database grants, and backup procedures. A local academic demo should not be interpreted as a security audit.

> [!CAUTION]
> Never commit `.env`, `.env.test`, real database exports, account credentials, customer contact data, or private recordings. If sharing demo data, anonymize personal information and share through an appropriate private channel.

### Booking and facility rules worth preserving

1. New bookings should target **approved, active, non-deleted** facilities and eligible turfs.
2. Staff access must be checked against **active assignments**, not merely staff role.
3. Owners should retain historical records for deactivated/soft-deleted facilities.
4. Booking status updates should keep `bookings` and associated `tm_booking_groups` consistent.
5. Multi-segment groups require explicit group-level status handling; copying one segment's status to the entire group may be incorrect.

---

## ðŸ§ª Testing and Verification

Testing should cover both page access **and** the data changing correctly across roles.

| Area | Example verification |
|---|---|
| Authentication | Correct logins; incorrect passwords and cross-role access rejected |
| Facility approval | Unapproved venues excluded from customer discovery |
| Staff isolation | Employee only accesses assigned eligible facilities |
| Booking conflicts | Overlapping confirmed slots cannot be booked |
| Booking lifecycle | Pending â†’ Confirmed â†’ Completed correctly reflected across portals |
| Cancellation/rejection | Correct allowed transitions and status visibility |
| Overnight bookings | Multi-day booking segments remain consistent |
| Inventory | Purchases, usage, adjustments and stock validation |
| Financial records | Payments and expenses correctly represented in reports |
| Historical data | Owner retains past bookings for inactive facilities |

**Previously observed local test run:** 114 checks passed, with **2 automated HTTP failures** for Owner reporting endpoints. Those report pages were also checked manually and opened, but manual success does not prove the automated failures were fixed. Subsequent changes to booking actions should be revalidated independently. Do not present this historical result as a fully passing final test suite.

If you retain the local automated test tools, their usage may be documented in `README-TESTING.txt`. Test scripts and `.env.test` may be excluded from the public repository; they are not needed to run the normal website.

---

## ðŸ¤ Git Collaboration

This is a shared source-code repository. **Git synchronizes files, not MySQL records.** Each teammate keeps their own database configuration and must import/apply database changes separately.

```bash
# Inspect local changes
git status

# Pull teammates' work when safe
git pull --ff-only origin main

# Commit a specific documentation update
git add README.md
git commit -m "docs: redesign TurfMate README"

# Share changes
git push origin main
```

- Do not commit local database dumps or credentials.
- Add schema changes as SQL migrations and communicate their application order.
- Avoid force-pushing shared `main`.
- Use Git history to review contributions, not as a sole measure of teamwork.

**Repository:** [github.com/daw159/TurfMate-](https://github.com/daw159/TurfMate-)

---

## ðŸ›£ï¸ Roadmap

Potential future enhancements (**not claimed as implemented**):

- [ ] Email/WhatsApp booking notifications and reminders
- [ ] Online payment gateway integration
- [ ] Stronger automated end-to-end checks and CI
- [ ] Audit logging for booking, inventory, and financial actions
- [ ] Better analytics and configurable reporting
- [ ] Production deployment, HTTPS, monitoring and scheduled backups
- [ ] Additional accessibility and mobile UX improvements

## ðŸŽ“ Academic Project

| | |
|---|---|
| **Project** | TurfMate â€” Online Turf Booking & Sports Facility Management System |
| **Course context** | Web Technologies |
| **Approach** | LAMP stack, role-based access, relational database |
| **Development environment** | Ubuntu Linux (including VirtualBox-based local development) |
| **Source code** | [TurfMate on GitHub](https://github.com/daw159/TurfMate-) |
| **License** | Not specified; do not assume an open-source license without a `LICENSE` file |

---

<div align="center">

### ðŸ TurfMate

**Less paperwork. Smarter bookings. Better turf management.**

<img src="https://capsule-render.vercel.app/api?type=waving&color=0:65C18C,50:198754,100:0F5132&height=110&section=footer" alt="TurfMate footer" width="100%" />

</div>
