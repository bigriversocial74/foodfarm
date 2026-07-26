# Phase 11 — Alerts, Notifications, and Shared Calendar

## Purpose

Phase 11 turns Homestead's operational data into a household attention layer. It reads current source records, creates deduplicated in-app notifications, projects time-sensitive work onto a shared calendar, and preserves provenance back to the originating record.

## Interfaces

- `/phase11.php` — notification inbox, sync controls, preferences, digests, calendar, and outbox status
- `/phase11-calendar.php` — authenticated, permission-aware ICS export
- `/api/phase11-health.php` — protected relational and lifecycle diagnostics

## Source signals

The deterministic sync evaluates active tasks, low inventory, prepared-food use-by dates, Phase 8 forecast shortages, seasonal plans, high-priority Phase 9 finance recommendations, high-priority Phase 10 nutrition recommendations, upcoming meals, and harvest windows.

## Deduplication and lifecycle

Every notification uses a stable household-scoped SHA-256 deduplication key. Household-wide and member-private alerts use separate recipient scopes. Repeated syncs update existing alerts. Managed alerts that are no longer present are expired.

States are `unread`, `acknowledged`, `completed`, `dismissed`, and `expired`. A notification can create one provenance-linked Phase 7 task; repeated conversion returns the existing task.

Notification settings and member preferences are included in the sync source watermark so delivery-policy changes produce a fresh deterministic run without duplicating active alerts.

## Privacy and calendar access

Notification and calendar visibility is enforced as household, adults-only, or member-private. Member-private task and seasonal events are available only to the assigned member. Adults-only financial notifications are excluded from youth and guest views, digests, task conversion, calendar queries, and external outbox candidates.

## Digests and external delivery

Daily and weekly digest runs are deterministic per member and period. Email and web-push adapters are disabled by default. Outbox records are created only when the household adapter and member channel are enabled, the category is allowed, the minimum priority is met, and the recipient is authorized to view the alert.

Phase 11 records adapter-ready payloads and attempt state but does not dispatch through an external provider. Sensitive wellness preview text is redacted unless the recipient explicitly allows it.

## Security and integrity

- Household and member isolation
- Active-member validation
- Visibility-aware inbox, lifecycle, digest, calendar, and outbox access
- CSRF and session-bound action keys
- Household transaction serialization
- Stable source watermarks and deterministic run keys
- Source and recipient provenance
- Stale sync and delivery-lock diagnostics
- MySQL 8 and MariaDB 10.11 certification

## Certification boundary

The repository workflows certify PHP compatibility, replay-safe migration behavior, database-backed household isolation, permission-aware browser workflows, protected health diagnostics, and authenticated ICS export. They do not certify an external email or web-push provider because provider dispatch remains outside Phase 11.

## Migration

Run after Phase 10:

```text
database/phase11_alerts_calendar_notifications.sql
```
