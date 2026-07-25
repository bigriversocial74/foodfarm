# Phase 10 Handoff

## Branch

`feature/phase10-nutrition-dietary-wellness-20260725`

## Scope

Nutrition, dietary planning, allergen controls, recipe nutrition snapshots, meal-plan assessments, recommendations, and Phase 7 task conversion.

## SQL

Required later, after Phase 9:

```text
database/phase10_nutrition_dietary_wellness.sql
```

## Main interface

```text
/phase10.php
```

## Health endpoint

```text
/api/phase10-health.php
```

The endpoint requires the configured `X-Homestead-Health-Key` or an authenticated platform-administrator session.

## Certification files

```text
.github/workflows/phase10-certification.yml
tests/phase10_static_audit.php
tests/phase10_integration.php
tests/phase10_http_smoke.sh
```

## Deployment state

No SQL import, installation, deployment, production configuration change, or merge was performed during development.

## Safety boundary

Nutrition values and targets are household-entered planning data. The module does not provide diagnosis, treatment, medical advice, clinical requirement calculations, or allergen certification.