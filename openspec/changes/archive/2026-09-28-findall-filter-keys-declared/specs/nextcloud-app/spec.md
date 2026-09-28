# Nextcloud App Shell: object read filter keys delta

**Spec refs**: `nextcloud-app`, ADR-001 (data through OpenRegister), ADR-011 (use OpenRegister core as it is)

## ADDED Requirements

### Requirement: Every object read MUST filter only on properties its target schema declares

Every `ObjectService::findAll()` call under `lib/` MUST use, as a `filters` key, either a query context key (`register`, `schema`, `registers`, `schemas`, `extend`, `@self`, or a key starting with `_`) or a property that the target schema in `lib/Settings/learniq_register.json` declares. The key MUST be compared verbatim: on the `findAll()` path OpenRegister does not split a key on underscores, so `tenant_id` is valid wherever the schema declares `tenant_id`. An object id MUST travel in the config's `ids`, not as an `id` or `uuid` filter.

#### Scenario: A course with a published lesson can be published

- **GIVEN** a Course `course-7` in tenant `tenant-a` and a published Lesson with `courseId = course-7` and `tenant_id = tenant-a`
- **WHEN** `CoursePublishGuard` checks the Course's `publish` transition against a store that answers like OpenRegister
- **THEN** the guard allows the transition
- **AND** the lookup's filters carry `tenant_id = tenant-a` whole, with no `tenant` key

#### Scenario: A draft lesson, another course's lesson or another tenant's lesson does not count

- **GIVEN** only a draft Lesson on `course-7`, a published Lesson on `course-8` and a published Lesson on `course-7` in `tenant-b`
- **WHEN** `CoursePublishGuard` checks `course-7` in `tenant-a`
- **THEN** the guard refuses the transition

#### Scenario: A new undeclared filter key fails at unit time

- **GIVEN** a `findAll()` call under `lib/` whose filters name a property the target schema does not declare, and which is not in the test's known list
- **WHEN** `FindAllFilterKeysAreDeclaredTest` runs
- **THEN** it fails and names the file, the line, the schema and the key

#### Scenario: A fixed read must leave the known list

- **GIVEN** an entry in the test's known list that the scan no longer finds
- **WHEN** `FindAllFilterKeysAreDeclaredTest` runs
- **THEN** it fails and asks for the entry to be deleted
