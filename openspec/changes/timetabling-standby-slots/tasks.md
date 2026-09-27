# Tasks: timetabling-standby-slots

## Implementation tasks

### Task 1: Register: StandbySlot
- **spec_ref**: `specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours`
- **files**: `lib/Settings/learniq_register.json` (new schema, `info.version` bump)
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/StandbySlotRegisterTest.php`

### Task 2: Candidate service and route
- **spec_ref**: `specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first`
- **files**: `lib/Service/SubstitutionCandidateService.php`, `lib/Controller/SubstitutionCandidateController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a standby teacher with a lesson at that time WHEN candidates are listed THEN that teacher is last with "has a lesson then"
  - GIVEN the absent teacher WHEN candidates are listed THEN they are not in the list
- [ ] Implement
- [ ] Test: `tests/Unit/Service/SubstitutionCandidateServiceTest.php`; hydra gates 5, 7, 30

### Task 3: Substitution dialog
- **spec_ref**: `specs/timetabling/spec.md#requirement-the-substitution-dialog-offers-standby-teachers-first`
- **files**: `src/dialogs/SubstitutionModal.vue`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/standby.spec.ts` (standby teacher listed first, chosen, lesson shows the substitute); gate NcSelect input labels

### Task 4: Standby planning page and teacher timetable
- **spec_ref**: `specs/timetabling/spec.md#requirement-a-coordinator-plans-standby-hours`
- **files**: `src/views/StandbyPlanning.vue`, `src/manifest.d/learning.json`, `src/registry.js`, `lib/Controller/TimetableController.php`, `lib/Service/TimetableProjector.php`, `src/views/MyTimetable.vue`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/standby.spec.ts` (coordinator adds a slot, teacher sees it in their week)

### Task 5: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-standby-slots --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
