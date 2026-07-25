# Phase 9 — Cost, Waste, and Savings Intelligence

## Purpose

Phase 9 connects the operational food system to household economics. It records purchase prices, maintains compatible-unit cost basis, calculates recipe costs, values recorded waste, summarizes monthly food spending, and turns evidence into internal household tasks.

## Core records

- `household_finance_settings` — monthly budget, waste target, savings target, and price-alert threshold.
- `household_suppliers` — household-owned supplier directory.
- `food_purchase_records` — immutable purchase evidence with quantity, total cost, unit cost, package metadata, and ledger provenance.
- `inventory_cost_basis` — weighted unit cost for an inventory item in its tracked unit.
- `food_waste_events` — inventory or prepared-food loss with quantity, estimated value, and ledger provenance.
- `recipe_cost_snapshots` and lines — source-keyed recipe cost calculations with explicit priced, missing, and unit-mismatch states.
- `household_finance_snapshots` — deterministic monthly summaries with source-data watermarks.
- `finance_recommendations` — evidence-linked actions that can become Phase 7 tasks.
- `finance_lifecycle_events` — immutable purchase, waste, snapshot, and recommendation history.

## Calculation rules

### Purchase unit cost

`total purchase cost / quantity added to inventory`

### Weighted inventory cost basis

The service combines prior recorded purchase quantity and weighted cost with the new purchase quantity and unit cost. A unit mismatch is rejected rather than silently converted.

### Recipe cost

Each recipe ingredient is priced only when its linked inventory item has a cost basis in the same unit. Missing prices and mismatched units remain visible. Cost per serving is withheld until every ingredient has compatible pricing.

### Waste value

Inventory waste uses the compatible weighted unit cost available at the time of recording. Prepared-food waste uses the latest complete recipe cost per serving when available.

### Household-production value

Recorded harvest quantity is valued using the compatible replacement cost of its linked inventory item. Harvests sent directly to preservation are excluded so preserved outputs are not counted twice.

### Preservation value

Completed or active preserved output is valued using the compatible replacement cost of the output inventory item.

### Estimated savings

The monthly snapshot uses tracked household-production value plus tracked preservation value minus recorded waste value, floored at zero. This is an operational estimate, not a financial statement.

## Security and integrity

- Household scope is required on every mutable lookup and update.
- Active household membership is validated for every service action.
- Purchase and waste action keys are single-use and payload-bound.
- Inventory, cost-basis, ledger, waste, and lifecycle changes are transactional.
- Monthly snapshots use source-data watermarks and deterministic run keys.
- Recommendation transitions use row locks and guarded prior-status conditions.
- Accepted recommendations create one provenance-linked Phase 7 task.
- Health diagnostics detect cross-household, source, lifecycle, and metric inconsistencies.

## Permission defaults

- Owners: full access.
- Administrators and adult members: `finance.view` and `finance.manage`.
- Youth members: `finance.view`.
- Guest helpers: no finance access by default.
- Permission overrides remain supported.

## Scope boundary

Phase 9 does not connect bank accounts, process payments, perform receipt OCR, calculate taxes, certify accounting records, provide investment guidance, or automatically buy food. All monetary values depend on the completeness and unit consistency of household records.