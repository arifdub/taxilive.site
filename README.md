# taxilive.site

Files in `public/` are deployed to cPanel (`/public_html/`) over FTP by
`.github/workflows/deploy.yml` on every push to `master`/`main`.

Required repo secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`.
