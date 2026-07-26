# Phase 11 Handoff

## Branch

`feature/phase11-alerts-calendar-notifications-20260725`

## Scope

Household notification inbox, deterministic signal sync, member preferences, visibility-aware shared calendar, ICS export, digests, adapter-ready delivery outbox, lifecycle history, and Phase 7 task conversion.

## SQL

Required later, after Phase 10:

```text
database/phase11_alerts_calendar_notifications.sql
```

## Interfaces

```text
/phase11.php
/phase11-calendar.php
/api/phase11-health.php
```

## Certification

```text
.github/workflows/phase11-certification.yml
tests/phase11_static_audit.php
tests/phase11_integration.php
tests/phase11_http_smoke.sh
```

Certification covers PHP 8.1 and PHP 8.3, MySQL 8 and MariaDB 10.11, member-private calendar persistence, visibility-aware notification delivery candidates, and complete-source integrity checks.

## Delivery boundary

The in-app inbox, permission-aware calendar, digests, and outbox persistence are implemented. Email and web-push providers are not integrated or dispatched by this phase.

## Deployment state

No SQL import, installation, deployment, production configuration change, or merge was performed during development.
