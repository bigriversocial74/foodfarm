# Phase 6 — Grow, Harvest & Preserve

## Goal

Close Homestead's field-to-pantry lifecycle by connecting garden zones, plantings, environmental observations, harvests, inventory, preservation batches, and the food ledger.

## Workflow

```text
Garden zone
→ planting
→ environmental readings
→ growth-stage progression
→ harvest
→ inventory or direct destination
→ planned preservation
→ guarded input deduction
→ preserved-food output inventory
→ immutable ledger history
```

## Security and integrity boundaries

- Every zone, planting, inventory item, storage location, harvest, and preservation batch is resolved through the authenticated household.
- Harvest and preservation writes use database transactions.
- Plantings and inventory inputs are locked before mutation.
- Harvest and preservation action keys prevent accidental duplicate posting.
- Existing inventory can receive a harvest only when its normalized unit matches.
- Preservation input deductions use a guarded `current_quantity >= requested_quantity` update.
- Failed operations roll back without changing inventory.
- Cross-household zone, inventory, location, and member references are rejected.
- Growth stages cannot move backward; completed and failed plantings are terminal.

## Permissions

- `garden.view`
- `garden.manage`
- `harvest.record`
- `preservation.view`
- `preservation.manage`

Owners inherit all permissions. Administrators and adult members can manage the full workflow by default. Youth members can view garden and preservation records and record harvests. Guest helpers receive garden view access only unless an administrator grants an override.

## SQL

Import after the Phase 5 snapshot migration:

```text
database/phase6_grow_harvest_preserve.sql
```

The migration is replay-safe on MySQL 8 and MariaDB 10.11. It adds harvest and preservation provenance columns, unique action-key indexes, household workflow indexes, output-inventory links, and `preservation_batch_inputs`.

## Safety boundary

Homestead records the preservation method, dates, quantities, storage, notes, and optional authoritative reference followed by the household. It does not certify the safety of a recipe or preservation process.
