# Phase 10 — Nutrition, Dietary Planning & Household Wellness

Phase 10 connects household-entered ingredient label data, recipes, meal plans, family profiles, dietary patterns, allergen rules, and Phase 7 tasks.

## Product boundary

This module is a household planning and record-keeping system. It does not diagnose conditions, prescribe diets, certify allergen safety, calculate clinical nutrition requirements, or replace medical guidance. Optional targets are entered by the household and are compared only against tracked meal-plan data.

## Capabilities

- Household assessment thresholds and recipe-variety goals
- Optional member calorie, protein, fiber, sodium, and added-sugar planning targets
- Dietary-pattern notes by household member
- Member allergen, intolerance, and preference rules
- Ingredient nutrition profiles using household-entered label or estimated data
- Ingredient allergen tags for contains, may-contain, and shared-facility notices
- Deterministic recipe nutrition snapshots
- Explicit missing-profile and unit-mismatch states
- Per-serving calorie, protein, fiber, and sodium calculations
- Recipe allergen-key aggregation
- Source-watermarked meal-plan assessments
- Member-level meal coverage, variety, optional target comparisons, and allergen conflicts
- Transparent household balance and data-completeness scores
- Allergen, data-quality, variety, protein, fiber, sodium, and added-sugar recommendations
- Recommendation-to-Phase-7-task conversion with provenance
- Guarded recommendation lifecycle and immutable nutrition events
- Protected relational and lifecycle health diagnostics

## Permissions

- `nutrition.view` permits access to the household nutrition workspace.
- `nutrition.manage` permits changes to settings, profiles, ingredient data, calculations, assessments, and recommendations.
- Owners retain full access.
- Administrators and adult members receive view/manage defaults.
- Youth members receive view access by default.
- Guest helpers do not receive nutrition access by default.

## Data integrity

- Every mutable record is household-scoped.
- Members, ingredients, recipes, meal plans, recommendations, and tasks are validated against the active household.
- Recipe calculations use deterministic source fingerprints.
- Meal-plan assessments use deterministic source watermarks and run keys.
- Purchase-style concurrent writes are not used; household-level locks serialize snapshot and recommendation lifecycle writes.
- Accepted recommendations create one provenance-linked household task.

## Database migration

Import after Phase 9:

```text
database/phase10_nutrition_dietary_wellness.sql
```

The migration is replay-safe and creates only Phase 10 objects.

## Certification

Phase 10 certification covers:

- PHP and shell syntax
- Static authorization and household-isolation controls
- Clean migration import and migration replay
- MySQL 8 and MariaDB 10.11
- Recipe nutrition calculations and reuse
- Cross-household rejection
- Meal-plan assessments and recommendation generation
- Task bridge provenance and guarded lifecycle
- Authenticated HTTP workflow
- Protected health endpoint