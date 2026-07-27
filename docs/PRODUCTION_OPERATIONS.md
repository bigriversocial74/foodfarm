# Homestead Production Operations and Recovery

This runbook covers deployment preflight, backups, restore verification, post-deploy checks, and rollback. It does not replace hosting-provider snapshots, managed database backups, infrastructure monitoring, or an organization-specific incident plan.

## Safety rules

- Run all `bin/` tools from the command line only.
- Keep `config.php`, database dumps, checksums, logs, and temporary client files outside the public web root.
- Never pass a database password as a command-line argument.
- Use a dedicated database account with only the privileges Homestead requires.
- Keep at least one verified backup off the application server.
- Test restores against a disposable database before relying on a backup.
- Do not run the restore-verification script against the configured Homestead database.

## 1. Deployment preflight

After uploading code and importing all migrations, run:

```bash
php bin/production-preflight.php
```

For machine-readable output:

```bash
php bin/production-preflight.php --json
```

The preflight validates production mode, disabled debug output, HTTPS, required PHP extensions, secret lengths, explicit database credentials, database connectivity, UTC database sessions, strict SQL mode, representative schema objects, and the presence of an active household owner.

A failed preflight is a release blocker.

## 2. Create a backup

Choose a directory outside the repository and public web root:

```bash
bash bin/database-backup.sh --output-dir=/srv/homestead-backups
```

The script:

- reads credentials from `config.php` without printing them;
- uses a private temporary client configuration file;
- performs a single-transaction logical dump;
- includes routines, triggers, events, and binary-safe data;
- compresses the dump;
- validates the gzip stream;
- creates a SHA-256 checksum; and
- applies private file permissions.

Copy both the `.sql.gz` file and its `.sha256` file to independent storage.

## 3. Verify a backup by restoring it

Use a disposable database name beginning with `homestead_restore_`:

```bash
bash bin/verify-database-restore.sh \
  --backup=/srv/homestead-backups/homestead-homestead-YYYYMMDDTHHMMSSZ.sql.gz \
  --target-database=homestead_restore_release_test \
  --confirm-disposable
```

The script verifies the checksum when present, drops and recreates only the explicitly confirmed disposable database, imports the backup, checks representative tables, and removes the disposable database when complete.

Add `--keep-database` only when an operator needs to inspect the restored copy manually.

## 4. Deploy code and migrations

1. Place the application in maintenance mode at the hosting layer or temporarily restrict access.
2. Create and copy a verified backup.
3. Record the currently deployed Git commit.
4. Upload the exact release commit or release archive.
5. Preserve the production `config.php`; never replace it with `config-example.php`.
6. Import only the documented migrations that have not already been applied, in repository order.
7. Confirm file ownership and server rules protect `app/`, `bin/`, `database/`, `docs/`, `tests/`, configuration files, backups, and logs.
8. Run the production preflight.
9. Restart PHP workers or clear opcode caches when the hosting environment requires it.

## 5. Post-deploy verification

Run against the real HTTPS hostname:

```bash
php bin/post-deploy-smoke.php
```

The checker validates the landing page, sign-in page, PWA manifest, service worker, offline fallback, required security headers, and all protected Phase 2–11 health endpoints using the configured health key. It reports pass/fail status without printing protected response bodies.

Then complete a brief authenticated manual check:

- sign in with a non-platform household account;
- open the dashboard, pantry, recipes, planning, and alerts;
- confirm permission-restricted sections remain hidden;
- submit one reversible non-destructive update;
- sign out and verify the session is closed; and
- install or refresh the PWA on one supported device when PWA assets changed.

## 6. Rollback

### Code-only rollback

Use this when no new migration was applied:

1. Restore the previously recorded Git commit or release archive.
2. Preserve the current production `config.php`.
3. Restart PHP workers or clear opcode caches if required.
4. Run the production preflight and post-deploy smoke check.

### Database-affecting rollback

Use this when a migration or data-writing release must be reversed:

1. Stop application writes at the hosting layer.
2. Preserve a forensic backup of the current failed state.
3. Restore the last backup that was independently verified.
4. Deploy the matching application commit from the same release point.
5. Run the production preflight, every keyed health endpoint, and an authenticated smoke check.
6. Reopen traffic only after household isolation and critical workflows are confirmed.

Do not attempt to improvise a down-migration against production data unless that exact rollback was designed and tested in advance.

## 7. Recommended schedule

- Backup before every deployment or migration.
- Automated database backup at least daily.
- Independent off-server copy after every backup cycle.
- Automated checksum validation after creation.
- Disposable restore verification at least monthly and before major releases.
- Keyed health checks after every deployment and on a regular monitoring interval.
- Quarterly review of operator access, secrets, retention, and recovery instructions.

## 8. Retention baseline

A practical minimum is:

- seven daily backups;
- four weekly backups; and
- twelve monthly backups.

Adjust retention for household data sensitivity, hosting capacity, legal obligations, and recovery objectives. Encrypt backup storage and limit access to designated operators.
