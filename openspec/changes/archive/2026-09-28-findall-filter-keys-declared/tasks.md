# Tasks: findall-filter-keys-declared

## 1. Confirm the cause

- [x] 1.1 Trace `ObjectService::findAll()` through `GetObject`, `MagicMapper` and `MagicSearchHandler` in openregister `development` and confirm the underscore split in `SearchQueryHandler::buildSearchQuery()` is not on that path.
- [x] 1.2 Read the guard as it ran on the live check (`46346f6c`) and confirm its register and schema sat outside `filters`.

## 2. Tests

- [x] 2.1 Add two `RegisterFaithfulStore` cases to `CoursePublishGuardTest`; run them against the guard at `46346f6c` (red) and on `development` (green).
- [x] 2.2 Add `FindAllFilterKeysAreDeclaredTest` with control tests for each resolution form and for both directions of the baseline.
- [x] 2.3 Mutation check: renaming the guard's `tenant_id` filter to `tenantId` turns both the guard test and the register test red.

## 3. Verify and ship

- [x] 3.1 Once before push: `php -l`, `composer check:strict`, `npm run lint`, `npm run format`, `npm run check:specs`, `npm run check:l10n-js`, `npm run check:schema-l10n`, `npm run test:js-unit`.
- [x] 3.2 Open the PR against `development`; file one follow-up issue for the 35 listed reads (#1116).

Documentation and i18n: not applicable, no user-facing text or screen changes.
