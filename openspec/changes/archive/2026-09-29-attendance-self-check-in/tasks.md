# Tasks: attendance-self-check-in

## Implementation tasks

### Task 1: Register: CheckInWindow and AttendanceRecord.markedVia
- **spec_ref**: `specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson`
- **files**: `lib/Settings/learniq_register.json` (new CheckInWindow with lifecycle, aggregation and authorization; AttendanceRecord 0.3.0; `info.version` bump)
- **acceptance_criteria**:
  - GIVEN a learner WHEN they list check-in windows through the object API THEN nothing comes back
- [x] Implement
- [x] Test: `tests/Unit/Settings/SelfCheckInRegisterTest.php`; `npm run check:register`, `npm run check:json-strict`
- No `checkInCount` aggregation: the count comes from `GET /api/check-in/{id}/code` (CheckInCodeController), counting records with `markedVia: self-check-in`.

### Task 2: Code service
- **spec_ref**: `specs/attendance/spec.md#requirement-the-check-in-code-changes-every-thirty-seconds-in-the-room`
- **files**: `lib/Service/CheckInCodeService.php`
- **acceptance_criteria**:
  - GIVEN a code from two steps ago WHEN verified THEN it is refused; the current and previous step pass
- [x] Implement
- [x] Test: `tests/Unit/Service/CheckInCodeServiceTest.php` with a fixed clock
- Test is `tests/Unit/Service/CheckIn/CheckInServiceTest.php` (code tests with a fixed clock sit there). The HMAC key is a learniq secret in app config (`check_in_code_secret`), not the instance secret: the open point of design.md, decided.

### Task 3: Check-in endpoint
- **spec_ref**: `specs/attendance/spec.md#requirement-a-learner-checks-in-with-the-code`, `#requirement-a-self-check-in-never-overwrites-a-mark`
- **files**: `lib/Controller/CheckInController.php`, `lib/Service/CheckInService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN each refusal case of design.md WHEN posted THEN the plain reason comes back and no record is written
- [x] Implement
- [x] Test: `tests/Unit/Controller/CheckInControllerTest.php`; hydra gates 5, 7 and 30
- Also `POST /api/check-in` with only the code (the learner needs no link), `GET /api/check-in` (open check-ins of the learner's lessons, never the code), and the portal receiver `POST /api/portal/check-in` (PortalCheckInController, pattern of #1096 and #1142) with the `checkIn` action in the student contribution. Tests: `CheckInServiceTest`, `PortalCheckInControllerTest`.

### Task 4: Register screen and learner page
- **spec_ref**: `specs/attendance/spec.md#requirement-a-teacher-opens-a-self-check-in-window-for-a-lesson`, `#requirement-a-learner-checks-in-with-the-code`
- **files**: `src/views/AttendanceRegisterView.vue`, `src/views/CheckInPage.vue`, `src/manifest.d/learning.json`, `src/registry.js`
- **acceptance_criteria**:
  - GIVEN a self check-in row WHEN the teacher changes its status and saves THEN the teacher's status is stored and `markedVia` becomes `teacher`
- [x] Implement
- [x] Test: Playwright `tests/e2e/self-check-in.spec.ts` (open window, learner checks in, teacher sees the row)
  - r5-live, 2026-09-29, shared dev instance: `tests/e2e/self-check-in.spec.ts`. The teacher opens self check-in on the register; a temporary learner in the class checks in with the board code on /check-in; the AttendanceRecord has `markedVia: self-check-in` and status `present`, and the register row shows "checked in". 1 passed (#1436).
- The board shows the code in large letters and, for an online lesson, the link. No QR image: that needs a new frontend dependency and this lane changes no lockfile. `CheckInPage` is at `/check-in` (the link carries `?window=&code=`). Saving the register leaves an untouched self check-in alone (`registerRowsToSave`, unit-js test). Playwright test not written: no live instance in this lane.

### Task 5: Seed data and translations
- **files**: `lib/Settings/learniq_mock_register.json` or the VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate attendance-self-check-in --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
- Three CheckInWindow rows and `markedVia` on the AttendanceRecord rows in the mock register; the VO example set generator is not extended.

