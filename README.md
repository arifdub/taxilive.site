# taxilive.site

Taxi Live – hub for Irish taxi drivers: noticeboard, important numbers, live traffic cameras.

## Pages
| URL | What |
|---|---|
| `/` | Home, live updates, noticeboard |
| `/numbers/` and `/numbers/?c=<category>` | Important numbers, one page per category |
| `/cameras/` | Links to TII traffic cameras by road |
| `/post.html` | Post an advert or notice |
| `/manage.html` | Members edit / remove their posts with an email code |
| `/admin/` | Admin panel: posts, numbers, admins |
| `/setup/` | One-time setup: connects the MySQL database (locks itself afterwards) |

## Setup on cPanel
1. cPanel → **MySQL® Databases**: create a database and a user, add the user to the database with **ALL PRIVILEGES**.
2. cPanel → **Email Accounts**: create e.g. `noreply@taxilive.site` (used to send members their codes).
3. Open `https://taxilive.site/setup/`, enter the database details, choose your admin login.
   Existing posts and numbers are copied in automatically.
4. Log in at `https://taxilive.site/admin/`.

Database design: [docs/DATABASE.md](docs/DATABASE.md). Server code lives in `public/lib/`.
Settings are saved outside `public_html` in `~/taxilive-data/config.php`.

## Deploy
Pushing to `master`/`main` deploys `public/` to cPanel over FTPS
(`.github/workflows/deploy.yml`). Repo secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`,
optional `FTP_SERVER_DIR` (defaults to `/public_html/`).
