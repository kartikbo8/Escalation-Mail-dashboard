# Email Escalation Tracker - Email Response & Escalation Matrix

Built per AFC HR's request (Priya Singh) - pilot starting with Sales Team.

## The Flow (3-Level Escalation)

```
Email received -> assigned to Employee
   |
   |-- 24h: First reminder to Employee
   |-- 48h: No reply? -> Second reminder/escalation to Level 2 (Reporting Manager + HOD)
              |
              |-- 48h: No reply? -> Third/final escalation to Level 3 (HR + Admin)
                         (final - cc's everyone involved: employee, manager, HOD)

An employee's genuine reply (not an auto-reply/out-of-office) marks the ticket
'replied'. A later client reply is classified by AI: a confident confirmation
that no further action is needed stops reminders; an unresolved or uncertain
reply reopens the reminder workflow. If AI classification is unavailable, the
system keeps the reminder workflow active rather than treating the client as
satisfied. For new tickets, the same AI check applies when a genuine reply
comes from any address in the original email's To list; CC addresses are
ignored. Such replies can be detected only in mailboxes enabled for monitoring.

Holidays and non-working days are automatically skipped in every 48/24-hour
window, so a multi-day festival doesn't eat into anyone's response time.
```

## Folder Structure
```
escalation-tracker/
├── database/
│   ├── schema.sql                          -> import FIRST
│   ├── migration_2_escalation_matrix.sql    -> import SECOND (keywords, Sales dept)
│   ├── migration_3_multilevel_escalation.sql-> import THIRD (manager/HOD hierarchy)
│   └── seed_admin.php                       -> run once, then delete
├── config/
│   ├── db.php
│   └── graph.php
├── includes/
│   ├── auth.php
│   ├── functions.php          -> SLA/reminder calculation (working-hours aware)
│   ├── keyword_helper.php     -> email classification + auto-reply detection
│   ├── graph_helper.php
│   ├── layout_header.php
│   └── layout_footer.php
├── public/
│   ├── login.php / logout.php / index.php
│   ├── dashboard.php
│   ├── email_list.php         -> now shows Priority column
│   ├── email_detail.php       -> now shows Priority, Reminder time
│   ├── users.php              -> NEW: add employees, set Reporting Manager
│   └── settings.php           -> now includes HOD assignment + Keyword management
└── cron/
    ├── fetch_new_mails.php     -> tags new tickets by keyword, filters auto-replies
    └── check_escalations.php  -> full 3-level reminder + escalation engine
```

## Setup Order (Localhost)

1. **Fresh install:** Import ONLY `database/schema.sql` - it already has everything from
   migrations 2-6 merged in (keywords, Design/Costing/PPC/Projects/Production/Sales
   departments, multi-level escalation columns, notification controls, etc).
   The individual `migration_*.sql` files are kept only as a change-history record -
   you do NOT need to run them separately on a fresh database.
   On an existing database, run `database/migration_15_ticket_to_recipients.sql`
   to enable AI-checked replies from addresses in new tickets' To lists.
2. Run `database/seed_admin.php` once (creates admin@example.com / admin123), then delete it
3. Login -> go to **Users** page -> add your employees, set each one's Reporting Manager
4. Go to **Settings** -> set each department's HOD (dropdown), review/add keywords, confirm
   HR (Priya Singh) and Admin contact rows under "HR / Admin Escalation Contacts"
5. Test the flow (see previous testing notes) - create a test ticket, assign it to a real
   employee with a manager, and manually run `cron/check_escalations.php` after each SLA
   window to watch it move: Level 1 -> Level 2 -> Level 3

To catch up only reminders whose working-time checkpoint is due today, without running
the escalation stage, run `php cron/check_escalations.php --reminders-only
--reminder-date=YYYY-MM-DD` from the project root. The date must be today's local date;
future checkpoints are left for a later run, and a failed Graph send remains eligible
for retry. A reminder is sent only while the current stage's SLA deadline is still
ahead; if the checkpoint was missed and the stage deadline has already passed, the
regular escalation stage advances the ticket instead of sending a stale reminder.
Regular cron runs consider eligible overdue tickets regardless of their original
received date. Tickets remain open while the Level 2 manager/HOD window is active so
a later run can continue to the final Level 3 escalation if still unresolved.

## AI Action Assistant

Signed-in users can open the floating **AI Help** button at the bottom-right of any
application page to ask about application errors and documented workflows. Chat
messages are kept in the current session and are not given access to tickets, logs,
or the database. Do not include email content, credentials, tokens, or personal data.
If Gemini is unavailable, the assistant provides local troubleshooting guidance for
common app, login, email, reminder, and escalation issues. The assistant keeps the
Escalation Tracker context across follow-up questions, so users do not need to repeat
the application name in each message. Gemini receives a curated, non-sensitive guide
to the application's features and workflows on each chat request; source files,
credentials, live ticket data, and email bodies are not sent. Replies should remain
supportive and non-blaming for employees and administrators in English or Hindi.
Chat history stays available when navigating between application pages and clears
when the browser page is refreshed. If the chat is open while navigating, it remains
open on the next page until the user closes it with the close button or Escape.
For requests such as "Excel sheet for 1 September", it can provide a date-specific
daily ticket CSV download that opens in Excel and respects the signed-in user's
department access.

Admins and Super Admins can also open **AI Action Assistant** from the sidebar to
request one of three confirmed actions for an explicit ticket number: pause permitted
notification triggers, alert the department's configured HR/Admin contacts, or send
the assigned employee a reminder. The floating chat can prepare these same supported
actions when an Admin or Super Admin clearly asks and includes a ticket number. It
opens the existing preview page; no email is sent and no ticket setting is changed
until the authorized user reviews the ticket and explicitly confirms. The assistant
previews the ticket and recipients, enforces department access for Admins, and records
executions in the ticket timeline. A manual HR/Admin alert does not advance or reset
the ticket's automatic escalation stage. Regular users can receive guidance but
cannot initiate admin-only actions.

The same assistant can prepare an all-time employee report by employee name, without
requiring a ticket number. Admins see employees in their department; Super Admins can
select employees across departments. The report includes summary counts and retained
ticket details and can be downloaded as both an Excel workbook and CSV. Closed tickets
older than 10 days may already have been removed by the ticket-retention job, so the
report covers records currently retained by the application.

## Weekly Department Reports

`cron/send_weekly_reports.php` creates an Excel workbook for the previous full
Monday-Sunday reporting week. Department reporting managers, secondary managers,
and HODs receive only the department report they oversee. Configured HR/Admin
contacts in `escalation_contacts` receive the full workbook, with an overview
and a separate worksheet for every department.

Each department worksheet contains employee totals and email-level details:
emails received, escalations/replies recorded during the reporting week, items
still open at report time, and confirmed L1 reminder / L2 / L3 email deliveries.
The script sends reports through Microsoft Graph from
`notifications@afcindia.in` and is intended to run every Monday at 10:00 AM
Asia/Kolkata. The Windows Task Scheduler task
`Escalation Tracker - Weekly Department Reports` runs
`cron\run_weekly_reports.bat` every Monday at 10:00 AM. The computer must be on
and the configured Windows user logged in at run time. Successful recipient/week deliveries are recorded
to prevent duplicate reports; failed attempts can be retried by rerunning the job.

For a safe workbook-only test that sends no email, run:
`C:\xampp\php\php.exe cron\send_weekly_reports.php --dry-run-dir=C:\Temp\weekly-reports`.
On an existing database, run `database\migration_14_weekly_report_deliveries.sql`;
the report runner also creates the tracking table if it does not already exist.

## Department Portals

The existing merged portal remains available at the normal `dashboard.php` URL. To open a
department-only portal, first log in and use one of these URLs:

- `public/portal.php?department=design`
- `public/portal.php?department=costing`
- `public/portal.php?department=sales`
- `public/portal.php?department=marketing`

The selected department is kept for the logged-in session, so Dashboard, All Emails, Email
List, ticket details, and reports show only that department. Regular users can open only their
own department portal; admins and super admins can open any department portal. Use
`public/portal.php?department=all` to return to the unchanged merged portal.

## Connecting Outlook for ALL Departments/Employees (Application Permissions)

To read/send mail across multiple mailboxes without every employee individually
logging in, use **Application permissions** (not Delegated) in Azure:

1. Azure Portal -> App registrations -> your app -> **API permissions**
2. Add a permission -> Microsoft Graph -> **Application permissions** (not Delegated)
   -> add `Mail.Read` and `Mail.Send`
3. Click **"Grant admin consent for [Company]"** (needs a Global/Application Admin -
   ask AFC IT if you don't have this role yourself)
4. Fill `config/graph.php` with Client ID, Client Secret, Tenant ID (same as before -
   no redirect URI needed for this flow)
5. Test it: `http://localhost:8080/escalation-tracker/public/test_graph_connection.php?mailbox=someone@afcindia.in`
   - This confirms the token works and can read a mailbox, BEFORE touching real cron jobs
6. Once confirmed, set `mailbox_email` in the `departments` table **only for the
   department(s) you want live right now** (e.g. just Sales, per HR's pilot request).
   Leave every other department's `mailbox_email` blank - `fetch_new_mails.php`
   skips any department with no mailbox set, so nothing else is touched.

`cron/fetch_new_mails.php` and `cron/check_escalations.php` now fetch this
app-only token automatically (`graphGetServiceToken()`) - no manual token/env
var management needed once Admin Consent is granted.

## Auto-Syncing Employees from Microsoft 365 (instead of manual entry)

Instead of typing every employee into the Users page by hand, you can pull
them straight from AFC's Microsoft 365 directory:

1. In Azure Portal, add ONE more Application permission (same place as
   Mail.Read/Mail.Send): **User.Read.All** -> Grant admin consent again
   (same button, same admin account as before)
2. Login to the tracker -> **Users** page -> click **"Sync from Microsoft 365"**
3. Click **"Sync Now"**

This creates/updates a row in the `users` table for every enabled employee
account, with their name and email pulled directly from Microsoft 365. If
AFC's Azure AD already has each employee's **Manager** field populated (many
orgs do, via HR system sync), the Reporting Manager is linked automatically
too - no manual dropdown selection needed for that part.

**What this does NOT auto-fill:**
- **HOD per department** - Azure AD has no native "Head of Department" concept,
  only manager chains. Still set this manually from Settings.
- **department_id** - only auto-matched if Azure AD's "Department" field text
  exactly matches one of your department names (IT, Design, Costing, Account,
  PPC, Sales). Otherwise leave it blank and set manually.
- Synced users get a random unusable password and `role = 'user'` - they
  can't log into the dashboard until you manually reset their password/role
  from the Users page (most employees don't need dashboard access anyway;
  they only need to receive reminder/escalation emails).

This only reads directory profile data (name/email/manager) - no mail is
touched by this sync.

## Giving a Status Update as PDF (when someone asks "what's going on?")

Dashboard has a **"📄 Generate Status Report (PDF)"** button -> opens `public/report.php`,
a clean printable summary (overall counts, per-department breakdown, tickets currently
at Level 2/Level 3, tickets nearing SLA breach). To turn it into an actual PDF file:

1. Click the button, the report opens in a new tab
2. Click **"🖨️ Print / Save as PDF"** on the page (or press Ctrl+P)
3. In the print dialog, choose **"Save as PDF"** as the destination instead of a printer
4. Save it - now you have a PDF you can email/share with HR/Director whenever asked

No extra PHP library needed - this uses the browser's own built-in PDF export, so it
works on any machine without additional setup.

## Keyword-Gated Escalation (only subject-matched emails escalate)

Per HR's matrix, escalation is now gated by the `is_important` / `escalation_queued`
flags (set in `migration_5_subject_keyword_priority.sql`):

- When a new email arrives, `matchTicketKeywords()` checks the **subject line only**
  against your configured keywords (Settings -> Keyword Categories).
- A match sets `is_important = 1` and `escalation_queued = 1` on the ticket.
- **Only** important/queued tickets go through Level 2 (Manager+HOD) and Level 3
  (Director+HR) escalation. A normal ticket that breaches its SLA stays with the
  employee - no escalation notification is sent for it, ever.
- The full timer flow is unchanged and applies to every important ticket:
  Level 1 (employee, 48h, 24h reminder) -> Level 2 (Manager+HOD, fresh 48h, 24h
  reminder) -> Level 3 (Director+HR, final).
- Email List has a **"⚠️ Important / Escalation Queue only"** filter, and
  important tickets show a ⚠️ marker inline.

## Self-Service First Login + Admin vs Super Admin Access

**First login (any account with a placeholder password - synced or newly added):**
Enter your email on the login page and click Continue. If you've never set your
own password, you'll be prompted to create one right there - no admin needs to
hand out temp passwords. This applies to everyone: employees synced from
Microsoft 365, and any new employee added manually from the Users page.

**Access split (Manager tier vs Director/HR tier):**

| Action | admin (Manager) | super_admin (Director/HR) |
|---|---|---|
| Dashboard, Email List, Users page | ✅ | ✅ |
| Add employees, set manager/department | ✅ | ✅ |
| Stop an **employee's** (Level 1) notification trigger | ✅ | ✅ |
| Stop a **manager's** (Level 2) notification trigger | ❌ | ✅ |
| Change a user's **role** | ❌ | ✅ |
| **Reset** someone's password | ❌ | ✅ |
| **Settings** page (SLA, HOD, keywords, department mailboxes, escalation contacts) | ❌ | ✅ |
| **Sync from Microsoft 365** | ❌ | ✅ |

Run `database/migration_7_self_password_setup.sql` after the earlier migrations
to add this (only needed if your database predates this feature - a brand new
install via the consolidated `schema.sql` still needs this migration for now,
since `password_set` isn't yet folded into it).

⚠️ **Security note:** first-login-by-email-only is convenient but means anyone
who knows an employee's email can claim that account before the real employee
logs in for the first time. Fine for an internal pilot; if this becomes a
concern, add a one-time setup code/link sent via email before opening it up
company-wide.

## Per-Employee Mailbox Monitoring (AFC has no shared department mailboxes)

Since every AFC employee has their own `name@afcindia.in` mailbox (not a shared
`sales@afcindia.in`), the system monitors **individual employee mailboxes**
instead of one mailbox per department:

1. Run `database/migration_8_per_employee_mailbox.sql`
2. For an existing database, run `database/migration_13_email_source_mailbox.sql`
   so newly created tickets retain the source mailbox and can show their original
   email body on the ticket detail page. Fresh installs already include this field
   in `schema.sql`.
3. Login as **Super Admin** -> **Users** page
4. Each row now has a **"📬 Monitor Mailbox"** column with an Off/🟢 LIVE toggle
5. Click it for each Sales employee whose mailbox should be tracked - a
   confirmation pops up since this is genuinely live (real customer emails
   will start becoming tickets from that moment)
6. `cron/fetch_new_mails.php` now checks every monitored employee's mailbox.
   New mail -> ticket is **auto-assigned to that employee** (no manual
   "Assign" step needed, since we already know whose inbox it landed in)
7. A department can *also* still have one shared `mailbox_email` (set from
   Settings) if it genuinely has one - both approaches run side by side.

⚠️ Turn this on one employee at a time and watch the Email List / cron output
before enabling the whole team, especially the first time.

## Notes on the harder parts

- **Auto-reply detection** (`includes/keyword_helper.php` -> `isAutoReply()`) uses subject-line
  phrase matching (out of office, automatic reply, undeliverable, etc.) plus header checks
  (`X-Auto-Response-Suppress`, `Auto-Submitted`) when available from Graph API. It's a heuristic,
  not 100% foolproof - review the phrase list and add anything specific to what you see in
  practice (e.g. AFC's exact security auto-response subject line).
- **Keyword classification** (`matchTicketKeywords()`) tags every new ticket with the
  highest-priority matching category: Escalation Trigger > Customer/Project > PPC/Internal >
  Normal. This is informational (shown as a badge) - it does not currently change the SLA
  hours, but you could extend `calculateSlaDueDate()` to shorten the deadline for
  `escalation_trigger`-tagged tickets if HR wants that later.
- **Working-hours-aware timers**: every 48h/24h window is walked day-by-day through
  `working_hours` and skips `holidays` entirely - a 4-day festival simply doesn't count,
  exactly as requested.
