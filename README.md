# taxilive.site

Taxi Live – hub for Irish taxi drivers (important numbers, live updates, noticeboard).

- `public/` is the website. Edit contacts in `public/assets/contacts.js`.
- Replace `public/assets/logo.svg` (and `favicon.svg`) with the real logo.
- Pushing to `master`/`main` deploys `public/` to cPanel over FTPS via
  `.github/workflows/deploy.yml`.

Noticeboard: members post adverts/notices/wanted via `public/api/posts.php`
(PHP, no database). Posts are stored in `~/taxilive-data/posts.json` (outside
public_html), photos in `public_html/uploads/`. Posts expire after 30 days,
3 reports hide a post, max 3 posts per hour per IP. Moderate at `/admin.html`.

Repo secrets: `ADMIN_PASSWORD` (admin page), `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, and optional
`FTP_SERVER_DIR` (defaults to `/public_html/`).
