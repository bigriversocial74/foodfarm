# Homestead

Homestead is a household food system for growing, storing, cooking, preserving, and coordinating real food.

This repository contains the V1 product requirements, the visual application shell, and the first database-backed household operations workspace.

## Interfaces

### Visual application shell

Open:

```text
/index.php
```

The shell includes responsive mock-data pages for Dashboard, Family Members, Pantry Inventory, Recipes & Meal Planning, Garden Monitoring, Preservation Tracking, Shopping List, Grow Light Schedules, Storage Locations, Tasks & Calendar, Reports, and Settings.

### Phase 2 data workspace

Open:

```text
/phase2.php
```

This workspace provides database-backed:

- Household-scoped Family Members
- Optional private height, weight, daily activity, dietary, allergy, and serving-profile fields
- Adult, youth, administrator, and guest/helper roles
- Member activation and deactivation
- Hierarchical Storage Locations
- Inventory opening entries
- Positive and negative quantity adjustments
- Immutable Food Lifecycle Ledger events
- CSRF protection, validation, transactions, and flash messages

## Requirements

- PHP 8.1+
- MySQL 8+ or MariaDB 10.6+
- PDO MySQL extension
- A modern browser

## Local setup

Copy the example configuration:

```bash
cp config-example.php config.php
```

Update the database credentials in `config.php`.

Create the database, then import:

```text
database/schema.sql
database/phase2_install.sql
```

The Phase 2 installer adds optional family wellness-planning fields and creates a minimal household, owner, storage locations, and shared categories when the tables are empty.

Start the local PHP server:

```bash
php -S 127.0.0.1:8080
```

Open:

```text
http://127.0.0.1:8080/phase2.php
```

## Health check

After configuration and SQL import, open:

```text
/api/phase2-health.php
```

A successful response reports:

- `connected: true`
- No missing required tables
- No missing wellness-profile columns
- The active household ID

## Family wellness privacy

Height, weight, and activity levels are optional. They are intended only for private household meal-demand planning.

- Serving multiplier remains the primary operational field.
- Measurements are excluded from the activity ledger.
- Visibility defaults to private.
- Youth measurements should be managed only by authorized adults.
- The application does not provide medical, diagnostic, calorie, or weight-loss guidance.

## Application structure

```text
app/
├── bootstrap.php
├── Database.php
├── HouseholdContext.php
└── Support.php

api/
└── phase2-health.php

assets/
├── css/app.css
└── js/app.js

database/
├── schema.sql
└── phase2_install.sql

docs/
└── HOMESTEAD_V1_PRD.md

config-example.php
index.php
phase2.php
```

## Scope boundary

Phase 2 establishes household-scoped data operations. It does not yet include complete login and invitation screens, granular permission administration, recipe deductions, harvest-to-inventory posting, preservation posting, shopping completion, or real device control.

## Safety boundary

Homestead supports household planning and record keeping. It does not certify food safety, replace authoritative preservation guidance, offer medical advice, or automatically operate physical devices in V1.
