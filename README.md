# DevTech Staff Portal

A self-hosted web app for daily work reports, job orders, staff attendance, work tools, staff wallets (allowances, budgets, expenses with receipts), funding requests and admin dashboards. It runs on standard cPanel hosting: PHP 8.1+ and MySQL/MariaDB. There's no build step, and no Composer or Node needed on the server.

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
6. **Turn on HTTPS (required).** cPanel → *SSL/TLS Status* → run AutoSSL. Then uncomment the three "force HTTPS" lines at the bottom of `.htaccess`. Phones only share GPS location and open the camera on HTTPS, so clock-in won't work without it.
7. **Set up email** (needed for follow-up reminders):
   - cPanel → *Email Accounts* → create e.g. `portal@yourdomain.com` → *Connect Devices* to see the SMTP host and port.
   - In the portal: **Settings → Email notifications**. Enter the host, port (465 = SSL), username and password, then **Send test email**.
8. **Add the reminders cron job.** cPanel → *Cron Jobs* → *Once Per Hour*, command:
   `php /home/YOUR_CPANEL_USER/public_html/cron.php` (use your real path; it's shown under **Settings → Follow-up reminders** together with a URL version for hosts that only allow URL cron jobs). Without a cron job, reminders still go out the first time someone opens the portal after the send time.

## First-day setup

1. **Settings**: check the schools/locations list, expense categories, payment types and the default daily allowance.
2. **Import history**:
   - **Work reports**: open the Google Sheet → *Form responses 1* tab → File → Download → CSV. Upload it. Staff accounts are created from the email column automatically.
   - **Expense console**: download each tab (payments, expenses, adjustments) as CSV. Upload one at a time, match the columns on the preview screen, and import. Add staff first so names can be matched. Rows with names it can't match confidently are skipped and listed, so you can add or rename the staff member and import again. Re-importing never creates duplicates.
3. **Staff setup**: set a password for each staff member. Imported accounts show "No password", and you can use the wand button to generate one. Tick "Email them their sign-in details" to send the details by email.
4. **Opening balances**: if staff already hold money, use **Send payment → Adjust a balance** with the reason "Opening balance".
5. **Staff profiles**: open each person from **Staff setup** and tick their usual **Responsibilities** (e.g. Installation, Maintenance & repair). Their daily report starts with these ticked.
6. **Attendance**: in **Settings → Attendance**, set work start and end times, the grace period and working days. Then open each school under **Clients** and set its GPS point (press *Use my current location* while standing there) so clock-ins show the nearest school.
7. **Reminders**: in **Settings → Follow-up reminders**, choose the send time and any extra addresses (e.g. an operations inbox), then press **Send digest now** to check it arrives.
8. **Work tools**: add your laptops, toolkits and testers under **Work tools** and issue them to staff.

## Updating an existing install

Upload the new files over the old ones (keep `config.php` and `uploads/`). The database upgrades itself the next time anyone opens the portal: new tables and columns are added and nothing is deleted.

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

The first step asks **"What did you do today?"** Staff tick one or more of:

- Class / lesson delivery
- Google Classroom / content prep
- Project work
- Installation
- Maintenance & repair
- Training
- Media editing
- Office / admin work

Only the sections for the ticked work are asked. Staff also rate how urgent their open issues are (Normal / High / Urgent), and each device can be marked High or Urgent; High and Urgent reports are emailed to admins immediately and sort to the top of the Issues board. Someone who did installation and maintenance never sees the lesson or training questions. Instead they get the installation questions and a **device form**: one entry per laptop, socket, projector and so on, with a fault checklist for that device (OS, RAM, storage, battery, no power, wiring…), what they did, parts used and whether it's fixed. Admins can edit the devices and faults under **Settings → Maintenance faults**.

Complaints and follow-up, and Wrap-up, are always asked. Drafts save on the phone automatically, and photos are compressed before upload. Reports made before this change still display correctly.

The **Issues board** collects pending tasks, device faults (including maintenance entries that aren't fixed yet), complaints and follow-ups, and urgent requests. Admins mark each one handled when it's sorted, or press **Create job** to turn it into a job order.

## Job orders

Tasks collected from schools and companies.

| Status | Who moves it there |
|---|---|
| Requested | Staff logged a job they picked up on site |
| Open | Admin created it, or accepted a requested job by assigning staff |
| In progress | Assigned staff pressed **Start**, or listed it on their daily report |
| Awaiting check | Staff pressed **Mark complete** with at least one photo and a note |
| Sent back | Admin wasn't satisfied; staff are notified with the reason |
| Done | Admin approved the photos |

When staff mark a job complete they also capture the **client's sign-off**: the contact's name, phone and a signature drawn on the phone. If the client isn't available, they must say why, and the admin sees a warning. Staff can **raise a job to High or Urgent** with a reason; admins are emailed straight away.

A job can have several staff, and admins can reassign it at any time; the timeline keeps the full history of who was on it, every update, photo and status change. Overdue jobs are highlighted, and staff get a notification when they're added to a job.

## Attendance

The portal works as an attendance machine. Staff tap **Clock in** when they arrive and **Clock out** when they leave. Each time:

- **A selfie taken now** is required (photos older than 10 minutes, e.g. from the gallery, are rejected).
- **A fresh GPS reading** is taken the moment they press the button. The GPS fix carries its own timestamp; if it is more than 60 seconds older than the button press (a cached or old location) it is rejected and the phone tries again. The phone's clock must be within 5 minutes of the server's, and accuracy must be better than 200m.
- **A reason** is required when clocking in after the start time (plus the grace period) or clocking out before the end time.
- If location can't be read at all, staff can still clock in by saying where they are; the record is flagged "No GPS" for the admin.

The portal names the nearest client site and the distance. An hour after closing time, anyone still clocked in is reminded; after midnight, forgotten clock-outs are marked **No clock-out**.

**Attendance → Clock-ins** shows the daily register (who's in, late, left early, selfies, map links, reasons). **Attendance → Report** gives each staff member's days present and absent, late days and minutes late, average arrival time, early leaves, missed clock-outs, hours worked and punctuality for any date range, with a colour-coded calendar per person and a CSV export. Staff see their own report under **My attendance**. Admins can correct a time or add one manually with a reason, which is flagged and kept in the audit log.

## Follow-up reminders

Every morning at the time set in Settings (default 08:00), admins and any extra addresses get one email listing:

- overdue jobs, and jobs due today or tomorrow
- high and urgent jobs still open
- jobs with no update for a few days (default 3)
- completed jobs waiting for your check, and jobs logged by staff that nobody is assigned to yet
- high-priority issues from daily reports not yet marked handled
- yesterday's late arrivals, absences and missed clock-outs

Each staff member gets their own list (overdue, due soon, sent back, high priority, missed clock-outs). Nothing is sent to someone with nothing outstanding.

## Clients

**Clients** lists every school and company with its contact, GPS point, open and overdue jobs and unfixed device faults. Each client page shows open jobs, job history, client sign-offs, daily reports from that location and the full device repair history. The directory is filled from your schools list and existing jobs, and a job typed with a new client name adds that client automatically.

## Staff profiles and work tools

Each staff profile shows contact, next-of-kin and bank details, responsibilities, tools held, attendance this month, jobs, reports and wallet. Staff keep their own details up to date under **Profile**.

**Work tools** is the equipment register: name, tag, serial number, condition and value. Issue a tool to a staff member and return it to the store (recording its condition); every move is kept in the tool's history.

## Files

```
index.php        App page (sign-in + app)
cron.php         Hourly reminders (run from cPanel Cron Jobs)
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

- **Report questions** are in `inc/schema.php`. Change wording or options there, and the form, validation, CSV import and export all follow. Each section's `show` rule decides which ticked duty asks it.
- **Currency, timezone, upload limits and poll interval** are in `config.php`.
- **Logo**: replace `assets/logo.png` (full logo) and `assets/logo-mark.png` (round icon). The company name and address can be changed under **Settings → Company**.
- **Colours** are the variables at the top of `assets/app.css` (`--brand` is the purple).

## Security notes

- Passwords are hashed with bcrypt. An account locks for 15 minutes after 5 failed sign-ins.
- Every change needs a CSRF token, and every query uses prepared statements.
- Staff can only see their own reports, transactions, requests, attendance and files, and only the jobs they're on or logged. This is enforced on the server.
- Uploads are checked by content (JPG, PNG, WEBP, GIF or PDF only, 8MB max), renamed randomly, and stored in a folder the web server won't serve directly.
- Money changes, reviews, edits and imports are recorded in the `audit_log` table.
