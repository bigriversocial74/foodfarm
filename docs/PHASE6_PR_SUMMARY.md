# Phase 6 Pull Request Summary

## Scope

Phase 6 connects Homestead's existing garden records to inventory, the immutable food ledger, and preservation workflows.

## Main workflows

- Create and monitor garden zones
- Plan plantings and advance crop stages without backward transitions
- Record manual or simulated environmental readings
- Post harvests to inventory, preservation, recipe use, donation, or compost
- Generate planned preservation batches from garden harvests
- Complete preservation with guarded inventory deductions and preserved-food output inventory
- Preserve source-harvest, inventory, member, location, and batch provenance
- Enforce household isolation, permissions, row locking, rollback, and action idempotency

## Validation

- Repository-wide PHP lint
- Phase 6 static security audit
- MySQL 8 and MariaDB 10.11 clean migration and replay
- Database-backed grow, harvest, preservation, rollback, and isolation tests
- Protected health-check validation
- Authenticated HTTP smoke testing

## Deployment status

No SQL import, installation, or deployment has been performed. The new migration is required later:

`database/phase6_grow_harvest_preserve.sql`
