# Saagi SyncCampus

Student concern management and leadership decision support for the ISBAT University Guild.

Students report a problem anonymously and get a reference and private access code. The system
scores the concern, links it to similar reports, recommends which minister should handle it, and
reminds or escalates when it sits too long. The Guild President keeps the final say on every
assignment. A concern is only closed when the student confirms it is fixed.

Final year project by Atwijukire Gilbert (DSE), supervised by Kakooza Tobius.

Live site: https://synccampus-frontend.vercel.app

## Three ways in

| Who | Address | How they get in |
| --- | --- | --- |
| Students | `/` (the public site) | No account. They submit a concern and track it with the reference and private access code they are given. |
| Guild cabinet (President, Vice, Speaker, ministers, GRCs) | `/staff/` | Their own email and password, after a one-use sign-up link from the system admin. Each person can see and sign out their own devices. |
| System admin | `/admin/` | No password. They enter the admin email, get a one-time link by email, and must open it in the same browser on the same device that asked for it. Each signed-in device can be signed out from the admin page. |

The public site never links to `/staff/` or `/admin/`, and search engines are told not to index them.
Signing in at the wrong one shows a pointer to the right one. Asking for an admin link with any other address does nothing (the page answers the same way, so it can't be used to discover the admin's address), and the cabinet list, invitations and account controls exist only behind the admin sign-in.

The public pages also show the Guild cabinet alongside the content. On the submit page, that panel shows who usually handles the chosen kind of concern.

## Stack

- **Frontend:** plain HTML, CSS and JavaScript, installable as a Progressive Web App. No build step.
- **Backend:** PHP 8.1+ JSON API with no framework, using PDO, packaged as a Docker image for Render.
- **Database:** PostgreSQL on Neon in production. The same code also runs on MySQL, so it can be
  demonstrated on XAMPP.
- **Hosting:** Vercel serves the frontend and forwards `/api` to the PHP API on Render.

## Project layout

```
backend/
  public/index.php        entry point for every /api request
  src/
    routes.php            every API route in one list
    Controller/           one class per area: students, auth, leader, delegate, workspace, community
    Engine/               the decision-support rules (see below)
    Service/              concern workflow: create, rescore, assign, escalate, handover
    Db.php Auth.php ...   database access, tokens, validation, throttling
  database/               schema.mysql.sql and schema.pgsql.sql
  bin/                    migrate.php, escalate.php (cron), seed-demo.php
  tests/                  engines_test.php
  Dockerfile              image used on Render
  dev-router.php          local server: frontend + API on one port
frontend/
  *.html                  public student pages
  staff/                  staff dashboard and Guild workspace (not linked from the public site)
  js/  css/  data/  assets/
docs/database-structure.svg
```

## Decision-support rules

All weights and thresholds are in [backend/src/Engine/Rules.php](backend/src/Engine/Rules.php), so they can be
tuned against case-study data without touching the logic.

| Engine | How it works |
| --- | --- |
| Priority | Weighted sum of severity, urgency, students affected, academic impact, repeat reports, time open and financial impact, scaled to 0–100 and banded into Critical / High / Medium / Low. The weights come from the questionnaire ranking (rank-sum). Safety risk ranked first, so a safety concern is never below Critical. Leaders can open a breakdown of every score. |
| Related concerns | TF-IDF vectors and cosine similarity over title and description, with stop words, light stemming and a small synonym list ("wifi" and "internet" both become "network"). Matching concerns in the same category from the last 30 days are grouped, and the group size feeds back into the priority score. |
| Delegate recommendation | Area match (45%), spare capacity (25%), experience with the category (15%) and campus (15%). Delegates who are away or at full load are skipped. Overrides are recorded. |
| Escalation | A reminder halfway, then an escalation, once a concern passes its limit: Critical 6 hours, High 24 hours, Medium 3 days, Low 1 week. The student is told about the delay, and the President acknowledges the escalation. |
| Analytics | Time to assign, first response and resolution, resolution and reopen rates, student satisfaction, per-delegate performance, trends by month and campus, plus a before-and-after comparison with the questionnaire baseline. |
| Handover | A saved record of open concerns, projects and pending tasks, resolved history, delegate roles, performance and upcoming meetings, readable by the incoming cabinet. |

[docs/requirements.md](docs/requirements.md) maps every questionnaire question to the feature that answers it.

## Running it locally (XAMPP)

1. Start MySQL in the XAMPP control panel and create an empty database called `synccampus` in phpMyAdmin.
2. Copy `backend/.env.example` to `backend/.env`. The XAMPP defaults (user `root`, no password) already match.
3. From the project folder:

   ```
   C:\xampp\php\php.exe backend\bin\migrate.php
   C:\xampp\php\php.exe backend\bin\seed-demo.php
   C:\xampp\php\php.exe -S localhost:8000 backend\dev-router.php
   ```

4. Open http://localhost:8000. The staff dashboard is at http://localhost:8000/staff/. Sign in as
   `president@demo.local`, `nakato@demo.local` (a minister), `ssemanda@demo.local` (a City Campus GRC) or `admin@demo.local`, all with the
   password `Demo-password-2026`.

`seed-demo.php` adds a small cabinet and a few sample concerns so every screen has data. It refuses
to run when `APP_ENV=production`.

## Tests

```
C:\xampp\php\php.exe backend\tests\engines_test.php
```

This checks the priority, similarity, recommendation and escalation rules. It needs no database.

## Who can see what

- **Students** need no account. Their concern is reopened with the reference plus access code, and only a keyed hash of the code is stored. Five wrong codes lock that reference for a minute.
- **The Guild President and Vice** see the full queue, assign concerns, and see analytics and handover records.
- **Ministers and GRC delegates** see only the concerns assigned to them.
- **The system admin** sets up and maintains the cabinet's accounts: invites members straight from the cabinet list, edits portfolios and areas, switches off the accounts of people who leave, and cancels links. The admin cannot open concerns.
- Sensitive categories (harassment, discrimination) are always private and never appear in public statistics.
- Every staff view and change is written to `audit_log`.

Staff accounts are created only by the system admin, through one-use invitation links. Nobody can register without one.

## Running the API in Docker

The image Render builds can also be run locally:

```
docker build -t synccampus-api backend
docker run -p 8080:8080 -e DATABASE_URL="postgres://..." -e SECRET_KEY=... -e ACCESS_CODE_PEPPER=... synccampus-api
```

Then `http://localhost:8080/api/health` should return `{"status":"ok"}`.

Deployment steps are in [DEPLOYMENT.md](DEPLOYMENT.md).
