# Tasks: timetabling-bulk-change-weeks

## Implementation tasks

### Task 1: Register: batch schema, Session.changeBatchId, notification condition
- **spec_ref**: `specs/timetabling/spec.md#requirement-affected-people-get-one-message-per-batch`
- **files**: `lib/Settings/learniq_register.json` (new schema with notification; Session 0.2.0; `info.version` bump)
- [x] Implement
- [x] Test: `tests/Unit/Settings/SessionChangeBatchRegisterTest.php`, `tests/Unit/Listener/SessionChangeNoticeHandlerTest.php` (a lesson in a batch writes nothing); gate 18

### Task 2: Series lookup and batch service with route
- **spec_ref**: `specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks`, `#requirement-every-lesson-in-a-batch-passes-the-same-checks`
- **files**: `lib/Service/SessionChangeBatchService.php`, `lib/Timetabling/SessionSeries.php`, `lib/Timetabling/SessionChangeInput.php`, `lib/Controller/SessionChangeBatchController.php`, `appinfo/routes.php`, `lib/actions.seed.json` (`timetable.bulk-change`)
- **acceptance_criteria**:
  - GIVEN a completed lesson in the batch WHEN applied THEN it is refused with the guard's reason and the others are applied
- [x] Implement
- [x] Test: `tests/Unit/Service/SessionChangeBatchServiceTest.php` (guard runs per lesson as the caller), `tests/Unit/Controller/SessionChangeBatchControllerTest.php`; hydra gates 5, 7, 30

### Task 3: Dialog
- **spec_ref**: `specs/timetabling/spec.md#requirement-a-coordinator-applies-one-change-to-several-weeks`
- **files**: `src/dialogs/SubstitutionModal.vue`
- [x] Implement
- [x] Test: Playwright `tests/e2e/spec-coverage/timetabling-bulk-change-weeks.spec.ts` (cancel with more weeks, see the result list); written, not run: no instance in this lane

### Task 4: Seed data and translations
- **files**: VO example set generator, `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`
- [x] Implement
- [x] Test: gate 101, `npm run check:schema-l10n`, `npm run check:l10n-js`

## Verification
- `openspec validate timetabling-bulk-change-weeks --strict` passes
- `composer check:strict` and `npm run lint` show no new finding against the development baseline
