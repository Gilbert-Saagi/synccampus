# Deploying Saagi SyncCampus

Production uses three free services:

| Part | Service | What it runs |
| --- | --- | --- |
| Frontend | Vercel | the `frontend/` folder as static files |
| API | Render | `backend/` as a Docker container (PHP 8.3 + Apache) |
| Database | Neon | PostgreSQL |

The browser only ever talks to Vercel. `frontend/vercel.json` forwards every `/api/...` request to
Render, so there is no CORS setup and no API address in the frontend code.

## 1. Database (Neon)

You can keep the existing Neon database from the earlier version of the project. The PHP schema
uses the same tables, so existing concerns and staff accounts carry over. Passwords are no longer
used: existing staff simply sign in with an emailed link to the address on their account.

For a fresh database, create a Neon project and copy its connection string
(`postgres://...?sslmode=require`). Tables are created automatically when the API starts.

## 2. API (Render)

Render has no native PHP runtime, so the API runs as a Docker service built from `backend/Dockerfile`.
The code is on GitHub at `Gilbert-Saagi/synccampus`; connect that account to Render first.

1. In Render choose **New → Blueprint**, pick the `synccampus` repository and accept `render.yaml`.
   It creates a service named `synccampus-api`.
   Or choose **New → Web Service** with the Docker runtime, Dockerfile path `backend/Dockerfile`, Docker build context `backend`, and health check path `/api/health`.
2. Set these environment variables:

   | Variable | Value |
   | --- | --- |
   | `DATABASE_URL` | the Neon connection string |
   | `ACCESS_CODE_PEPPER` | a long random string. **Never change it afterwards**: every student access code depends on it. If you still have the value from the old Render service, reuse it so access codes from the old database keep working; otherwise those old codes stop working (staff accounts and the concerns themselves are unaffected). |
   | `SECRET_KEY` | a different long random string |
   | `APP_ENV` | `production` |
   | `BOOTSTRAP_ADMIN_EMAIL` | the system admin's email address. Everyone signs in with emailed links, so it must be a real inbox. |
   | `BREVO_API_KEY` | from https://brevo.com (free, 300 emails a day): **Settings → SMTP & API → API keys**. |
   | `MAIL_FROM` | `SyncCampus <address>`, where the address is a sender you verified in Brevo (**Senders, domains & dedicated IPs → Senders**). A Gmail address works. |
   | `APP_URL` | `https://synccampus-frontend.vercel.app` (already set by `render.yaml`), used in the emailed link |

3. Deploy. On every start the container runs `bin/migrate.php`, which creates any missing tables
   and the admin account, then starts Apache.
4. Open `https://<service>.onrender.com/api/health`. It should return `{"status":"ok"}`.
5. `frontend/vercel.json` forwards `/api` to `https://synccampus-api.onrender.com`. If Render gave
   the service a different address (it adds a suffix when a name is taken), put that address in
   the `rewrites` destination, commit, and push.

Free Render services sleep after inactivity, so the first request after a quiet period can take
30–60 seconds.

## 3. Frontend (Vercel)

Because the repository moved to `Gilbert-Saagi/synccampus`, reconnect the Vercel project to it
(**Settings → Git**), or import the repository as a new project. **Root Directory** must be `frontend`. There is no
build step: `vercel.json` sets the framework to "Other" and serves the folder as it is. If an
earlier deployment set a Build Command or Output Directory in the Vercel dashboard
(**Settings → Build and Deployment**), clear both overrides.

Old staff links (`/staff.html`, `/workspace.html`) redirect to the new `/staff/` pages.

## 4. First sign-in

1. Go to `https://synccampus-frontend.vercel.app/admin/`, enter the admin email and press
   **Email me a sign-in link**. Open the emailed link on the same device and browser.
2. Under **My account → Signed-in devices** you can see every device signed in as admin and sign
   any of them out.
3. In **Guild cabinet**, press **Prepare invite** next to each member, starting with the President
   and Vice. The form fills in their name, role, position, suggested categories and campus. Check
   the categories, since they drive the delegate recommendations. Add their email so the link is
   emailed to them, or send it with **Send on WhatsApp** or **Copy link**.
4. Each member opens their link and confirms their name and email. From then on they sign in at
   `https://synccampus-frontend.vercel.app/staff/` by entering that email and opening the link
   sent to it on the same device.

The public site does not link to `/staff/` or `/admin/`. Share `/staff/` with the cabinet directly
and keep `/admin/` to yourself.

## Escalation checks

PHP has no background worker. The reminder and escalation check runs whenever the leader dashboard
or the home page statistics load, at most every five minutes, and leaders can press **Check now**.
On a host with cron, you can also schedule:

```
*/5 * * * * php /path/to/backend/bin/escalate.php
```

## Alternative: one PHP host (cPanel / shared hosting)

The whole project also runs on an ordinary Apache + PHP + MySQL host:

1. Create a MySQL database and import `backend/database/schema.mysql.sql` with phpMyAdmin.
2. Upload the repository so that its root folder is the web root. The root `.htaccess` sends
   `/api/*` to the PHP API and serves everything else from `frontend/`, so source files and `.env`
   are never reachable from a browser.
3. Set the variables from `backend/.env.example` in the control panel, or create `backend/.env`.
4. Create the admin account: set `BOOTSTRAP_ADMIN_EMAIL`, `BREVO_API_KEY`, `MAIL_FROM` and `APP_URL`, and run
   `php backend/bin/migrate.php` from the host's terminal or cron page.

Never commit `backend/.env`, database passwords, `SECRET_KEY` or `ACCESS_CODE_PEPPER`.
