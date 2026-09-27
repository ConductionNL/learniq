# Design: lesson-player-runtime

## Context

`Lesson.contentType` (`lib/Settings/learniq_register.json`) declares `scorm12`/`scorm2004`/`cmi5` as valid
values, and `Lesson.contentRef` is documented as "nc:files path or cmi5 launch URL for those content types."
`LessonPlayer.vue` renders `isTextLesson`/`isLtiLesson` branches and falls through to a placeholder
(`lesson-player__placeholder`) for everything else — including every SCORM/cmi5 lesson. `XapiCompletionHandler`
already listens for `http://adlnet.gov/expapi/verbs/completed`/`.../passed` on `xapi-statement` objects and
transitions an `Enrolment` to completed; the missing piece is a producer of those statements from an actual
SCORM/cmi5 player, not the consumer.

## Goals / Non-Goals

**Goals:** a real, spec-shaped SCORM 1.2 `window.API` runtime; a cmi5 launch-parameter builder; both wired
into `LessonPlayer.vue`; xAPI statements posted on completion through the existing object-create path.

**Non-Goals:** SCORM 2004, a content-hosting/extraction backend, the `cmi5-xapi-lrs-ingest` LRS ingest
controller/launch-token service (sibling change), relaxing `xapi-statement`'s authorization.

## Decisions

### Decision 1: `scorm12Runtime.js` is a factory returning a plain object, not a class instance mutating `window` itself

**Alternatives considered:** a class that self-registers on `window.API` in its constructor. Rejected: a
factory returning `{ LMSInitialize, LMSFinish, ... }` lets `LessonPlayer.vue` decide exactly when to assign
`window.API = shim` (right before the iframe mounts) and `delete window.API` on unmount/navigation-away,
without the module reaching into global state itself — easier to unit-test (call the returned functions
directly) and avoids leaking a global across lessons if a learner navigates between two SCORM lessons without
a full page reload.

### Decision 2: The CMI data model is a flat key-value map (SCORM 1.2's actual wire format), not a nested object

SCORM 1.2's `LMSGetValue`/`LMSSetValue` calls pass dotted string keys (`cmi.core.lesson_status`,
`cmi.core.score.raw`, `cmi.suspend_data`, ...) — matching that wire format with a `Map<string,string>` keyed
by the exact dotted name (rather than a nested JS object requiring path-parsing) is both simpler and exactly
what the spec's own function signatures already expect.

### Decision 3: Completion is detected on `LMSSetValue('cmi.core.lesson_status', <terminal-status>)` or `LMSFinish()`, whichever fires first

A conforming SCORM package always calls `LMSSetValue('cmi.core.lesson_status', 'completed'|'passed'|'failed')`
before `LMSFinish()`, but some packages set the status then never explicitly signal again beyond `LMSFinish`.
Firing the xAPI POST the moment a terminal status is `LMSSetValue`'d (rather than waiting for `LMSFinish`)
means a package that crashes or is closed by the learner immediately after setting status still gets its
completion recorded — matches the "record early, not late" posture already used by
`AttendanceFlag.x-openregister-notifications` (fires on the calculated-change, not a later batch job).

### Decision 4: Content URL resolution — OpenRegister's generic object-files endpoint, documented as unverified

**Alternatives considered:** (a) treat `contentRef` as a raw NC WebDAV path and build a
`/remote.php/dav/files/{owner}/...` URL — rejected: requires knowing which NC user owns the file, which
`Lesson`/`Material` do not record, and WebDAV auth is per-owner, not per-enrolled-learner (would 401 for a
learner who is not the uploader). (b) invent a new backend controller to unzip and serve SCORM content —
rejected as backend work beyond this change's "runtime in `LessonPlayer.vue`" scope (see proposal Out of
Scope). **Chosen:** call OpenRegister's already-routed generic per-object files convention (`GET /api/
objects/{register}/{schema}/{id}/files/...`, confirmed present in `openregister`'s own `appinfo/routes.php`
as `files#show`/`objects#downloadFiles`), isolated to one `resolveContentUrl(lesson)` method so a wrong guess
here costs one method, not the SCORM/cmi5 runtime logic itself.

## Declarative-vs-imperative decision (ADR-031)

The SCORM 1.2 API shim and cmi5 launch-parameter builder are legitimately imperative per ADR-031's
"External-system contract — SDK/API bridge that must be expressed in PHP [or JS]" exception (the same
rationale `lib/Proctoring/ProvidesProctoring.php`'s own docblock already cites for a comparable third-party
protocol bridge): SCORM's `window.API` object is a fixed, external JavaScript calling convention a packaged
SCO invokes directly — it cannot be expressed as a declarative OpenRegister annotation. No new backend
lifecycle/aggregation/notification is introduced.

## Seed Data

Not applicable — no schema change in this pass (Lesson/XapiStatement already declare everything needed).

## Risks / Trade-offs

- [Risk] Content URL resolution unverified (Decision 4). → Mitigation: isolated to one method; see proposal
  Risk 1.
- [Risk] `xapi-statement` create is admin-only until the sibling change ships (see proposal Risk 2). →
  Mitigation: fail-soft, log-loud posture; the learner's playback experience is unaffected.
- [Risk] The cmi5 launch endpoint this change calls does not exist yet (sibling change, 0/19 tasks). →
  Mitigation: a 404/503 response renders a clear "cmi5 playback is not yet available for this lesson" empty
  state rather than a crash or an infinite spinner.

## Migration Plan

Not applicable — no schema or data change; `migration.md` is skipped per its own `skipWhen` condition.

## Open Questions

- Content URL resolution convention (Decision 4) — flagged for confirmation once a course-package
  hosting/serving mechanism is built.
- Live verification against a real SCORM 1.2 package and a real cmi5 launch — deferred, see proposal.
