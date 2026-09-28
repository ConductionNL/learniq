## Implementation Tasks

### Task 1: Build the SCORM 1.2 API shim + CMI data model
- **spec_ref**: `openspec/specs/course-management/spec.md#requirement-a-scorm-12-window-api-runtime-exists`
- **files**: `src/utils/scorm12Runtime.js`
- **acceptance_criteria**:
  - GIVEN `createScorm12Api()` WHEN called THEN it returns an object with all 8 SCORM 1.2 functions
    (`LMSInitialize`, `LMSFinish`, `LMSGetValue`, `LMSSetValue`, `LMSCommit`, `LMSGetLastError`,
    `LMSGetErrorString`, `LMSGetDiagnostic`)
  - GIVEN `LMSSetValue('cmi.core.lesson_status', 'completed')` WHEN called THEN a subsequent
    `LMSGetValue('cmi.core.lesson_status')` returns `'completed'` and the shim's completion callback fires
    exactly once
- [x] Implement
- [x] Test

### Task 2: Map SCORM completion to an xAPI statement shape
- **spec_ref**: `openspec/specs/course-management/spec.md#requirement-a-scorm-12-window-api-runtime-exists`
- **files**: `src/utils/scorm12Runtime.js`
- **acceptance_criteria**:
  - GIVEN a terminal `lesson_status` of `completed`/`passed`/`failed` WHEN mapped THEN the resulting
    statement's `verb.id` is `http://adlnet.gov/expapi/verbs/completed` or `.../passed` — the same IRIs
    `XapiCompletionHandler` already recognises — never a third, unrecognised IRI
- [x] Implement
- [x] Test

### Task 3: Build the cmi5 launch-parameter builder
- **spec_ref**: `openspec/specs/course-management/spec.md#requirement-a-cmi5-launch-url-builder-exists`
- **files**: `src/utils/cmi5Launch.js`
- **acceptance_criteria**:
  - GIVEN a launch-token response shape (`{ endpoint, fetchUrl, actor, activityId, registration }`) WHEN
    `buildCmi5LaunchUrl(...)` is called THEN it returns a URL with all five cmi5-spec query parameters
    correctly encoded
- [x] Implement
- [x] Test

### Task 4: Unit-test both utility modules
- **spec_ref**: `openspec/specs/course-management/spec.md#requirement-a-scorm-12-window-api-runtime-exists`
- **files**: `tests/unit-js/scorm12Runtime.test.mjs`, `tests/unit-js/cmi5Launch.test.mjs`
- **acceptance_criteria**:
  - GIVEN `node --test tests/unit-js/scorm12Runtime.test.mjs tests/unit-js/cmi5Launch.test.mjs` WHEN run
    THEN every test passes
- [x] Implement
- [x] Test

### Task 5: Wire the SCORM 1.2 branch into LessonPlayer.vue
- **spec_ref**: `openspec/changes/lesson-player-runtime/design.md#decision-4-content-url-resolution--openregisters-generic-object-files-endpoint-documented-as-unverified`
- **files**: `src/views/LessonPlayer.vue`
- **acceptance_criteria**:
  - GIVEN `lesson.contentType === 'scorm12'` WHEN the lesson renders THEN an iframe mounts with `window.API`
    assigned before load, and `window.API` is cleared on unmount
  - GIVEN the shim signals completion WHEN it fires THEN an xAPI statement is POSTed via the existing
    OpenRegister object-create endpoint for `xapi-statement`, and a POST failure (e.g. 403, pending the
    sibling `cmi5-xapi-lrs-ingest` change) is caught and logged, never shown as a blocking error
- [x] Implement
- [x] Test

### Task 6: Wire the cmi5 branch into LessonPlayer.vue
- **spec_ref**: `openspec/changes/lesson-player-runtime/design.md#decision-4-content-url-resolution--openregisters-generic-object-files-endpoint-documented-as-unverified`
- **files**: `src/views/LessonPlayer.vue`
- **acceptance_criteria**:
  - GIVEN `lesson.contentType === 'cmi5'` WHEN the lesson renders THEN the player requests a launch token and,
    on success, opens an iframe built via `buildCmi5LaunchUrl`
  - GIVEN the launch-token request 404s/503s (the sibling change has not shipped it yet) WHEN that happens
    THEN a clear "cmi5 playback is not yet available for this lesson" empty state renders, not a crash or an
    infinite spinner
- [x] Implement
- [x] Test

## Verification

- All tasks checked off
- `openspec validate lesson-player-runtime --strict` passes
- `node --test tests/unit-js/scorm12Runtime.test.mjs tests/unit-js/cmi5Launch.test.mjs` passes
- `git diff` reviewed against every spec requirement before commit

## Tests (company-wide ADR-009)

- PHPUnit: N/A — no PHP touched by this change
- Newman/Postman: N/A — no new route
- Browser (Playwright MCP): deferred this pass — no local instance exercised (see proposal Open Questions);
  the utility-module unit tests are the primary verification for this iteration
- `composer check:strict` (no PHP touched) and `npm run lint` run once before push per CLAUDE.md

## Documentation (company-wide ADR-010)

- N/A this pass — the course-management capability's own spec is updated (Modified Capabilities); no
  separate `docs/` feature page exists for the content-runtime sub-feature to extend

## i18n (company-wide ADR-005)

- New user-facing strings (the cmi5 "not yet available" empty state) go through `t('learniq', ...)`, matching
  every other `LessonPlayer.vue` string; no hardcoded literal bypasses translation
