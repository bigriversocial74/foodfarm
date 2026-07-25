# Homestead

Homestead is a household food system for growing, storing, cooking, preserving, and coordinating real food.

## Interfaces

- `/index.php` — visual application shell
- `/phase2.php` — authenticated household, family, storage, inventory, and ledger workspace
- `/login.php` — account login
- `/phase3.php` — restricted invitations, roles, permission overrides, and authentication history
- `/phase4.php` — recipes, family meal planning, ingredient deductions, and prepared food
- `/phase5.php` — platform-administrator starter-kit builder, versions, fulfillment mapping, orders, and activation links
- `/activate-kit.php?token=...` — customer kit review and household provisioning
- `/api/phase2-health.php` — Phase 2 database validation
- `/api/phase3-health.php` — Phase 3 authentication validation
- `/api/phase4-health.php` — Phase 4 food workflow validation
- `/api/phase5-health.php` — Phase 5 starter-kit validation

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

Update database credentials and application URL. Import SQL in order:

```text
database/schema.sql
database/phase2_install.sql
database/phase3_install.sql
database/phase4_install.sql
database/phase5_install.sql
database/phase5_shopping_extension.sql
database/phase5_hardening.sql
```

Start a local server:

```bash
php -S 127.0.0.1:8080
```

Open `/api/phase5-health.php` and confirm `ok: true`, sign in at `/login.php`, then verify the authenticated Phase 2–5 workflows.

## Whole-application audit

The repository-wide audit and repair record is maintained in:

```text
docs/WHOLE_APP_AUDIT.md
```

The initial whole-application score was **5.9/10**. The application is not certified as 10/10. A final score requires completed code review, passing CI, clean SQL imports, database-backed end-to-end tests, health validation, and deployed smoke testing.

CI currently includes:

- PHP syntax validation across all PHP files
- Whole-application static security regression checks
- Phase 5 starter-kit security regression checks

## Phase 5 capabilities

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
- Opening `received` food-ledger events with starter-kit provenance
- Shopping-list and delivery-request generation for items not yet owned
- Kit ownership and activation history

## Starter-kit integrity

Kit definitions, purchased kit versions, and household activations are separate records. Editing a future kit version does not change a customer's historical order or activated pantry contents. Items are stocked only after customer confirmation; local-shopping and delivery items remain pending until selected.

## Family wellness privacy

Height, weight, and activity levels remain optional and private by default. They are not copied into kit orders, activation records, inventory provenance, shopping lists, or delivery requests.

## Current scope boundary

Phase 5 records optional delivery requests but does not yet dispatch drivers, calculate delivery routes, charge delivery fees, or integrate with grocery-delivery providers. Email delivery, password-reset email, multi-household switching, harvest-to-inventory posting, preservation posting, and physical device control remain deferred.

## Safety boundary

Homestead supports household planning and record keeping. It does not certify food safety, replace authoritative preservation guidance, offer medical advice, or automatically operate physical devices in V1.
