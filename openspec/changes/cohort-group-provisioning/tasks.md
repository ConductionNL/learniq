# Tasks: cohort-group-provisioning

## Implementation Tasks

### Task 1: Add CohortGroupProvisioningHandler
- **spec_ref**: `openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group`
- **files**: `lib/Listener/CohortGroupProvisioningHandler.php`
- **acceptance_criteria**:
  - GIVEN a Cohort with `ncGroupId: null` WHEN its `activate` transition fires
    THEN a Nextcloud group is created, every resolvable `teacherIds`/`learnerIds`
    entry is added as a member, and `ncGroupId` is saved back onto the Cohort
  - GIVEN a Cohort whose `ncGroupId` is already set WHEN an `activate`-shaped
    event is handled for it again THEN no new group is created
  - GIVEN a Cohort with a provisioned `ncGroupId` WHEN an Enrolment referencing
    it transitions `activate`/`withdraw` THEN the learner is added/removed
    from that Cohort's group
  - GIVEN a Cohort with no `ncGroupId` yet WHEN an Enrolment referencing it
    transitions `activate`/`withdraw` THEN nothing happens and no exception
    is raised
- [x] Implement
- [x] Test

### Task 2: Register the listener
- **spec_ref**: `openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group`
- **files**: `lib/AppInfo/Registrar/CollaborationListenerRegistrar.php`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN listeners are registered THEN
    `CohortGroupProvisioningHandler` is registered for `ObjectTransitionedEvent`
- [x] Implement
- [x] Test

### Task 3: Unit tests with `createMock()` doubles
- **spec_ref**: `openspec/changes/cohort-group-provisioning/specs/school-structure/spec.md#requirement-cohort-activation-provisions-and-maintains-a-real-nextcloud-group`
- **files**: `tests/Unit/Listener/CohortGroupProvisioningHandlerTest.php`
- **acceptance_criteria**:
  - Every scenario in the spec has a corresponding test method
  - Doubles use `createMock()`/`onlyMethods()` only — never `addMethods()`, so
    a double cannot expose a method the real class lacks
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) —
  `CohortGroupProvisioningHandlerTest`
- [ ] Newman/Postman tests for new/changed API endpoints — N/A, no API changed
- [ ] Browser tests (Playwright MCP) for UI changes — N/A, no UI changed
- [x] All tests pass (`vendor/bin/phpunit --filter CohortGroupProvisioningHandlerTest`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation updated in `docs/` — N/A, no new user-facing surface
- [ ] Screenshot captured and committed to `docs/images/` — N/A, no visual change

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A,
  no new user-facing strings (a backend listener, no notification text)
