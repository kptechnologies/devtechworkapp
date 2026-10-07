# DevTech Staff Portal

A self-hosted web app for daily work reports, staff wallets (allowances, budgets, expenses with receipts), funding requests and admin dashboards. It runs on standard cPanel hosting: PHP 8.1+ and MySQL/MariaDB. There's no build step, and no Composer or Node needed on the server.

## Deploy on cPanel (about 10 minutes)

1. **Create the database.** cPanel → *MySQL® Databases*:
   - Create a database, e.g. `cpuser_portal`.
   - Create a user with a strong password.
   - *Add User To Database* → tick **ALL PRIVILEGES**.
2. **Check the PHP version.** cPanel → *Select PHP Version* (or *MultiPHP Manager*) → choose **PHP 8.1 or newer**. Make sure the `pdo_mysql`, `fileinfo`, `gd` and `mbstring` extensions are ticked. They are on most hosts.
3. **Upload.** cPanel → *File Manager*:
   - Open `public_html` (or a subfolder like `public_html/portal`, or a subdomain's folder).
   - Upload `devtech-portal.zip` → *Extract*.
   - Move the files out of the `devtech-portal` folder if you want the app at the folder root.
4. **Run the installer.** Visit `https://yourdomain.com/install.php` (or `/portal/install.php`). Enter the database details and create your admin account.
5. **Delete `install.php`.** It refuses to run once an admin exists, but deleting it is tidier.
6. **Turn on HTTPS.** cPanel → *SSL/TLS Status* → run AutoSSL. Then uncomment the three "force HTTPS" lines at the bottom of `.htaccess`.
7. **Set up email** (optional but recommended):
   - cPanel → *Email Accounts* → create e.g. `portal@yourdomain.com` → *Connect Devices* to see the SMTP host and port.
   - In the portal: **Settings → Email notifications**. Enter the host, port (465 = SSL), username and password, then **Send test email**.

## First-day setup

1. **Settings**: check the schools/locations list, expense categories, payment types and the default daily allowance.
2. **Import history**:
   - **Work reports**: open the Google Sheet → *Form responses 1* tab → File → Download → CSV. Upload it. Staff accounts are created from the email column automatically.
   - **Expense console**: download each tab (payments, expenses, adjustments) as CSV. Upload one at a time, match the columns on the preview screen, and import. Add staff first so names can be matched. Rows with names it can't match confidently are skipped and listed, so you can add or rename the staff member and import again. Re-importing never creates duplicates.
3. **Staff setup**: set a password for each staff member. Imported accounts show "No password", and you can use the wand button to generate one. Tick "Email them their sign-in details" to send the details by email.
4. **Opening balances**: if staff already hold money, use **Send payment → Adjust a balance** with the reason "Opening balance".

## How the wallet works

| Action | Who | Effect on balance |
|---|---|---|
| Send payment (Daily allowance / Work budget / Top-up) | Admin | + immediately, staff notified |
| Add expense (with receipt photos) | Staff | − immediately ("Awaiting review") |
| Approve | Admin | no change (confirmed) |
| Query | Admin | no change; staff must reply / add receipt |
| Reject | Admin | amount goes **back** into the balance |
| Adjust balance (±) | Admin | ± with a reason (audited) |
| Funding request approved | Admin | + (creates a payment linked to the request) |

Balances refresh on every open screen about every 10 seconds, so staff see money arrive and admins see expenses come in without reloading.

## Daily work report

The report is the same 71 questions as the Google Form, split into 7 steps:
1. Your day
2. Lessons and classroom
3. Laptops and tech issues
4. Complaints and follow-up
5. Installation and tools
6. Media
7. Wrap-up

Questions that don't apply are hidden. For example, if no lesson was delivered, the lesson questions are skipped. Drafts save on the phone automatically, and photos are compressed before upload. The **Issues board** collects pending tasks, laptop faults, complaints and follow-ups, and urgent requests. Admins mark each one handled when it's sorted.

## Files

```
index.php        App page (sign-in + app)
api.php          JSON API
file.php         Serves receipts/photos only to their owner or an admin
install.php      One-time installer (delete after use)
config.php       Created by the installer: database + app settings
inc/             Server code (web access blocked)
assets/          CSS, JavaScript, icon
uploads/         Receipts and photos (web access blocked; served through file.php)
```

## Backups

- **Database**: cPanel → *Backup* → *Download a MySQL Database Backup*, or schedule *JetBackup* if your host offers it.
- **Files**: back up the `uploads/` folder, which holds the receipts and photos, plus `config.php`.

## Customising

- **Report questions** are in `inc/schema.php`. Change wording or options there, and the form, validation, CSV import and export all follow.
- **Currency, timezone, upload limits and poll interval** are in `config.php`.
- **Colours** are the variables at the top of `assets/app.css` (`--brand` is the purple).

## Security notes

- Passwords are hashed with bcrypt. An account locks for 15 minutes after 5 failed sign-ins.
- Every change needs a CSRF token, and every query uses prepared statements.
- Staff can only see their own reports, transactions, requests and files. This is enforced on the server.
- Uploads are checked by content (JPG, PNG, WEBP, GIF or PDF only, 8MB max), renamed randomly, and stored in a folder the web server won't serve directly.
- Money changes, reviews, edits and imports are recorded in the `audit_log` table.
