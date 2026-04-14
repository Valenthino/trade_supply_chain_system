# CommodityFlow

A web-based commodity trading and operations platform built for a cashew business in Cote d'Ivoire.

CommodityFlow centralizes the full operational cycle: buying from suppliers, managing stock and lots, delivering to customers, tracking financing and payments, monitoring profitability, and running logistics with fleet and document controls.

The application is implemented in plain PHP + MySQL and is optimized for shared-hosting deployments.

---

## What This Project Solves

Commodity trading operations often run across disconnected spreadsheets (procurement, inventory, logistics, finance, and account management). This project brings those flows into one system with:

- End-to-end transaction traceability (purchase to delivery to sale)
- Financial visibility (advances, debts, payments, expenses, margins)
- Role-based operational control
- Live dashboards and trend monitoring
- AI-assisted business reporting and document extraction

---

## Core Features

### Operations

- **Purchases:** supplier purchases with quality and pricing metrics
- **Sales:** customer sales with cost breakdown, margin, and net profit
- **Deliveries Out:** outbound deliveries and delivery-item tracking
- **Payments:** incoming/outgoing cash movement tracking by counterparty

### Master Data

- **Supplier Master:** supplier profiles and lifecycle data
- **Customer Master:** customer records and financing references
- **Pricing Master:** customer/supplier pricing agreement management
- **Bank Master:** financial counterparties for debt and financing flows
- **Supplier Ranking:** score snapshots to support financing decisions

### Finance

- **Financing:** supports bank debt, customer advances, and supplier balances
- **Expenses:** categorized expense management
- **Profit Analysis:** Profit & Loss, Cash Flow, and Simulation tabs
- **Financial KPIs:** debt exposure, remaining financed volume, coverage ratios

### Logistics and Inventory

- **Inventory Ledger:** lot-level stock logic and stock movement calculations
- **Fleet & Drivers:** vehicle and driver administration
- **Fleet Paperwork Tracking:** expiry monitoring for logistics documents
- **Bags Log:** operational bag movement/register workflows

### AI and Analytics

- **AI Reports:** generated reports for monthly summary, profit analysis, supplier performance, customer risk, and price trends
- **Receipt/Document AI Reader:** extracts structured data from uploaded images/PDFs
- **Gemini Integration:** configurable AI model and API key through system settings

### Security and Administration

- **Authentication:** secure login flow with session hardening
- **Role-Based Access Control:** menu/module access by role
- **Rate Limiting:** lockout logic for repeated failed logins
- **CSRF Protection:** token validation for sensitive form actions
- **Activity Logs:** user action logging and audit trail
- **Theme/UX Controls:** dark mode + personalized UI preferences
- **Notifications:** unread center + role/user targeted system alerts

---

## Roles Implemented

The system defines these roles:

- `Admin`
- `Manager`
- `Procurement Officer`
- `Sales Officer`
- `Finance Officer`
- `Fleet Manager`
- `Warehouse Clerk`

Each role sees only the modules relevant to their responsibilities.

---

## How the System Works (Business Flow)

Typical flow across modules:

1. Set up reference data (locations, contract types, warehouses, seasons, etc.).
2. Register suppliers/customers and pricing agreements.
3. Record purchases (volume, quality, and procurement cost).
4. Manage inventory and lot balances.
5. Create deliveries to customers.
6. Confirm sales and compute profitability.
7. Track financing commitments (banks/customers/suppliers).
8. Register payments and expenses.
9. Monitor KPIs in dashboard and profit-analysis views.
10. Use AI reports for executive summaries and risk insights.

---

## Tech Stack

- **Backend:** PHP (procedural, multi-page app)
- **Database:** MySQL/MariaDB
- **Frontend:** HTML/CSS/JavaScript + jQuery + SweetAlert2 + Charting components
- **AI:** Google Gemini API (text + vision use cases)
- **Deployment target:** shared hosting (Apache/Nginx compatible)

---

## Project Structure (High-Level)

- `login.php` - authentication entry point
- `dashboard.php` - KPI dashboard and analytics endpoints
- `setup.php` - database bootstrap and schema creation
- `config.php` - environment loading, security helpers, DB connection, common utilities
- `sidebar.php` - role-aware navigation + notifications
- Operational modules: `purchases.php`, `sales.php`, `deliveries.php`, `inventory.php`, `payments.php`, `expenses.php`, `financing.php`
- Master data modules: `suppliers.php`, `customers.php`, `banks.php`, `pricing.php`, `settings-data.php`
- AI modules: `ai-reports.php`, `ai-helper.php`

---

## Database Coverage

`setup.php` provisions major tables including:

- Core security/admin (`users`, `activity_logs`, `login_attempts`, `system_settings`)
- Master data settings (`settings_*` tables)
- Operations (`purchases`, `deliveries`, `delivery_items`, `sales`, `lots`)
- Finance (`payments`, `financing`, `expenses`, `banks`)
- Logistics (`fleet_vehicles`, `fleet_paperworks`, `bags_log`)
- Commercial (`customers`, `suppliers`, pricing agreements, supplier ranking snapshots)
- System support (`notifications`, seasons/currencies, salary payments)

---

## Local Setup

### 1) Prerequisites

- PHP 8.1+ (recommended)
- MySQL/MariaDB
- Apache/Nginx or local stack (XAMPP/WAMP/MAMP)
- Git

### 2) Clone Repository

```bash
git clone https://github.com/Valenthino/trade_supply_chain_system.git
cd trade_supply_chain_system
```

### 3) Configure Environment

Create a local `.env` file from `.env.example` and set database credentials:

```env
DB_HOST=localhost
DB_NAME=your_local_database
DB_USER=your_db_user
DB_PASS=your_db_password
```

### 4) Create Database

Create an empty MySQL database (example: `commodityflow_local`).

### 5) Run Initial Setup

Open in browser:

```text
http://localhost/trade_supply_chain_system/setup.php
```

This creates required tables and seed records.

### 6) Launch App

Use your local web server and open:

```text
http://localhost/trade_supply_chain_system/login.php
```

---

## Environment and Deployment

### Branch Strategy

- `main` -> production
- `staging` -> pre-production testing
- `feature-*` -> short-lived implementation branches

### Live Environments

- Production: <https://app.cooplagloire.com/>
- Staging: <https://staging.cooplagloire.com/>

### Safe Deployment Checklist

1. Back up production database.
2. Deploy latest branch code (`staging` or `main`).
3. Apply only additive schema updates.
4. Validate login, purchases, sales, deliveries, and payment flows.
5. Validate dashboard and key finance KPIs.

---

## Security Notes

- Keep `.env` out of version control.
- Use strong credentials and rotate production secrets regularly.
- Restrict access to `setup.php` on production once initialization is complete.
- Keep role permissions aligned with business responsibilities.

---

## Roadmap Ideas

- Automated recurring jobs for alerts and document expiry checks
- Export-ready reports (PDF/Excel)
- Additional audit trails on critical finance edits
- Deeper forecasting models for seasonality and price risk

---

## Contributing

1. Branch from `staging` into `feature-*`.
2. Open PR into `staging`.
3. Validate on staging environment.
4. Promote tested changes from `staging` to `main`.

---

## License

MIT License

Copyright (c) 2026 Sawadogo Valentin

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
