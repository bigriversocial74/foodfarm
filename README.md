# Homestead

Homestead is a household food system for growing, storing, cooking, preserving, and coordinating real food.

## Interfaces

- `/index.php` — visual application shell
- `/phase2.php` — household, family, storage, inventory, and ledger workspace
- `/login.php` — account login
- `/phase3.php` — invitations, roles, permission overrides, and authentication history
- `/phase4.php` — recipes, family meal planning, ingredient deductions, and prepared food
- `/api/phase2-health.php` — Phase 2 database validation
- `/api/phase3-health.php` — Phase 3 authentication validation
- `/api/phase4-health.php` — Phase 4 food workflow validation

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

Update the database credentials and application URL. Import the SQL files in order:

```text
database/schema.sql
database/phase2_install.sql
database/phase3_install.sql
database/phase4_install.sql
```

Start a local server:

```bash
php -S 127.0.0.1:8080
```

Open `/api/phase4-health.php` and confirm `ok: true`, then sign in at `/login.php` and open `/phase4.php`.

## Phase 4 capabilities

- Database-backed household recipe library
- Recipe ingredient records linked to pantry inventory
- Base servings, yield, preparation, cooking, and resting details
- Meal plans and scheduled breakfast, lunch, dinner, and snack records
- Family-member selection for each meal
- Serving calculations using each member's serving multiplier
- Transactional recipe completion
- Required-ingredient availability checks
- Pantry quantity deductions
- Immutable `used_in_recipe` ledger events
- Recipe-run history and ingredient snapshots
- Prepared-food and leftover batches
- Prepared-food inventory creation
- Refrigerator, freezer, counter, and shelf-stable storage methods
- Use-by dates, storage locations, reheating notes, and intended family members
- Role and override permissions for viewing, managing, planning, and completing recipes

## Family wellness privacy

Height, weight, and activity levels remain optional and private by default. Serving multiplier is the primary field used by Phase 4 meal calculations. Private measurements are not exposed in recipe runs, meal schedules, prepared-food records, or food ledger events.

## Current scope boundary

Phase 4 does not yet include email delivery, password-reset email, multi-household account switching, automatic nutrition or calorie targets, harvest-to-inventory posting, preservation posting, shopping completion, or physical device control.

## Safety boundary

Homestead supports household planning and record keeping. It does not certify food safety, replace authoritative preservation guidance, offer medical advice, or automatically operate physical devices in V1.
