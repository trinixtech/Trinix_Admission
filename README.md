# Trinix Admission

Interview-based admission for a multi-campus institution, in one installation and one database: import the shortlisted applicants, schedule GD and PI panels, mark on phones and tablets, lock the results and publish the merit list. Applicants never log in; all notices go by email.

This is the **Main** product (version 1.0), split from Trinix Admiso. The optional modules (scrutiny and eligibility, call lists, contact log, reservation and seat allocation, offers, admission and accounts) are in Trinix Admiso.

## Documents

| File | What it is |
| --- | --- |
| [`docs/SPEC.md`](docs/SPEC.md) | What the product does: the workflow, rules and the interview schedule template |
| [`docs/DECISIONS.md`](docs/DECISIONS.md) | Decisions behind it |
| [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md) | Running, testing and project layout |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Shared hosting without SSH: package, upload, `/install`, cron, updates |
| [`docs/Trinix-Admission-User-Manual.pdf`](docs/Trinix-Admission-User-Manual.pdf) | User manual with screenshots (source in `docs/manual/`) |

## Stack

Laravel 13 (PHP 8.3), MySQL 8, Vue 3 + TypeScript, Tailwind 4, Pest, Playwright. Releases are ZIP packages built by GitHub Actions and uploaded over FTPS/SFTP; installation runs in the browser at `/install`.
