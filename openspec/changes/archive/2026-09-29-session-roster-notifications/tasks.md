# Tasks: session-roster-notifications

## Implementation Tasks

### Task 1: Declare Session.x-openregister-notifications.rosterChanged
- **spec_ref**: `openspec/changes/session-roster-notifications/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents`
- **files**: `lib/Settings/learniq_register.json`
- **acceptance_criteria**:
  - GIVEN the `Session` schema WHEN `x-openregister-notifications.rosterChanged`
    is read THEN its `trigger` is `{"type": "transition", "action": ["cancel",
    "substitute-teacher", "substitute-teacher-in-progress"]}`
  - GIVEN the same block WHEN `recipients` is read THEN it is
    `[{"kind": "field", "field": "affectedLearnerIds"}, {"kind": "field",
    "field": "affectedParentIds"}]` and `channels` is `["nc-notification"]`
  - GIVEN the register's top-level `info.version` WHEN compared to its
    pre-change value THEN it has been bumped
- [x] Implement
- [x] Test

### Task 2: Add the register-JSON assertion test
- **spec_ref**: `openspec/changes/session-roster-notifications/specs/timetabling/spec.md#requirement-cancellation-or-substitution-notifies-affected-learners-and-parents`
- **files**: `tests/Unit/Settings/SessionRosterNotificationRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN `lib/Settings/learniq_register.json` decoded WHEN the test runs THEN
    it asserts the `Session.x-openregister-notifications.rosterChanged`
    trigger type, action list, recipient field names, and channel list
  - GIVEN the same decoded register WHEN the test runs THEN it asserts
    `info.version` is at least the version this change bumps to
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) —
  `SessionRosterNotificationRegisterTest`
- [x] Newman/Postman tests for new/changed API endpoints — N/A, no API changed
  - N/A, confirmed in r5-structure: a28be1fa (#909) changes only `lib/Settings/learniq_register.json` and a register test; no API.
- [x] Browser tests (Playwright MCP) for UI changes — N/A, no UI changed;
  end-to-end notification delivery is OpenRegister platform behaviour,
  covered by the nightly matrix, not per-PR
  - N/A for UI, confirmed in r5-structure: a28be1fa changes nothing under `src/`. Live delivery of the notification is OpenRegister behaviour and was not exercised by this lane.
- [x] All tests pass (`vendor/bin/phpunit --filter SessionRosterNotificationRegisterTest`)

## Documentation (company-wide ADR-010)
- [x] Feature documentation updated in `docs/` — N/A, no new user-facing
  surface; the `timetabling` spec already documented this behaviour as
  intended, this change makes the declaration match the spec
  - N/A, confirmed in r5-structure: no new page or setting.
- [x] Screenshot captured and committed to `docs/images/` — N/A, no visual
  change
  - N/A, confirmed in r5-structure: nothing visual changed.

## i18n (company-wide ADR-005)
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A,
  the notification subject is declared inline in the register JSON
  (`nl`/`en` keys), not routed through `l10n/*.json`, matching every other
  notification subject in this register
  - N/A, confirmed in r5-structure: the `rosterChanged` subject is declared inline in the register with `nl` (Je rooster is gewijzigd) and `en` (Your timetable has changed) values.
