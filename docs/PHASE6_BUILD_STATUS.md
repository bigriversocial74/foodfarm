# Phase 6 Build Status

Phase 6 is implemented on `feature/phase6-grow-harvest-preserve-20260725` and is proposed for merge into `main`.

## Included

- Permission-aware household dashboard
- Garden-zone and planting management
- Manual and simulated environmental readings
- Forward-only planting stage progression
- Harvest destinations for inventory, preservation, recipe use, donation, and compost
- Transactional and idempotent harvest posting
- Inventory and immutable food-ledger provenance
- Planned and completed preservation batches
- Guarded preservation input deductions with rollback protection
- Separate preserved-food output inventory
- Household isolation for garden, harvest, and preservation records
- Protected Phase 6 health diagnostics
- MySQL 8 and MariaDB 10.11 certification workflow

## SQL

Required later, after the Phase 5 snapshot-storage migration:

`database/phase6_grow_harvest_preserve.sql`

No database import or deployment has been performed during development.
