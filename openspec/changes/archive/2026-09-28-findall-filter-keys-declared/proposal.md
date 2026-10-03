---
kind: code
depends_on: [findall-config-filters-sweep]
---

# Proposal: findall-filter-keys-declared

## Summary

Issue #1109 reported that no Course could be published: `CoursePublishGuard` found no published Lesson even when one existed. This change pins the cause with a guard test that reads the way OpenRegister reads, and adds a register test that fails when any `findAll()` filter under `lib/` names a property its target schema does not declare.

## Motivation

The live check behind #1109 ran on learniq `46346f6c`. At that commit the guard passed `register` and `schema` at the top level of its `findAll()` config. OpenRegister's `ObjectService::prepareFindAllConfig()` (openregister `lib/Service/ObjectService.php`, lines 1521-1533 at `84352bae`) reads them from `filters` only, so during the Course's own save the lookup ran against the Course table, where `courseId` is not a property. `MagicSearchHandler::applyObjectFilters()` (line 2376) answers an undeclared property with `1 = 0`, so the lookup was always empty. #1047 moved the scope into `filters` twenty minutes before #1109 was filed; the guard on `development` is correct.

The issue suspected the `tenant_id` key instead: `SearchQueryHandler::buildSearchQuery()` splits query keys on underscores (line 605). That split is real on the REST list endpoints, but `findAll()` never passes through it. The path is `ObjectService::findAll()` to `GetObject::findAll()` to `MagicMapper::findAll()` to `MagicSearchHandler::searchObjects()`, and the key reaches `applyObjectFilters()` whole. Lesson declares `tenant_id`, so the tenant filter works there.

What the defect had in common with its suspected cause is the failure shape: a filter OpenRegister cannot apply returns nothing and reports nothing. No unit test could see it, because the doubles answered whatever they were asked.

## Scope

### In Scope

- `CoursePublishGuardTest`: two cases over `RegisterFaithfulStore`, which answers like OpenRegister (scope only from `filters`, undeclared property matches nothing). The first is red against the guard at `46346f6c` and green on `development`.
- `tests/Unit/Register/FindAllFilterKeysAreDeclaredTest.php`: tokenises `lib/`, resolves each `findAll()` filter statically (literal, `array_merge()`, `tenantScoped()`, a local variable, a class's own `findAll(schema:, filters:)` wrapper) and checks every key against the shipped register.
- The 35 reads that already filter on an undeclared property are listed in the test as a two-way ratchet: a new one fails, and a fixed one that is not removed from the list fails too.

### Out of Scope

- Fixing the 35 listed reads. Most filter on `id` or `uuid`, which belongs in the config's `ids`; each needs its caller read. They are tracked in #1116.
- OpenRegister. The REST split has a supported escape, the bracket spelling `filter[tenant_id]=...` (openregister#3611), so no platform change is needed for learniq.

## Impact

Tests only. No runtime code, schema, route or UI changes.

## Rollback Strategy

Revert the merge commit.
