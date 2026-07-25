# Homestead

Homestead is a household food system for growing, storing, cooking, preserving, and coordinating real food.

This repository contains the V1 product requirements document and the first working application shell.

## Current application shell

The PHP shell includes responsive mock-data pages for:

1. Dashboard
2. Family Members
3. Pantry Inventory
4. Recipes & Meal Planning
5. Garden Monitoring
6. Preservation Tracking
7. Shopping List
8. Grow Light Schedules
9. Storage Locations
10. Tasks & Calendar
11. Reports
12. Settings

The interface is intentionally manual-first. Sensor readings, button actions, and automation controls are simulated until the database-backed service layer is implemented.

## Requirements

- PHP 8.1+
- MySQL 8+ or MariaDB 10.6+
- A modern browser

## Local preview

From the repository root:

```bash
php -S 127.0.0.1:8080
```

Then open:

```text
http://127.0.0.1:8080/index.php
```

No database is required to preview the current application shell.

## Configuration

Copy the example configuration before database work begins:

```bash
cp config-example.php config.php
```

Update the database credentials and application URL. `config.php` is intentionally excluded from version control.

## Database

The initial V1 schema is available at:

```text
database/schema.sql
```

The schema establishes:

- Household and family-member boundaries
- Roles, youth restrictions, preferences, and skills
- Storage locations
- Pantry, prepared-food, and supply inventory
- A permanent food lifecycle ledger
- Recipes and meal planning
- Garden zones, plantings, readings, and harvests
- Preservation batches
- Shopping lists
- Grow-light schedules
- Household tasks and activity events

Do not import the schema for the static shell unless you are beginning the database implementation milestone.

## Application structure

```text
assets/
├── css/app.css
└── js/app.js

database/
└── schema.sql

docs/
└── HOMESTEAD_V1_PRD.md

config-example.php
index.php
```

## Next milestone

The next scoped milestone is the PHP/MySQL foundation:

- Configuration loader and database connection
- Session authentication
- Household access boundary
- Shared controllers, repositories, and services
- Database-backed Family Members
- Database-backed Storage Locations
- Inventory CRUD and lifecycle ledger
- CSRF protection and server-side validation

## Safety boundary

Homestead supports household planning and record keeping. It does not certify food safety, replace authoritative preservation guidance, or automatically operate physical devices in V1.
