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
8. **Add the reminders cron job.** cPanel → *Cron Jobs* → *Once Per Five Minutes* (`*/5 * * * *`), command:
   `php /home/YOUR_CPANEL_USER/public_html/cron.php` (use your real path; it's shown under **Settings → Follow-up reminders** together with a URL version for hosts that only allow URL cron jobs). Every 5 minutes is needed for the reminders sent before a task is due (e.g. 1 hr and 30 min before); the daily digest still goes out once a day. Without a cron job, reminders only go out while someone has the portal open.

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
8. **Work tools**: add your laptops, toolkits and testers under **Work tools** and issue them to staff. Laptops need their brand, model, serial number, RAM, storage and a photo on record before they can be issued. Staff report problems from **Profile → Tools**; management sends items for repair and records the repairer, cost and outcome.

9. **Ideas & plans**: any staff member can post an idea, a plan for the company or a recommendation under **Ideas & plans**. Everyone can vote and comment; management reviews each post and gives approved ones an owner and a due date, and the owner marks progress until it is done.

## Updating an existing install

Upload the new files over the old ones (keep `config.php` and `uploads/`). The database upgrades itself the next time anyone opens the portal: new tables and columns are added and nothing is deleted.

**Automatic deploys.** `.github/workflows/deploy.yml` uploads changed files over FTP every time `main` changes. You can also run it by hand from the *Actions* tab. It never touches `config.php`, `uploads/` or `install.php`. One-time setup:

1. cPanel → *FTP Accounts* → create an account (e.g. `deploy@devtech.ng`) and note the FTP server shown under *Configure FTP Client*.
2. GitHub repo → *Settings → Secrets and variables → Actions*:
   - Secrets: `FTP_SERVER` (e.g. `ftp.devtech.ng`), `FTP_USERNAME`, `FTP_PASSWORD`.
   - Variable `FTP_SERVER_DIR`, which is the portal folder **relative to that FTP account's home**, ending in `/`. If it isn't set, `./` is used, which is right when the FTP account's home is the portal folder (as with `devtech@staff.devtech.ng`).

The first run uploads every file. Later runs only upload what changed.

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

**Time frames and tasks.** Every job an admin creates needs a due date, and can have a due time. A job can be split into **tasks**, each with its own staff and due date/time (add them when creating the job, or with **Add task** on the job). Staff given a task are added to the job automatically. Staff tick their own tasks off; when all are done they mark the whole job complete with photos and sign-off as usual.

**Daily job-order checklist.** Work isn't carried to the next day without an admin's approval:

- At every clock-in, staff see a pop-up of their pending job orders and tasks (due today and overdue first, then what's coming up).
- The clock-out form shows a checklist of everything due today or overdue, with the time left on each ("2h 10m left", "Expired 40m ago"). Items at the school they're leaving come first; staff tick several and press **Mark selected as done**.
- **Leaving a school mid-day:** everything due today at that school must be ticked done, or have more time asked for, before they can clock out there.
- **Ending the day** (clock-out ticked **This is my last location today**): every item must be done (task ticked, or job marked complete), or have more time approved.
- **Not finished: ask for more time** sends the reason and a new date/time to the admins (in-app and email). The admin approves or refuses from the job page; approving moves the due date. Moving the due date in the job or task edit form also approves it.
- **One-hour answer window.** If no admin answers a request for more time within an hour, the staff member can press **No answer: go ahead** and the new due date applies. If no admin checks a job marked complete within an hour, its staff can press **Close job myself**; the job shows "Closed without admin check" and admins can still send it back. Admins are emailed either way, and both appear in the daily email.
- Clocking out of a location mid-day with work still due sends the staff member a "pending job orders" notice; at the end of the day they get a notice of anything carried over with approval.
- The admin's daily email lists requests waiting for an answer and tasks left past their due date; each staff member's email lists their tasks due today. If no admin can be reached, an admin can still close a staff member's day from the attendance register.

## Attendance

The portal works as an attendance machine. Staff tap **Clock in** when they arrive and **Clock out** when they leave. Each time:

- **A selfie taken now** is required (photos older than 10 minutes, e.g. from the gallery, are rejected).
- **A fresh GPS reading** is taken the moment they press the button. The GPS fix carries its own timestamp; if it is more than 60 seconds older than the button press (a cached or old location) it is rejected and the phone tries again. The phone's clock must be within 5 minutes of the server's, and accuracy must be better than 200m.
- **A reason** is required when clocking in after the start time (plus the grace period) or clocking out before the end time.
- If location can't be read at all, staff can still clock in by saying where they are; the record is flagged "No GPS" for the admin.

The portal names the nearest client site and the distance. An hour after closing time, anyone still clocked in is reminded; after midnight, forgotten clock-outs are marked **No clock-out**.

**Attendance → Clock-ins** shows the daily register (who's in, late, left early, selfies, map links, reasons). **Attendance → Report** gives each staff member's days present and absent, late days and minutes late, average arrival time, early leaves, missed clock-outs, hours worked and punctuality for any date range, with a colour-coded calendar per person and a CSV export. Staff see their own report under **My attendance**. Admins can correct a time or add one manually with a reason, which is flagged and kept in the audit log.

## Timetables

**Timetable** holds each school's weekly classes: day, start and end time, class, subject, teacher and room.

- **Who edits:** teaching staff add and update the periods they teach at their own schools; admins add or change any period at any school and choose the teacher (the teacher is notified). The portal refuses a period that clashes with the same teacher's other classes, or with the same class at that school.
- **Who sees:** staff see the full timetable of every school they're assigned to, with their own periods highlighted, plus **My week** across all their schools. Admins see every school.
- **Class record:** open a period to see its topics *taught* (from classes logged, with what was achieved), *this week* and *coming up* (from lesson plans), and any next classes with no plan yet. From there teachers **post the topic and lesson plan** for the next class (the plan is linked to that class and period) and, after the class, **log what was taught**.
- **Plan before class:** each period shows "Plan posted" or "No plan posted" for its next class. Teachers get an in-app reminder on the day before and the morning of a class with no plan, and it's in their daily email. Admins' daily email lists today's classes with no plan and yesterday's timetabled classes that weren't logged; the lessons compliance report counts timetabled classes with no plan per teacher.

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

**School documents:** each client page has a **Documents** tab for the school's timetable, school calendar, scheme of work, agreement and other papers (PDF, Word, Excel or a photo, one file per document with a title and type). Management uploads and removes them, from there or from the top of that school's **Timetable**; staff assigned to the school see them on their Timetable page, can open or download them, and are notified when a new one is added.

## Management roles

Set a role under **Staff setup**. Every role below admin is still an employee: they clock in, file reports, have a wallet and appear on payroll like staff.

| | Supervisor | Sub admin | Manager | Admin |
|---|:-:|:-:|:-:|:-:|
| Jobs and tasks: create, assign, check, send back, follow up | ✓ | ✓ | ✓ | ✓ |
| Lessons, schemes, training, timetables, work tools | ✓ | ✓ | ✓ | ✓ |
| View staff, attendance register, work reports, issues board | ✓ | ✓ | ✓ | ✓ |
| Memos to staff, and requests to the admin | ✓ | ✓ | ✓ | ✓ (decides requests) |
| Queries | Proposes | Raises and closes | Raises and closes | Raises, closes, sets fines |
| More time on a job or task | Recommends | Recommends | Approves | Approves |
| Expense review and funding requests | | | ✓ (not their own) | ✓ |
| Add and edit staff accounts | | | Staff, supervisors, sub admins | Everyone |
| Payroll, salaries, fines, bank details, balances, ledger, company wallet, settings | | | | ✓ |
| Delete or cancel jobs, edit attendance | | | | ✓ |

- **Proposed queries:** a supervisor's query isn't sent to the staff member. A manager, sub admin or admin reviews it, then issues or discards it, and the supervisor is told which.
- **Recommendations for more time:** the recommendation shows on the request, and admins and managers get a notification. Nobody but an admin can answer their own request, review their own expense or decide their own funding request.

## Memos, notes and requests

- **Memos:** any management role sends a memo to all staff, to chosen people, or to management. Each person gets a notification and the sender sees who has read it.
- **Notes from staff:** staff send a note or memo to management (admins, managers, sub admins and supervisors). Staff can't message each other.
- **Requests:** a manager, sub admin or supervisor sends a request to the admin, who approves or declines it with a note. Open requests stay on the admin's Memos badge until answered.
- Everyone can reply on a memo's thread. On a memo to staff, each staff member sees only the sender's replies and their own.

## General notices

Any management role posts a notice from **Notices** to all staff, or to everyone including management. It shows as a banner at the top of each person's home page until they press **Got it**, it reaches its *Show until* date, or it is taken down. Urgent notices show in red. The sender sees who has pressed Got it, who has only opened it, and who hasn't seen it yet.

## Phone alerts and Google Tasks

**Phone alerts (web push)** need no setup beyond HTTPS. Each staff member opens **Profile → Alerts on your phone → Turn on** (the home page also offers it once). From then on every portal notification (notices, jobs, memos, queries and the rest) pops up on that phone straight away, even with the portal closed.
- Android: works in Chrome.
- iPhone: needs iOS 16.4 or newer. Open the portal in Safari, tap **Share → Add to Home Screen**, open it from the new icon, then turn alerts on.

**Google Tasks** (optional) adds each notice and each new job order to a task list named after the company in the staff member's own Google Tasks, which reminds them on the due date. Google Tasks only keeps the date, not the time. To set it up once:
1. In [Google Cloud Console](https://console.cloud.google.com/), create a project, open *APIs & Services → Library* and enable **Google Tasks API**.
2. *OAuth consent screen*: choose **External** (or **Internal** if staff use Google Workspace on your domain), fill in the app name, and add the scope `.../auth/tasks`. With **Internal** there is nothing more to do. With **External**, publish the app (*Publishing status → In production*): while it is in *Testing* only listed test users can link, and Google expires their link every 7 days. Google may ask you to verify the app because the Tasks scope is sensitive; until then staff see an "unverified app" warning they can click through.
3. *Credentials → Create credentials → OAuth client ID → Web application*. Paste the **Authorised redirect URI** shown in **Settings → Phone alerts & Google Tasks** (it is `https://your-domain/google.php`).
4. Copy the Client ID and Client secret into that Settings card and save.

Staff then press **Link** under Google Tasks on their profile and sign in with their Google account. They can unlink any time.

## Staff profiles and work tools

Each staff profile shows contact, next-of-kin and bank details, responsibilities, tools held, attendance this month, jobs, reports and wallet. Staff keep their own details up to date under **Profile**.

**Work tools** is the equipment register: name, tag, serial number, condition and value. Issue a tool to a staff member and return it to the store (recording its condition); every move is kept in the tool's history.

## Files

```
index.php        App page (sign-in + app)
cron.php         Reminders every 5 minutes (run from cPanel Cron Jobs)
api.php          JSON API
google.php       Google Tasks sign-in (OAuth redirect)
sw.js            Service worker that shows phone alerts
manifest.json    Lets phones add the portal to the home screen
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
- Staff can only see their own reports, transactions, requests, attendance and files, and only the jobs they're on or logged. Management roles see work records; only managers also see expenses and funding requests, and only admins see pay. This is enforced on the server.
- Uploads are checked by content (JPG, PNG, WEBP, GIF or PDF only, 8MB max), renamed randomly, and stored in a folder the web server won't serve directly.
- Money changes, reviews, edits and imports are recorded in the `audit_log` table.
