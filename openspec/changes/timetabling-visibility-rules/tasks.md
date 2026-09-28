# Tasks: timetabling-visibility-rules

## Implementation tasks

### Task 1: Move learner-facing session reads to endpoints
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-the-api-follows-the-same-line`
- **files**: every read of `session` in `src/` and `lib/` found by `git grep -n "'session'"` (at least `src/views/CohortTimetableView.vue:60`)
- [x] Implement (reads found: `CohortTimetableView.vue` now reads through `GET /api/timetable/of?kind=cohort`; `AttendanceRegisterView.vue` is a teacher screen and keeps its object read; in `lib/`, `LocalSessionTimetableSource` now reads without the caller's RBAC, because the timetable endpoints decide access before they ask it, and the other readers (`SessionWindowLoader`, `AttendanceWindowAggregator`, `PeerReviewController`, the listeners) run for staff or as the system. The live check on an instance was not run: build lanes may not touch the shared instance. The `Session` block names exactly the groups of the register-level `authorization.roles` rule, so under either reading of OpenRegister's fallback the object API stays staff-only and nothing staff reads today is lost)
- [x] Test: the list of reads with their new path is in the PR body; `TimetableVisibilityRegisterTest::testSessionReadIsStaffOnly`; the live learner listing was not run (instance off limits), see above

### Task 2: Register: policy and Session authorization
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-school-sets-whose-timetables-each-role-may-see`, `#requirement-the-api-follows-the-same-line`
- **files**: `lib/Settings/learniq_register.json` (new policy schema, `Session` 0.2.0 authorization, `info.version` bump)
- [x] Implement (TimetableVisibilityPolicy 0.1.0, Session 0.1.2 to 0.2.0, info.version 0.32.0)
- [x] Test: `tests/Unit/Settings/TimetableVisibilityRegisterTest.php`; the register ratchet tests stay at their current set

### Task 3: Endpoint and options
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows`
- **files**: `lib/Controller/TimetableController.php` (`of`, `ofOptions`), `lib/Service/TimetableVisibilityService.php`, `appinfo/routes.php`
- [x] Implement (in its own `lib/Controller/TimetableVisibilityController.php`, methods `timetable` and `options` at the same URLs `/api/timetable/of` and `/api/timetable/of/options`, plus `GET /api/timetable/visibility-policy`; reads in `lib/Service/TimetableDirectory.php`; `TimetableController` is at its coupling limit)
- [x] Test: `tests/Unit/Controller/TimetableVisibilityControllerTest.php`, `tests/Unit/Timetabling/Source/LocalSessionTimetableSourceTest.php` as well; `tests/Unit/Service/TimetableVisibilityServiceTest.php` (each policy value for each role); hydra gates 5, 7, 30

### Task 4: Timetables page, week component, settings section
- **spec_ref**: `specs/personal-timetable/spec.md#requirement-a-user-opens-another-timetable-the-school-allows`
- **files**: `src/views/Timetables.vue`, `src/components/TimetableWeek.vue`, `src/views/MyTimetable.vue`, `src/views/CohortTimetableView.vue`, `src/manifest.d/learning.json`, `src/registry.js`, the settings page
- [x] Implement (`src/views/TimetableLookup.vue` at `/timetables`, menu entry under My learning; the page shows the week as a day list rather than extracting MyTimetable's grid into a shared component, so MyTimetable is untouched; the policy is edited on the same page by team leads and compliance officers, because the learniq settings page is admin only)
- [x] Test: Playwright `tests/e2e/timetable-visibility.spec.ts` (a teacher timetable opens, an unknown kind is refused; written, not run in this lane; the learner rules are in `TimetableVisibilityServiceTest`)

### Task 5: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-visibility-rules --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
