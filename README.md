# Homestead

Homestead is a household food system for growing, storing, cooking, preserving, and coordinating real food.

## Interfaces

- `/index.php` — visual application shell
- `/phase2.php` — household, family, storage, inventory, and ledger workspace
- `/login.php` — account login
- `/phase3.php` — invitations, roles, permission overrides, and authentication history
- `/api/phase2-health.php` — Phase 2 database validation
- `/api/phase3-health.php` — Phase 3 authentication validation

## Requirements

- PHP 8.1+
- MySQL 8+ or MariaDB 10.6+
- PDO MySQL extension
- A modern browser

## Installation

Copy the example configuration:

```bash
cp config-example.php config.php
```

Update the database credentials and application URL. Then import the SQL files in order:

```text
database/schema.sql
database/phase2_install.sql
database/phase3_install.sql
```

Start a local server:

```bash
php -S 127.0.0.1:8080
```

Open `/api/phase3-health.php` and confirm `ok: true` before using `/login.php`. The Phase 3 installer prepares the seeded owner account for initial access; rotate the temporary credential immediately after installation.

## Phase 3 capabilities

- Password-hash verification and secure session regeneration
- Login, logout, and protected routes
- Household-scoped user/member identity
- Seven-day invitation tokens stored only as hashes
- Invitation acceptance and account creation
- Invitation revocation and status history
- Owner, administrator, adult, youth, and guest/helper role defaults
- Member-specific allow/deny permission overrides
- Owner protection from permission downgrades
- Authentication and permission audit events
- CSRF validation on account-management writes

## Family wellness privacy

Height, weight, and activity levels remain optional and private by default. They support household meal-demand planning only. They are excluded from activity feeds and authentication records, and Homestead does not provide medical, diagnostic, calorie, or weight-loss guidance.

## Current scope boundary

Phase 3 does not yet include email delivery, password-reset email, multi-household account switching, recipe ingredient deductions, harvest-to-inventory posting, preservation posting, shopping completion, or physical device control.

## Safety boundary

Homestead supports household planning and record keeping. It does not certify food safety, replace authoritative preservation guidance, offer medical advice, or automatically operate physical devices in V1.
