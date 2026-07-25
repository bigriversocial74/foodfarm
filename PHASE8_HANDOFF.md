# Homestead Phase 8 Build Handoff

Branch: `feature/phase8-forecasting-seasonal-self-sufficiency-20260725`

Phase 8 adds:

- Deterministic pantry and demand forecasting
- Source-watermarked, idempotent forecast snapshots
- Planned meal demand
- Historical depletion and household-produced inflow analysis
- Expected harvest and preservation inflow
- Days-on-hand and shortage projections
- Inventory coverage, tracked production share, seasonal readiness, and resilience scores
- Evidence-linked forecast recommendations
- Seasonal operating calendar
- Recommendation-to-Phase-7-task conversion
- Immutable lifecycle history
- Protected Phase 8 health diagnostics
- MySQL 8 and MariaDB 10.11 certification

SQL required later, after Phase 7:

`database/phase8_forecasting_seasonal_self_sufficiency.sql`

No SQL import, installation, deployment, or merge is performed during the development build.
