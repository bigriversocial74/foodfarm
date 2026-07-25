# Homestead

Homestead is a household food system for growing, storing, cooking, preserving, planning, and coordinating real food.

## Interfaces

- `/index.php` — public product landing page
- `/login.php` — account login
- `/dashboard.php` — permission-aware household operating dashboard
- `/phase2.php` — household, family, storage, inventory, and ledger workspace
- `/phase3.php` — restricted invitations, roles, permission overrides, and authentication history
- `/phase4.php` — recipes, family meal planning, ingredient deductions, and prepared food
- `/phase5.php` — platform-administrator Starter Kit builder, versions, fulfillment mapping, orders, and activation links
- `/phase6.php` — garden zones, plantings, readings, harvests, inventory posting, and preservation batches
- `/phase7.php` — daily planning, assignments, recurring tasks, automation cycles, and shopping suggestions
- `/prepared-food.php` — prepared-food consumption, freezing, spoilage, and ledger posting
- `/starter-kit-lifecycle.php` — Starter Kit version duplication, retirement, cancellation, and activation lifecycle
- `/activate-kit.php?token=...` — customer kit review and household provisioning
- `/api/phase2-health.php` — protected Phase 2 database validation
- `/api/phase3-health.php` — protected Phase 3 authentication validation
- `/api/phase4-health.php` — protected Phase 4 food-workflow validation
- `/api/phase5-health.php` — protected Phase 5 Starter Kit validation
- `/api/phase6-health.php` — protected grow, harvest, inventory, and preservation validation
- `/api/phase7-health.php` — protected planning, task, suggestion, and lifecycle validation

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

Set explicit database credentials, application URL, environment, `debug=false` in production, and a long random `security.health_key`. Import SQL in order:

```text
database/schema.sql
database/phase2_install.sql
database/phase3_install.sql
database/household_owner_integrity_hardening.sql
database/phase4_install.sql
database/phase4_hardening.sql
database/phase5_install.sql
database/phase5_shopping_extension.sql
database/phase5_hardening.sql
database/phase5_snapshot_storage_hardening.sql
database/phase6_grow_harvest_preserve.sql
database/phase7_planning_tasks_automation.sql
```

Create the first owner from the command line:

```bash
php bin/create-owner.php
```

Start a local server:

```bash
php -S 127.0.0.1:8080
```

## Protected health checks

Health endpoints require either an authenticated platform-administrator session or the configured key in the `X-Homestead-Health-Key` header:

```bash
curl -H "X-Homestead-Health-Key: YOUR_CONFIGURED_KEY" https://example.com/api/phase7-health.php
```

Production health failures return a generic message rather than database or exception details.

## Whole-application audit

The repository-wide audit and repair record is maintained in:

```text
docs/WHOLE_APP_AUDIT.md
```

The initial whole-application score was **5.9/10**. The audited repository code and release-certification matrix reached **10/10** after the complete security, authorization, concurrency, migration, accessibility, database, and HTTP validation passes.

Phase-specific MySQL 8 and MariaDB 10.11 workflows extend that baseline with clean migration, replay, database integration, protected-health, and authenticated HTTP certification.

## Planning, tasks, and household automation

- One daily planning cycle per household and date
- Manual tasks with assignments, due dates, priorities, and time estimates
- Daily, weekly, and monthly recurring task templates
- Low-stock tasks and shopping recommendations
- Meal-preparation tasks from active meal plans
- Harvest-readiness tasks from planting windows
- Preservation follow-up tasks from active batches
- Prepared-food use-or-freeze tasks from use-by dates
- Start, complete, snooze, cancel, and reopen transitions
- Assignee-aware task visibility and household-manager controls
- Idempotent generation keys and single-use shopping suggestions
- Immutable task lifecycle and household activity records

## Grow, harvest, and preserve capabilities

- Household-owned garden zones with target environment ranges
- Crop and variety plantings with expected harvest windows
- Manual and simulated environmental readings
- Forward-only growth-stage transitions
- Harvest destinations for inventory, preservation, recipe use, donation, and compost
- Idempotent harvest posting with household and unit validation
- Automatic inventory and food-ledger provenance for stocked harvests
- Planned preservation batches created directly from harvests
- Guarded preservation input deductions with rollback protection
- Separate preserved-food output inventory records
- Preservation input provenance and immutable lifecycle ledger entries
- Role defaults and member-specific permission overrides for garden, harvest, and preservation work

## Starter Kit capabilities

- Administrator-defined basic and specialized starter kits
- Immutable version records and SKUs
- Shipped, local-shopping, optional-delivery, digital-only, and customer-supplied items
- Ingredient, equipment, supply, seed, and digital item types
- Required and optional item configuration
- Delivery and shipping eligibility
- Suggested storage, reorder levels, supplier, and estimated price metadata
- Purchased-kit and external-order records
- Secure one-time activation links stored only as SHA-256 hashes
- Customer confirmation of actual quantities and fulfillment choices
- Transactional digital-pantry provisioning
- Opening ledger events with Starter Kit provenance
- Shopping-list and delivery-request generation for items not yet owned
- Kit ownership and activation history

## Family wellness privacy

Height, weight, and activity levels remain optional and private by default. They are not copied into kit orders, activation records, inventory provenance, shopping lists, delivery requests, garden records, harvests, preservation batches, planning cycles, tasks, or shopping suggestions.

## Current scope boundary

Phase 5 records optional delivery requests but does not dispatch drivers, calculate delivery routes, charge delivery fees, or integrate with grocery-delivery providers. Email delivery, password-reset email, multi-household switching, external notifications, automatic purchasing, and physical device control remain deferred. Phase 6 accepts manual and simulated grow readings; real sensor adapters remain a later integration layer. Phase 7 creates internal plans and recommendations but does not execute external actions.

## Safety boundary

Homestead supports household planning and record keeping. It does not certify food safety, replace authoritative preservation guidance, offer medical advice, or automatically operate physical devices.
