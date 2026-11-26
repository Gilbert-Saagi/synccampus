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
uses the same tables, so existing concerns and staff accounts carry over. Old passwords keep
working and are upgraded to bcrypt the next time each person signs in.

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
   | `BOOTSTRAP_ADMIN_EMAIL` / `BOOTSTRAP_ADMIN_PASSWORD` | the first admin account; the password needs 12+ characters. Only used if that account does not exist yet. |

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

1. Go to `https://synccampus-frontend.vercel.app/staff/` and sign in with the bootstrap admin account.
2. Change the admin password, then create invitation links for the Guild President and Vice.
3. They open their links, create their accounts, and invite ministers and GRC delegates, setting
   each person's portfolio and the categories they handle (these drive the recommendations).

The public site does not link to `/staff/`. Share that address with the cabinet directly.

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
4. Create the admin account: set `BOOTSTRAP_ADMIN_EMAIL` / `BOOTSTRAP_ADMIN_PASSWORD` and run
   `php backend/bin/migrate.php` from the host's terminal or cron page.

Never commit `backend/.env`, database passwords, `SECRET_KEY` or `ACCESS_CODE_PEPPER`.
