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

Upload the app to a folder **outside** `public_html`, for example `/home/<user>/trinix-admission`. Then set the subdomain's **document root** to its `public/` folder: `/home/<user>/trinix-admission/public`.

Only `public/` can then be reached from the web. `.env`, `storage/` (documents) and `vendor/` cannot.

*If the panel cannot point the document root at `public/`:* upload the app into the subdomain's folder. Copy the block from **`.htaccess.root`** to the **top** of that folder's `.htaccess` (create it if there is none). **Keep the cPanel-generated blocks** (PHP version handler, php ini directives) below it. Every request is then served from `public/`, and dot-files are refused. A `404` on `/install` almost always means this step (or the document root) is missing.

## 3. Getting the code onto the host

Each release is a ready-to-upload ZIP. It includes `vendor/` and the built screens, so nothing has to be installed on the server.

**A. Automatic, from GitHub (recommended).** In the GitHub repository, open Settings → Environments, create **`test-host`**, and add these **secrets**:

| Secret | Example |
| --- | --- |
| `DEPLOY_PROTOCOL` | `ftps` (or `sftp`, or `ftp` only if nothing else is offered) |
| `DEPLOY_HOST` | `ftp.example.org` |
| `DEPLOY_PORT` | `21` (FTPS) or `22` (SFTP) |
| `DEPLOY_USER` | the FTP/SFTP user |
| `DEPLOY_PASSWORD` | its password |
| `DEPLOY_PATH` | the app folder as the FTP user sees it, e.g. `/trinix-admission` |

Then every version tag (`v0.5.0`, `v0.6.0`, …) builds the ZIP, attaches it to a GitHub Release and uploads it. It can also be started by hand: Actions → Release → Run workflow.

The upload replaces the code and deletes files a release no longer has. It **never touches** `.env`, `storage/` (documents, logs, the "installed" marker), database files or the root `.htaccess`.

**B. By hand.** Download `trinix-admission-<version>.zip` from the GitHub Release. Upload it with the File Manager to the app folder, and use **Extract**. On an update, do not overwrite `.env` or `storage/`.

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

## 6. Updates

1. A new version tag uploads the new code (or upload it by hand as in §3B).
2. Sign in as **IT Administrator** (or Super Admin): **System → Maintenance** lists any pending database updates. Take a backup in cPanel first, then type `MIGRATE` and your password.
3. If the screens look old, reload the page: built files have new names in each release.

## 7. Troubleshooting

| Symptom | Check |
| --- | --- |
| Error 500 everywhere | The PHP version is 8.3 for this subdomain; `storage/` and `bootstrap/cache/` are writable (755 or 775); `storage/logs/laravel.log` |
| Imports stay "queued", no emails arrive | The cron job runs every minute with PHP 8.3 (System → Health: scheduler) |
| "Page expired" or sign-in loops | The site address in `.env` (`APP_URL`) matches the address in the browser, including https |
| `/install` opens again on a second server | Not applicable to shared hosting (one server) |
