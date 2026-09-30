# Billflow

Billflow is an invoice and collection application built with PHP 8.3 and MySQL.

## Modules

The workspace is divided into Invoice, Clients, Services, Payment Methods, and Settings modules. Each module has its own overview and sidebar. Invoice controls include invoices, collections, recurring billing, and money receipts. Client controls include profiles and the searchable client ledger.

## Live server deployment

The `.env` file contains database and SMTP credentials and is excluded from Git. Copy `.env.example` to `.env` in the project root, then enter the MySQL credentials supplied by the hosting provider:

```dotenv
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=hosting_database_name
DB_USERNAME=hosting_database_user
DB_PASSWORD=hosting_database_password
```

Run these commands when SSH or terminal access is available:

```bash
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

Without terminal access, use cPanel File Manager to copy `.env.example` to `.env` and upload the complete local `vendor/` directory. Enable the `pdo_mysql`, `mbstring`, `openssl`, `sodium`, and `gd` PHP extensions. Create the MySQL database and user first, then grant that user the required database permissions. The application creates its tables on the first successful request.

After deployment, verify that `.env`, `vendor/autoload.php`, and `assets/uploads/` exist. If the database connection fails, the application displays setup guidance while writing technical details to the hosting error log.

## Local setup

Open this directory as the site root in Laragon. Copy `.env.example` to `.env`, enter the MySQL host, database, username, and password, and create the configured database before opening the application.

The default Super Admin email is `me@kbashar.com`. The supplied bcrypt hash is stored in the database once. Sign in with the original password represented by that hash, rather than the hash string.

To use PHP's built in server:

```powershell
& 'D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' -S 127.0.0.1:8000 router.php
```

Then open `http://127.0.0.1:8000`.

## SQLite to MySQL migration

Configure the MySQL connection in `.env`, then migrate the records, IDs, and relationships from `storage/invoice.sqlite`:

```powershell
& 'D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' migrate_sqlite_to_mysql.php
```

The script stops if the target database already contains business data. Pass `--fresh` only when you intend to replace that data with the SQLite records. Keep the old SQLite file until the migration completes successfully.

## Recurring invoices

Creating a recurring invoice saves the first invoice immediately and stores the next billing date and item snapshot in its schedule. Due invoices are generated when the application opens. For reliable background generation, run this command once a day with Windows Task Scheduler or an equivalent cron job:

```powershell
& 'D:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe' 'D:\laragon\www\invoice\cron.php'
```

## Client accounts and invoice editing

Each invoice mobile number is normalized and linked to a unique `clients` record. An existing number reuses its client account. The client portal has not been added yet, so the client password remains empty. Administrators currently record collections manually.

`Company Name` is optional and is saved on the client account and as a snapshot on each invoice. Invoice editing can update the billing client, dates, services, custom items, quantities, prices, payment method, and notes. The invoice number and previous payments remain unchanged. The new total cannot be lower than the amount already collected. Editing a recurring invoice changes that invoice only.

## Invoice collections

The Invoice Collections page lists unpaid and partially paid invoices. Select multiple invoices for the same client to allocate one collection from the oldest invoice forward. When the amount clears one invoice, the remaining amount continues as a partial collection on the next selected invoice. Each batch receives a printable money receipt.

An optional partial discount can be recorded with confirmation that it applies to one-time or recurring invoices. Collection plus discount cannot exceed the selected balance.

## Billing cycles and email delivery

Recurring schedules support Monthly, Quarterly, Half Yearly, Yearly, Biennial, Triennial, Quadrennial, and Quinquennial billing. The billing cycle can be changed from the Recurring Billing page.

Manually created invoices remain in the email queue until an administrator clicks Send Mail. Invoices generated automatically from recurring schedules are queued for automatic email delivery.

## Settings and email

Basic Settings manages the site title, slogan, contact details, office address, website, logo, favicon, authorized signature, and PDF header visibility. Uploaded files are stored in `assets/uploads/`; include that directory in backups.

SMTP Settings manages the host, port, encryption, username, password, sender name, and sender email. New and recurring invoice PDFs are emailed automatically when the client has a valid email address. Failed deliveries remain in the database queue, and `cron.php` retries them up to three times. The SMTP encryption key is stored in `storage/smtp.key`; back it up securely with the database.

## Payment methods and PDF view

Payment Methods supports bank, MFS, card, and other channels. Each method can store account details, a mobile number, branch or routing information, payment instructions, and a QR image. Methods can be enabled or disabled. If an invoice has no selected method, all active methods appear in its details and PDF; otherwise only the selected method appears.

PDF View opens a one page A4 invoice in a new tab with payment details and compact QR codes. PDF generation uses mPDF, so a new installation requires `composer install` or a complete uploaded `vendor/` directory.

## Data and security

- Admin authentication uses password hashing and CSRF tokens.
- Money is stored as integer cents, and overpayment is rejected.
- `storage/.htaccess` blocks direct downloads on Apache; `router.php` applies the same protection to the built in server.
- Production deployments should use HTTPS, regular MySQL backups, and a verified payment gateway when online payments are added.
