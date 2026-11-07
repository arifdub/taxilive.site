# Taxi Live – database design

MySQL / MariaDB (cPanel), `utf8mb4`, all times stored in UTC. Tables are created by `/setup/`
(code: `public/lib/schema.php`). Every table starts with `tl_` so it can share a database.

```
tl_admins ──< (logs in to /admin/)

tl_posts ──< tl_post_reports          one report per visitor per post
   │
   └── email ── tl_login_codes        6-digit codes members use to manage their posts

tl_contact_categories ──< tl_contacts   Important numbers (one page per category)

tl_rate_events   anti-spam counters        tl_meta   schema version, install date
```

## Tables

### `tl_posts` – noticeboard adverts & notices
| column | type | notes |
|---|---|---|
| id | INT PK | internal id (admin) |
| public_id | VARCHAR(24) UNIQUE | id used on the site |
| type | ENUM advert, notice | |
| category | VARCHAR(20) | adverts: rent, sale, wanted, service, other |
| title / body | VARCHAR(160) / TEXT | members: 80 / 1000 chars, admins: 160 / 5000 |
| price | VARCHAR(30) | adverts only |
| name, phone, area | VARCHAR | shown publicly |
| email | VARCHAR(190) NULL, indexed | **private** – the member's key to edit / remove |
| image | VARCHAR(80) NULL | `uploads/<random>.jpg` |
| link_url, link_label, deadline_text | VARCHAR | notice extras (button + red deadline) |
| status | ENUM active, hidden | hidden by admin or by 3 reports |
| pinned | TINYINT | shown at top of the board |
| report_count | INT | |
| ip_hash | CHAR(64) | keyed hash of poster IP (never the raw IP) |
| created_at, updated_at, expires_at | DATETIME | member posts expire after 30 days; editing renews; NULL = never |

### `tl_post_reports`
`(post_id, ip_hash)` primary key → each visitor can report a post once. Deleted with the post.

### `tl_login_codes`
| column | notes |
|---|---|
| email | who asked |
| code_hash | HMAC of the 6-digit code (the code itself is never stored) |
| attempts | max 5 wrong tries |
| expires_at | 15 minutes |
| used_at | single use; asking for a new code cancels older ones |

### `tl_contact_categories` / `tl_contacts`
Category: `slug` (used in `/numbers/?c=slug`), `title`, `icon`, `sort_order`.
Contact: `category_id` → category (deleted with it), `name`, `phone`, `note`, `url`, `sort_order`.

### `tl_admins`
`username` (unique), `email`, `password_hash` (PHP `password_hash`), `created_at`, `last_login_at`.

### `tl_rate_events`
`(action, subject, created_at)` – e.g. 3 posts / hour per IP, 3 codes / 15 min per email,
10 logins / 15 min per IP. Rows older than a day are cleaned up automatically.

### `tl_meta`
Key/value: `schema_version`, `installed_at`.

## Where things live on the server
| | |
|---|---|
| Settings (DB password, secret) | `~/taxilive-data/config.php` – outside `public_html`, written by `/setup/` |
| Admin / member sessions | `~/taxilive-data/sessions/` |
| Photos | `public_html/uploads/` (scripts blocked by `.htaccess`) |
| Server code | `public_html/lib/` (blocked from the web by `.htaccess`) |

## Member edit flow
1. Member posts with an email (required, never shown).
2. On **Edit or remove my posts** (`/manage.html`) they enter that email.
3. If the email has posts, a 6-digit code is emailed (from the "send emails from" address chosen in setup).
4. Code → signed in for that browser session → edit (renews 30 days), change/remove photo, or delete.

SMS codes can be added later with a provider such as Twilio (paid per message).
