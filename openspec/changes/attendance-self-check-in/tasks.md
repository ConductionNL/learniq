# Tasks: attendance-self-check-in

## Implementation tasks

### Task 1: Register: CheckInWindow and AttendanceRecord.markedVia
- **spec_ref**: `specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson`
- **files**: `lib/Settings/learniq_register.json` (new CheckInWindow with lifecycle, aggregation and authorization; AttendanceRecord 0.3.0; `info.version` bump)
- **acceptance_criteria**:
  - GIVEN a learner WHEN they list check-in windows through the object API THEN nothing comes back
- [ ] Implement
- [ ] Test: `tests/Unit/Settings/SelfCheckInRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`

### Task 2: Code service
- **spec_ref**: `specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room`
- **files**: `lib/Service/CheckInCodeService.php`
- **acceptance_criteria**:
  - GIVEN a code from two steps ago WHEN verified THEN it is refused; the current and previous step pass
- [ ] Implement
- [ ] Test: `tests/Unit/Service/CheckInCodeServiceTest.php` with a fixed clock

### Task 3: Check-in endpoint
- **spec_ref**: `specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code`, `#requirement-a-self-check-in-never-overwrites-a-mark`
- **files**: `lib/Controller/CheckInController.php`, `lib/Service/CheckInService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN each refusal case of design.md WHEN posted THEN the plain reason comes back and no record is written
- [ ] Implement
- [ ] Test: `tests/Unit/Controller/CheckInControllerTest.php`; hydra gates 5, 7 and 30

### Task 4: Register screen and learner page
- **spec_ref**: `specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson`, `#requirement-a-learner-checks-in-with-the-code`
- **files**: `src/views/AttendanceRegisterView.vue`, `src/views/CheckInPage.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN a self check-in row WHEN the teacher changes its status and saves THEN the teacher's status is stored and `markedVia` becomes `teacher`
- [ ] Implement
- [ ] Test: Playwright `tests/e2e/self-check-in.spec.ts` (open window, learner checks in, teacher sees the row)

### Task 5: Seed data and translations
- **files**: `lib/Settings/learniq_mock_register.json` or the VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [ ] Implement
- [ ] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate attendance-self-check-in --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
