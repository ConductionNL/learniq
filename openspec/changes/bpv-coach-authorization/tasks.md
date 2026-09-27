# Tasks: bpv-coach-authorization

## Implementation Tasks

### Task 1: Add the authorization blocks
- **spec_ref**: `openspec/changes/bpv-coach-authorization/specs/bpv/spec.md#requirement-bpvplacement-access-is-enforced-with-the-school-coach-and-the-learner-as-scopes`, `#requirement-praktijkopleider-access-is-enforced-for-the-bpv-staff-groups`
- **files**: `lib/Settings/learniq_register.json` (`BpvPlacement.authorization`, `Praktijkopleider.authorization`, both `version`s 0.1.0 to 0.2.0, `info.version`, changelog sentence)
- **acceptance_criteria**:
  - GIVEN `BpvPlacement.authorization.read` WHEN read THEN it lists the six groups, a `schoolCoachId` self-match and a `learnerId` self-match
  - GIVEN `Praktijkopleider.authorization.read` WHEN read THEN it lists the six groups and no match entry
  - GIVEN either block WHEN read THEN create and update list the five writer groups and there is no `delete` key
- [x] Implement
- [x] Test

### Task 2: Register tests
- **spec_ref**: all requirements in `specs/bpv/spec.md` above
- **files**: `tests/Unit/Settings/BpvCoachAuthorizationRegisterTest.php` (new)
- **acceptance_criteria**:
  - GIVEN the new test WHEN run THEN it pins both blocks exactly, and asserts every group is declared and every matched field is a property
  - GIVEN `tests/Unit/Register/` WHEN run THEN this change adds no failure
- [x] Implement
- [x] Test

## Quality checklist
- No PHP business logic changes; register tests cover the blocks.
- No endpoint, page or string change, so no Newman, browser or i18n work.
- `openspec validate bpv-coach-authorization` passes.
