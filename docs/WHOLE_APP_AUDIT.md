# Homestead Whole-Application Audit

## Scope

This audit covers the complete repository, not only Starter Kits:

- PHP entry points and services
- Authentication, invitations, sessions, roles, and permissions
- Household data isolation
- Inventory, food ledger, recipes, meal plans, prepared food, and starter-kit provisioning
- SQL schema and phased migrations
- Health endpoints
- Front-end assets and application shell
- CI and static regression tests
- Deployment and configuration boundaries

## Scoring method

Each area is scored against production-oriented expectations:

1. Security and privacy — 25%
2. Data integrity and transactions — 20%
3. Authorization and household isolation — 15%
4. Correctness and validation — 15%
5. Architecture and maintainability — 10%
6. Automated testing and release safety — 10%
7. Accessibility and interface quality — 5%

A 10/10 score requires evidence from code review, automated checks, migration validation, and deployed end-to-end testing. A code-only review cannot by itself certify the running application as 10/10.

## First-pass application score

**5.9/10 before whole-application repairs.**

The application has a coherent product model, prepared statements, CSRF protection, several transaction boundaries, household columns, immutable event concepts, and useful staged migrations. However, the first whole-app review identified release-blocking authorization, session, concurrency, validation, migration, testing, and deployment gaps.

## Critical findings

### 1. Phase 2 trusted an unauthenticated fallback household

`HouseholdContext` selected the first household and first active member when no authenticated session existed. `phase2.php` used that context without requiring a user. This allowed unauthenticated access to family, wellness, storage, inventory, and ledger data and allowed writes with a session CSRF token.

**Repair:** remove fallback selection, bind household context to authenticated user/member/household IDs, require authentication, and enforce permissions for every Phase 2 action and view.

### 2. Phase 2 lacked action authorization

Member creation, member activation, storage creation, inventory creation, and inventory adjustment did not call the authorization service.

**Repair:** enforce `members.manage`, `storage.manage`, and `inventory.manage`; conditionally render controls; deny unauthorized sections.

### 3. Cross-household foreign keys were accepted from forms

Phase 2 accepted parent locations, categories, and storage locations by raw ID without confirming household ownership.

**Repair:** validate each selected record against the active household before writing.

### 4. Family administration leaked privileged account data

Any authenticated household role could open Phase 3 and see member email addresses, invitations, authentication history, and IP addresses even without access-management permissions.

**Repair:** require at least one family-access administration permission before loading Phase 3. Keep all mutations separately permission-guarded.

### 5. Permission administration was incomplete

The Phase 3 permission catalog did not include recipe and meal permissions added in Phase 4, so those permissions could not be explicitly overridden.

**Repair:** use a complete catalog covering members, storage, inventory, recipes, meals, and tasks.

### 6. Invitation acceptance had a race condition

Invitation validity was read before the transaction and the final update did not verify a single affected row. Concurrent submissions could race.

**Repair:** validate token format, lock the invitation row with `FOR UPDATE`, consume it conditionally, and require `rowCount() === 1`.

### 7. Login exposed demo credentials and allowed unsafe redirect forms

The login screen published a seeded password. Redirect validation accepted any string beginning with `/`, including protocol-relative paths. Failure audit metadata stored the submitted email directly.

**Repair:** remove credentials from UI, reject `//` and control-character redirects, hash attempted email in audit metadata, and add session-level attempt throttling.

### 8. Starter Kit administration crossed household and platform boundaries

The original Phase 5 implementation treated household administrators as global catalog administrators and exposed global customer/order data.

**Repair in this branch:** platform-admin boundary, purchaser binding, immutable published versions, activation locks, strict item validation, recipe/task provisioning, and source provenance.

## High-priority findings still under review

- Recipe unit compatibility and conversion policy
- Ingredient deduction locking and concurrent recipe completion
- Meal date/type and plan-range validation
- Prepared-food location/member validation
- Duplicate posting and idempotency for recipe completion
- Wellness-field visibility rules for non-administrators
- Health endpoint authentication and production error disclosure
- Config fallback behavior when `config.php` is missing
- Migration rerun safety and rollback strategy
- Password reset and invitation delivery lifecycle
- Multi-household membership and switching model
- Accessibility, keyboard navigation, labels, focus states, and responsive tables
- End-to-end database-backed tests
- Deployment smoke tests and secure production headers

## Repairs currently included in PR #7

- Platform-admin-only Starter Kit Builder
- Starter Kit activation and provisioning hardening
- Session user/member/household binding
- Removal of automatic first-household context
- Authenticated and permission-enforced Phase 2
- Cross-household ID validation in Phase 2
- Restricted Phase 3 administration visibility
- Complete permission override catalog
- Duplicate invitation prevention
- Hashed invitation/login audit identifiers
- Login throttling and local redirect validation
- Atomic invitation acceptance
- Expanded static regression checks

## Score status

The application is **not yet 10/10**.

The branch is a whole-application repair branch now, not merely a Phase 5 repair. It should remain open until:

1. The remaining services and routes are reviewed.
2. All critical and high findings are fixed or explicitly deferred with a documented boundary.
3. CI passes PHP lint and repository-wide static audits.
4. Every SQL migration imports successfully in order on a clean database.
5. Health endpoints return the expected results without leaking production internals.
6. Authenticated end-to-end workflows pass against MySQL/MariaDB.
7. The deployed application is smoke-tested.

Only then should the application receive a final rescore.
