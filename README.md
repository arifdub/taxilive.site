# taxilive.site

Taxi Live – hub for Irish taxi drivers (important numbers, live updates, noticeboard).

- `public/` is the website. Edit contacts in `public/assets/contacts.js`.
- Replace `public/assets/logo.svg` (and `favicon.svg`) with the real logo.
- Pushing to `master`/`main` deploys `public/` to cPanel over FTPS via
  `.github/workflows/deploy.yml`.

Repo secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, and optional
`FTP_SERVER_DIR` (defaults to `/public_html/`).
