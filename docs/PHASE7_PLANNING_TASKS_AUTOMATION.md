# Phase 7 — Planning, Tasks & Household Automation

Phase 7 turns Homestead's operational records into a coordinated daily work plan.

## Product loop

```text
Inventory + Meals + Garden + Preservation + Prepared Food + Recurring Duties
                              ↓
                     Daily planning cycle
                              ↓
              Tasks + assignments + shopping suggestions
                              ↓
             Start → snooze → complete → audit history
```

## Included workflows

- Permission-aware daily planning workspace at `/phase7.php`.
- Manual household tasks with assignees, due dates, priorities, and time estimates.
- Daily, weekly, and monthly recurring task templates.
- Idempotent planning cycles limited to one generated plan per household/date.
- Automatic low-stock tasks and shopping recommendations.
- Meal-preparation tasks from active meal plans.
- Harvest-readiness tasks from planting windows.
- Preservation follow-up tasks based on batch status.
- Prepared-food use-or-freeze tasks based on use-by dates.
- Start, complete, snooze, cancel, and reopen task transitions.
- Household-scoped task visibility and assignee enforcement.
- Shopping suggestion acceptance into an active household shopping list.
- Immutable task lifecycle events and household activity records.
- Protected Phase 7 health diagnostics.

## Automation boundaries

Phase 7 creates plans and recommendations from existing Homestead records. It does not send external notifications, purchase groceries, operate grow lights, control appliances, certify food safety, or make medical decisions.

## SQL

Import after Phase 6:

```text
database/phase7_planning_tasks_automation.sql
```

The migration is replay-safe on MySQL 8 and MariaDB 10.11 because it creates new tables with `IF NOT EXISTS` and does not rewrite existing household task records.

## Certification

The Phase 7 workflow validates:

- PHP and shell syntax
- Static authorization and idempotency controls
- Full clean migration sequence and Phase 7 replay
- Recurring task generation
- Low-stock task and shopping suggestion generation
- Meal, harvest, and preservation task generation
- Planning-cycle idempotency
- Task lifecycle transitions
- Shopping-list provenance
- Cross-household assignment rejection
- Protected health checks
- Authenticated HTTP task creation and completion

No installation or deployment is required during development.
