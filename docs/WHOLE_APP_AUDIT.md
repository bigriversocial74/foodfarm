# Homestead Whole-Application Audit

## Scope

This audit covers the complete repository: PHP routes and services, authentication, sessions, authorization, household isolation, inventory, recipes, meal planning, prepared food, starter kits, SQL migrations, health endpoints, configuration, CI, accessibility, and deployment boundaries.

## Scoring method

Scores are evidence-based across security, correctness, data integrity, authorization, validation, concurrency, test coverage, maintainability, accessibility, and deployment readiness. A score of 10/10 requires passing automated checks, successful clean-database migration, deployed health checks, and end-to-end workflow validation.

## Initial score

**5.9/10** before repository-wide repairs.

Strengths included a coherent product model, prepared statements, CSRF tokens, household IDs in many queries, immutable ledger intent, and transaction boundaries in important workflows.

Release-blocking weaknesses included unauthenticated household fallback, incomplete permission enforcement, invitation and activation races, insufficient input ownership validation, recipe concurrency and unit risks, unsafe development defaults, limited CI, and no database-backed integration suite.

## Completed repair pass 1

- Removed first-household and first-member fallback behavior.
- Bound household context to authenticated user, member, and household records.
- Required authentication and permissions for Phase 2 writes.
- Added household ownership checks for storage, category, inventory, and member references.
- Restricted Phase 3 administration and sensitive security records.
- Expanded the permission catalog.
- Prevented duplicate active invitations.
- Added invitation row locking and atomic token consumption.
- Removed the published seed password from login UI.
- Added login throttling, safe redirect validation, and hashed email audit metadata.
- Hardened starter-kit administration, activation, fulfillment, version immutability, recipe/task provisioning, and shopping provenance.
- Added whole-application and Phase 5 static regression checks to CI.

## Completed repair pass 2

- Added strict meal-plan date parsing and a 366-day maximum range.
- Required scheduled meals to fall within their selected meal plan.
- Added meal-type and prepared-food storage-method allowlists.
- Added active household ownership checks for recipe creators, intended members, and storage locations.
- Enforced exact normalized unit compatibility between linked recipe ingredients and inventory.
- Moved ingredient availability validation inside the completion transaction.
- Locked recipe and ingredient inventory rows during completion.
- Added guarded inventory decrements that fail if quantities changed concurrently.
- Added server-issued recipe-completion nonces and database uniqueness for idempotency.
- Added stricter numeric, date, unit, and text validation.
- Required an explicit `config.php` rather than silently running from example credentials.
- Disabled debug mode in production and required secure production session cookies.
- Added a recipe-workflow regression audit to CI.

## Current provisional score

**7.7/10** after the first two repair passes.

This is not a production certification. The score reflects reviewed source code and static CI coverage, not a completed deployment or database-backed end-to-end test.

## SQL required by the repair branch

Import after the earlier migrations:

1. `database/phase4_hardening.sql`
2. `database/phase5_hardening.sql`

## Remaining work before final scoring

- Protect health endpoints from public disclosure and suppress production exception details.
- Review every SQL migration for clean-install order and replay behavior.
- Add database-backed tests for authentication, household isolation, inventory posting, recipe concurrency, invitation races, and starter-kit activation.
- Verify all event and enum values against the installed schema.
- Review password change, logout, and account routes.
- Add security headers and a consistent error boundary.
- Complete accessibility review for labels, focus states, tables, dialogs, and mobile layout.
- Run a clean database install from all migrations.
- Run deployed browser smoke tests and permission-matrix tests.

## Final 10/10 conditions

A final 10/10 score will only be issued after the complete audit checklist is closed, CI passes, migrations install cleanly, health checks pass without exposing sensitive information, and critical user workflows pass deployed end-to-end tests.
