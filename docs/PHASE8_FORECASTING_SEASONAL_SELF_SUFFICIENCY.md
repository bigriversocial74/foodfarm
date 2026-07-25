# Phase 8 — Food Forecasting, Seasonal Planning & Self-Sufficiency Intelligence

Phase 8 adds a deterministic household forecasting layer on top of Homestead's pantry, food ledger, meal plans, growing records, harvest history, preservation work, and Phase 7 tasks.

## Operating model

The forecasting workflow is:

1. Read household-specific settings.
2. Create a source-data watermark from inventory, ledger, meal, planting, harvest, and preservation records.
3. Reuse an existing certified snapshot when both settings and source data are unchanged.
4. Calculate item-level depletion, planned demand, expected household-produced inflow, projected ending quantities, days on hand, and shortage dates.
5. Calculate bounded household metrics.
6. Create evidence-linked recommendations and seasonal plan entries.
7. Allow managers to convert recommendations into Phase 7 household tasks.
8. Preserve lifecycle events for snapshot, recommendation, and seasonal-plan actions.

## Scores

### Inventory coverage

Percentage of active food inventory records with configured reorder levels that are currently above those thresholds.

### Tracked production share

Average item-level share of tracked inflow produced through harvesting or preservation instead of purchase or receipt.

This score intentionally does **not** claim to represent calories, nutrition, financial value, or complete food independence. Items without tracked inflow are excluded from the item-level average.

### Seasonal readiness

Percentage of forecast-month slots that contain at least one expected harvest window.

### Resilience

Simple average of inventory coverage, tracked production share, and seasonal readiness.

The formula is transparent and deterministic. It is intended for household planning, not scientific, medical, financial, or agricultural certification.

## Forecast inputs

- Active ingredient, prepared-food, and preserved-food inventory
- Reorder and target-stock levels
- Best-use dates
- Food-ledger consumption, recipe use, spoilage, discard, purchase, receipt, harvest, and preservation events
- Active meal plans and connected recipe ingredients
- Historical harvest quantity and units
- Future planting harvest windows
- Planned preservation output
- Household forecast horizon, history window, target production share, and buffer days

## Forecast outputs

- Item-level daily depletion
- Planned and baseline demand
- Expected harvest and preservation inflow
- Projected ending quantity
- Days on hand
- Estimated shortage date
- Data-confidence level
- Restock, rotate, use-first, preservation, buffer, and data-quality recommendations
- Seasonal harvest, preservation, purchase, and rotation entries
- Snapshot history for trend review

## Security and integrity

- Household ownership is checked for every member, snapshot, projection, recommendation, task, and seasonal entry.
- Forecast runs are idempotent through a deterministic run key.
- Source watermarks force a new snapshot after meaningful household data changes.
- Recommendation and seasonal transitions use row locks and guarded status updates.
- Recommendation acceptance creates a provenance-linked Phase 7 task in one transaction.
- Manual seasonal entries use session-bound action keys.
- Protected health diagnostics validate table presence, relational ownership, score bounds, stale runs, and accepted recommendations without tasks.

## SQL

Import after Phase 7:

`database/phase8_forecasting_seasonal_self_sufficiency.sql`

The migration is replay-safe for clean development and certification databases.

## Scope boundary

Phase 8 provides planning estimates from household-entered records. It does not:

- Predict weather
- Guarantee crop yield
- Calculate nutrition or calories
- Certify food safety
- Automatically buy products
- Control grow equipment
- Replace professional agricultural, financial, medical, or preservation guidance
