## MODIFIED Requirements

### Requirement: Run cmi5 + xAPI natively with SCORM shim

The system MUST run cmi5 + xAPI content natively and SHOULD provide a SCORM 1.2/2004 compatibility shim.

This requirement splits into two halves, tracked separately because they are built separately (finding 5.6
found the player half unbuilt while `Lesson.contentType`/`XapiStatement` already existed):

- **Ingest half** (LRS ingest endpoint, cmi5 launch-token minting, `xapi-statement` authorization): owned by
  the sibling `cmi5-xapi-lrs-ingest` change (0/19 tasks at the time this change was authored) — NOT built by
  this change.
- **Player half** (this change): `src/utils/scorm12Runtime.js` provides a SCORM 1.2 `window.API` runtime (the
  8-function RTE3 surface) over an in-memory CMI data model, wired into `LessonPlayer.vue` for
  `contentType: scorm12` lessons. `src/utils/cmi5Launch.js` provides the cmi5 AU launch-URL builder, wired
  into `LessonPlayer.vue` for `contentType: cmi5` lessons — functional once the sibling change's launch-token
  endpoint exists; until then, a graceful empty state renders instead of a crash. `scorm2004` remains
  unbuilt (SHOULD, not MUST, and a materially larger data model — explicitly out of scope this pass).

#### Scenario: Run cmi5/xAPI content with SCORM fallback

- **GIVEN** a lesson backed by a content package
- **WHEN** a learner launches the lesson
- **THEN** the system runs cmi5 + xAPI content natively
- **AND** it runs SCORM 1.2/2004 packages through the compatibility shim

#### Scenario: A SCORM 1.2 package's completion status produces a recognised xAPI statement

<!-- @e2e exclude no local Nextcloud instance was exercised for this change (see proposal Open Questions);
     the SCORM 1.2 API shim's completion-to-xAPI mapping is covered by
     tests/unit-js/scorm12Runtime.test.mjs. A live browser verification pass against a real SCORM 1.2
     package is a named follow-up, not silently skipped. -->

- **GIVEN** a `Lesson` with `contentType: "scorm12"` and a learner has launched it
- **WHEN** the package calls `LMSSetValue('cmi.core.lesson_status', 'completed')` (or `'passed'`)
- **THEN** an xAPI statement is built with `verb.id` equal to `http://adlnet.gov/expapi/verbs/completed` or
  `.../passed` — the same IRIs `XapiCompletionHandler` already recognises
- **AND** the statement is POSTed via the existing OpenRegister object-create endpoint for `xapi-statement`

#### Scenario: A cmi5 lesson gracefully degrades until the sibling ingest change ships

- **GIVEN** a `Lesson` with `contentType: "cmi5"` and no cmi5 launch-token endpoint exists yet (the sibling
  `cmi5-xapi-lrs-ingest` change is not merged)
- **WHEN** a learner opens the lesson
- **THEN** `LessonPlayer.vue` shows a clear "cmi5 playback is not yet available for this lesson" empty state
- **AND** no unhandled error or infinite loading spinner is shown
