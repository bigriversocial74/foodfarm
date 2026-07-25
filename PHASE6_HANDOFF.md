# Homestead Phase 6 Build Handoff

Branch: `feature/phase6-grow-harvest-preserve-20260725`

This package contains the authoritative Phase 6 source set for:

- Permission-aware household dashboard
- Garden zones and plantings
- Manual and simulated grow readings
- Forward-only crop stage progression
- Transactional harvest posting
- Inventory and ledger provenance
- Planned and completed preservation batches
- Guarded inventory deductions
- Preserved-food output inventory
- Household isolation and idempotency
- Protected Phase 6 health checks
- MySQL 8 and MariaDB 10.11 certification

The GitHub branch already contains the initial Phase 6 implementation. The files in this package include subsequent local hardening for session-bound form action keys, strict date-time validation, planned-batch source inventory binding, permission-scoped reads, and expanded tests.

SQL required later, after the Phase 5 snapshot migration:

`database/phase6_grow_harvest_preserve.sql`

No deployment or SQL import is required during development.
