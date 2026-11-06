# Deploying to shared hosting (test site)

For **testing on shared hosting** (cPanel-style, FTP/SFTP, no SSH), as asked on 2026-11-05.

A shared host is a single server. It does **not** meet the production design in `docs/DECISIONS.md`, which requires two or more app servers, database failover, Redis and ClamAV (D16, D17). Use it to test the workflow before the production servers are set up.

---

## 1. What the hosting account needs

| Need | Where in cPanel |
| --- | --- |
| **PHP 8.3** with the extensions pdo_mysql, mbstring, openssl, intl, zip, fileinfo, dom, xml, curl | Select PHP Version / MultiPHP Manager |
| **MySQL 8** database and a user with all privileges on it | MySQL Databases |
| A **subdomain** (e.g. `admissions-test.<domain>`) whose **document root** you can choose, with **HTTPS** | Domains / Subdomains; SSL/TLS Status (AutoSSL) |
| **Cron jobs** (every minute) | Cron Jobs |
| **FTP or SFTP** account for the app folder | FTP Accounts |
| An **email account** for sending (SMTP) | Email Accounts → Connect Devices (shows server, port, user) |

About 60 MB of disk space for the code, plus documents and logs.

## 2. Folder layout

Upload the app to a folder **outside** `public_html`, for example `/home/<user>/trinix-admission`. Then set the subdomain's **document root** to its `public/` folder: `/home/<user>/trinix-admission/public` (cPanel → Domains → Manage next to the subdomain → Document Root).

Only `public/` can then be reached from the web. `.env` (database and mail passwords), `storage/` (applicants' documents) and the program code cannot, whatever happens to any `.htaccess` file. **This is the chosen layout.**

*Only if a panel cannot point the document root at `public/`:* upload the app into the subdomain's folder and copy the block from **`.htaccess.root`** to the **top** of that folder's `.htaccess`, keeping the cPanel-generated blocks below it. This works, but the private files are then protected only by that file's rules; prefer the document root above.

## 3. Getting the code onto the host (by hand)

Each release is a ready-to-upload ZIP on the repository's **Releases** page. It includes `vendor/` and the built screens, so nothing has to be installed on the server. It contains no `.env`, documents or database.

1. Download `trinix-admission-<version>.zip` from the release.
2. cPanel → **File Manager** → open the app folder (`/home/<user>/trinix-admission`) → **Upload** the ZIP.
3. Select the ZIP → **Extract** into the same folder. On an update, allow it to overwrite existing files.
4. Delete the ZIP afterwards.

Never delete `.env` or the `storage/` folder: they hold the settings, the "installed" marker, logs and the applicants' documents. Extracting a new release over the folder leaves them as they are.

*Optional:* the release workflow can upload over FTPS/SFTP instead, if the six `DEPLOY_*` values are added as repository secrets (Settings → Secrets and variables → Actions). Without them it only builds the ZIP.

## 4. First installation

1. Open **`https://admissions-test.<domain>/install`**. Every page sends you there until the installation is finished.
2. **Check:** fix anything marked required. ClamAV and Redis are usually not available on shared hosting; that is expected here.
3. **Database:** MySQL, host `localhost` (or what cPanel shows), the database name, user and password from step 1.
4. **Site and email:**
   - site address `https://admissions-test.<domain>`;
   - kind "Staging (testing)";
   - time zone Asia/Kolkata;
   - the mailbox's SMTP server, port, user and password;
   - **Send test email** to yourself.
5. **Storage and services:**
   - files: "A folder on this server";
   - **do not** tick Redis;
   - virus scanning: **Test mode**. System → Health keeps showing a warning: uploaded documents are not really scanned.
6. **Save settings**, then **Create tables**, then the **first Super Admin**.
7. **Cron job.** The finish page shows the exact line. Add it in cPanel → Cron Jobs, set to every minute:

   ```
   * * * * * /usr/local/bin/php /home/<user>/trinix-admission/artisan schedule:run >> /dev/null 2>&1
   ```

   The finish page works out the command-line PHP path (not php-fpm or lsphp) and has a Copy button; if your panel lists a different PHP 8.3 command, use that. Without Redis this cron also processes imports, document scans and emails, in up to 50 seconds of each minute. **Imports and emails do not move without it.** System → Health shows whether it runs.
8. Sign in, set up two-factor sign-in, and follow the checklist on the finish page.

## 5. First data

No sample or demo data is shipped or loaded: the system starts empty apart from roles, permissions and default settings. After signing in, follow the checklist on the installer's finish page:

1. Organisation (institution, campuses, courses, branch–course grid).
2. Cycle with offerings (seats) and Round 1.
3. Staff and faculty (invite, or **Import faculty** from Excel/CSV).
4. Source, then import the portal export (approved by a second person).
5. Interviews: stages and criteria, the interview schedule (Excel template), panels and slots; then the merit formula.
6. Optional: document types, then import applicants' files (ZIP).

**Real applicant data on a test host:** personal data is covered by the DPDP Act 2023 even during testing.
- Use HTTPS.
- Keep the number of people with accounts small.
- Remember that documents are **not virus-scanned** in test mode.
- Delete the data (or the whole database) when testing ends.

## 6. Backups

Sign in as Super Admin → **System → Backups**:

1. Set a **backup password** (12+ characters) and keep it somewhere safe. Every backup is an AES-256 encrypted ZIP; without the password it cannot be opened.
2. Tick **Make a database backup every day**, choose the time (IST), and add up to three email addresses. The encrypted ZIP is emailed (a notice instead if it is larger than the limit); a weekly full backup with the documents stays on the server.
3. Backups are kept in `storage/app/backups` (outside the website). **Download** one now and then, or rely on the emails, so a copy exists off the server.

The backups are made by PHP (no `mysqldump`), only from this app's own database, by the same every-minute cron job. Restoring (Super Admin only) makes a safety backup first. cPanel's own backups remain a good second layer.

## 7. Updates

1. Upload and extract the new release ZIP as in §3.
2. Sign in as **IT Administrator** (or Super Admin): **System → Maintenance** lists any pending database updates. Take a backup first (System → Backups → Back up now), then type `MIGRATE` and your password.
3. If the screens look old, reload the page: built files have new names in each release.

## 8. Troubleshooting

| Symptom | Check |
| --- | --- |
| Error 500 everywhere | The PHP version is 8.3 for this subdomain; `storage/` and `bootstrap/cache/` are writable (755 or 775); `storage/logs/laravel.log` |
| Imports stay "queued", no emails arrive | The cron job runs every minute with PHP 8.3 (System → Health: scheduler) |
| "Page expired" or sign-in loops | The site address in `.env` (`APP_URL`) matches the address in the browser, including https |
| `/install` opens again on a second server | Not applicable to shared hosting (one server) |
