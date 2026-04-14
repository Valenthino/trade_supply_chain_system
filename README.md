# Trade & Supply Chain Management System

This is a web-based **trading and inventory management system** for a cashew commodity negoce business in Côte d'Ivoire.  
It helps manage contracts, stock, warehouse movements, and basic logistics across the supply chain.

The project is built with **PHP** and **MySQL** and is designed to run on standard shared hosting (e.g. Hostinger).

---

## Features

- Contract management for purchases and sales
- Inventory tracking (warehouses, lots, stock movements)
- Basic logistics & documentation
- User authentication and role-based access (if implemented)
- Operational reports and dashboards

*(Adjust this list to match your actual code.)*

---

## Tech Stack

- PHP (version X.Y – update this to your real version)
- MySQL / MariaDB
- Web server: Apache or Nginx (Hostinger shared hosting)
- Git + GitHub for version control and collaboration

---

## Environments

- **Production (live)**  
  - Branch: `main`  
  - Host: https://app.cooplagloire.com/  

- **Staging (test)**  
  - Branch: `staging`  
  - Host: https://salmon-lion-321390.hostingersite.com/  

All development happens on short-lived `feature-*` branches, which are merged into `staging` first, tested on the staging site, and then promoted to `main` for production.

---

## Getting Started (Local Development)

### 1. Prerequisites

You need:

- PHP (version X.Y or higher)
- MySQL or MariaDB
- Git
- A local web server stack:
  - XAMPP / WAMP / MAMP **or**
  - PHP built-in server

### 2. Clone the Repository

```bash
git clone https://github.com/CommoditySystem/Trade-Supply-Chain-Management-System.git
cd Trade-Supply-Chain-Management-System

```markdown
### 3. Configure the Database

Create a new MySQL database locally (e.g. `trade_supply_chain_local`).

Import the initial schema:

If there is a `/database` or `/sql` folder with a `.sql` file, import it with phpMyAdmin or:

```bash
mysql -u username -p database_name < path/to/database.sql
```

Otherwise, run the initial setup script as described in the project (for example `setup.php` on your local environment).

Update the DB configuration file (for example `config.php` or `.env`):

```php
// Example config.php structure – adjust to your real file
$db_host = 'localhost';
$db_name = 'trade_supply_chain_local';
$db_user = 'root';
$db_pass = '';
```

---

### 4. Run the Application Locally

Depending on your setup:

- If using a local stack (XAMPP/WAMP/MAMP), put the project folder in `htdocs/www` and open in your browser:

  ```
  http://localhost/Trade-Supply-Chain-Management-System
  ```

- Or use PHP built-in server (if applicable):

```bash
php -S localhost:8000 -t public
```

Then visit:

```
http://localhost:8000
```

*(Update this section with the real entry point: `index.php`, `public/index.php`, etc.)*

---

## Deployment (Hostinger / Shared Hosting)

> **Important:** Never run destructive setup scripts on the production database.  
> Always back up the DB before deploying.

### 1. Production Deployment

Production is deployed from the `main` branch.

Deploy options:

- Git integration/Webhook from GitHub to Hostinger, or  
- Manual upload (FTP/SFTP) of the project files from the `main` branch.

Basic process:

1. Backup the production database via phpMyAdmin.  
2. Pull latest `main` or upload files.  
3. If DB structure changed, run only **additive** migrations/setup steps (no drop/truncate).  
4. Test core flows on the live site.

### 2. Staging Deployment

Staging is deployed from the `staging` branch to a separate folder and database.

You can:

- Use another Hostinger site/subdomain (e.g. `staging.example.com`).  
- Connect that folder to the `staging` branch via Git or manual upload.

Use staging to test new features and bug fixes with a **copy** of production-like data before pushing changes to `main`.



## Database & `setup.php` Safety

To avoid data loss:

- `setup.php` or any install script must **never** drop or recreate tables in production.

They should only:

- Create tables if they do not exist.  
- Add new columns if they do not exist.  
- Insert default data only when needed.

All schema changes must be:

- Tested on the staging database first.  
- Used in a way that is safe to run multiple times (idempotent).

Always back up the database before deploying to production.

---

## Git Workflow Summary

- `main`  
  Production code (what runs on live site).

- `staging`  
  Pre-production code (tested on staging environment).

- `feature-*` branches  
  Temporary branches for each bug fix or feature.

**Basic flow:**

1. Create `feature-*` branch from `staging`.  
2. Code and commit changes.  
3. Open Pull Request from `feature-*` to `staging`.  
4. Deploy `staging` to staging environment and test.  
5. When OK, open Pull Request from `staging` to `main`.  
6. Deploy `main` to production environment.

See `CONTRIBUTING.md` for detailed rules.
```
