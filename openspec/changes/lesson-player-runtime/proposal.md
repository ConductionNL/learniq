---
kind: code
depends_on: []
---

# Proposal: lesson-player-runtime

## Summary

`Lesson.contentType` declares `scorm12`, `scorm2004`, and `cmi5` as valid content types, but
`src/views/LessonPlayer.vue` has no rendering branch for any of them — a lesson with one of these content
types falls through to the generic "Lesson content not available" placeholder (finding 5.6). This change adds
a real SCORM 1.2 JavaScript runtime (the classic 8-function `window.API` object + an in-memory CMI data
model) and a cmi5 launch-parameter builder, both as pure, unit-tested utility modules, wired into
`LessonPlayer.vue` behind new `isScorm12Lesson`/`isCmi5Lesson` branches, completing on either path by posting
an xAPI statement through the existing (currently admin-gated) `xapi-statement` OpenRegister object path.

## Motivation

Finding 5.6 ("Content runtime: SCORM, cmi5, xAPI player") rates learniq "partial": `Lesson.contentType`
declares `scorm12`/`scorm2004`/`cmi5`, xAPI statements are stored, but "no SCORM/cmi5 runtime in
`src/views/LessonPlayer.vue`" — confirmed by reading the file: `MANUAL_COMPLETION_CONTENT_TYPES` and a code
comment both reference "the xAPI-sourced path" for these content types, but the template has zero branches
matching `contentType === 'scorm12'` or `'cmi5'`; every such lesson renders the generic placeholder at
`LessonPlayer.vue:240-253`. The finding's own note names the relevant open change:
`cmi5-xapi-lrs-ingest (open change, 0/19 tasks) covers ingest; the player itself is still the gap` — this
change is exactly that named, deferred frontend follow-up (that sibling change's own `tasks.md` task 5.2
explicitly defers "Wire the frontend Lesson player" as "a separate, explicitly out-of-scope follow-up" when
"no such Vue surface exists yet").

## Affected Projects

- [x] Project: `learniq` — `src/utils/scorm12Runtime.js`, `src/utils/cmi5Launch.js` (new, unit-tested), and
  `src/views/LessonPlayer.vue` (new template branches).

## Scope

### In Scope

- `src/utils/scorm12Runtime.js`: a pure factory building the SCORM 1.2 `window.API` object's 8 required
  functions (`LMSInitialize`, `LMSFinish`, `LMSGetValue`, `LMSSetValue`, `LMSCommit`, `LMSGetLastError`,
  `LMSGetErrorString`, `LMSGetDiagnostic`) over an in-memory CMI data model, plus a pure function mapping a
  terminal `cmi.core.lesson_status` (`completed`/`passed`/`failed`) to an xAPI statement shape using the same
  verb IRIs `XapiCompletionHandler` already recognises (`http://adlnet.gov/expapi/verbs/completed`,
  `.../passed`).
- `src/utils/cmi5Launch.js`: a pure function building a cmi5 AU launch URL (`endpoint`, `fetch`, `actor`,
  `activityId`, `registration` query parameters, per the cmi5 spec) from a launch-token response shape.
- `LessonPlayer.vue`: `isScorm12Lesson`/`isCmi5Lesson` computed properties and template branches rendering an
  iframe once a content URL resolves; mounting the SCORM API shim on `window` before the scorm12 iframe
  loads; posting the resulting xAPI statement via the existing generic OpenRegister object-create endpoint
  for `xapi-statement`.
- Unit tests for both utility modules (`node --test`, matching `courseOrder.test.mjs`'s convention).

### Out of Scope (explicit, with reasons)

- **`scorm2004` runtime.** The brief scopes this change to "SCORM 1.2 and cmi5" only; SCORM 2004's data
  model (`cmi.completion_status`/`cmi.success_status`/sequencing rules) is a materially different, larger
  surface than SCORM 1.2's flat model and is not attempted here.
- **A content-hosting/serving endpoint for extracted SCORM/cmi5 packages.** Grepped `lib/Controller/*.php`
  and `appinfo/routes.php`: no controller or route resolves `Lesson.contentRef` (an nc:files path, per the
  schema's own description) into a browsable URL today — this is backend infrastructure work, not "the
  runtime in `src/views/LessonPlayer.vue`" as scoped. This change resolves `contentRef` via OpenRegister's
  existing generic object-files convention (`GET /api/objects/{register}/{schema}/{id}/files/...`,
  `appinfo/routes.php` in the `openregister` app) as the most plausible integration point given the evidence,
  documented as an explicit open question — see design.md Decision 4 and Open Questions.
- **`Cmi5LaunchTokenService`/`LrsController`.** Both belong to the sibling, still-open `cmi5-xapi-lrs-ingest`
  change (0/19 tasks at the time of writing) — this change does not fork or duplicate that work. The cmi5
  launch call this change makes will 404/503 until that change ships the launch-token endpoint; the player
  handles that gracefully (an empty state, not a crash), per that change's own task 5.2 naming this exact
  follow-up as separate.
- **Relaxing `xapi-statement`'s admin-only create authorization.** Also owned by `cmi5-xapi-lrs-ingest`
  (its task 4.1). Until that lands, a learner's own SCORM completion POST will receive a 403 — handled
  gracefully (logged, not surfaced as a blocking error to the learner), not silently pretended to succeed.

## Approach

The novel logic (the SCORM 1.2 API shim's state machine, the cmi5 launch URL builder) lives in two pure,
directly unit-testable ES modules with no DOM/Vue dependency, mirroring `src/utils/courseOrder.js`'s
established pattern. `LessonPlayer.vue` only wires them: mounts the shim, renders the iframe, and posts the
resulting statement.

## Capabilities

### Modified Capabilities

- `course-management` — the "Run cmi5 + xAPI natively with SCORM shim" requirement's player half moves from
  a placeholder to an actual SCORM 1.2 window.API runtime + cmi5 launch orchestration, completing the split
  the sibling `cmi5-xapi-lrs-ingest` change's own proposal already named (ingest vs. player).

## New Dependencies

None — no SCORM/cmi5 npm package is added; the runtime is hand-written per the SCORM 1.2 RTE3 spec's 8-call
surface (a small, closed contract), matching this app's existing convention of hand-rolling small protocol
shims (e.g. `CredentialVerifyController::verifyJwsSignature` for JWS, per that class's own docblock) rather
than importing a library for a narrow, stable spec.

## Impact

- `src/utils/scorm12Runtime.js` (new).
- `src/utils/cmi5Launch.js` (new).
- `src/views/LessonPlayer.vue` — new computed properties, template branches, methods.
- `tests/unit-js/scorm12Runtime.test.mjs`, `tests/unit-js/cmi5Launch.test.mjs` (new).

## Cross-Project Dependencies

None directly, but this change's cmi5 branch and its xAPI-statement POST only become fully functional once
the sibling `cmi5-xapi-lrs-ingest` change ships (see Out of Scope). This is a soft, documented dependency, not
a hard blocker on merging this change — the SCORM 1.2 path is independently functional up to the same
authorization gate every other xAPI producer in this app faces today.

## Risks

### Risk 1: The content-hosting URL resolution (Decision 4) is unverified against how SCORM/cmi5 packages are actually stored

**Severity:** Medium — **Mitigation:** isolated to one method (`resolveContentUrl()`); the SCORM 1.2 API
shim and cmi5 launch-parameter builder (the substantial, novel logic this change adds) do not depend on which
URL-resolution convention turns out to be correct, and are independently unit-tested without it.

### Risk 2: A learner's SCORM completion POST will 403 until `cmi5-xapi-lrs-ingest`'s authorization relaxation ships

**Severity:** Medium — **Mitigation:** the POST failure is caught and logged to the console, not surfaced as
a blocking error over the lesson content itself (a learner still sees and can interact with the SCORM
package; only the completion record fails to persist until the dependency lands) — the same "fail soft, log
loud" posture `GlobalSearchWidget.fetchOne()` already uses in this lane's `global-search` change.

## Rollback Strategy

Revert the commit(s). `LessonPlayer.vue`'s other content-type branches (`text`, `lti`) are unmodified; a
revert restores the prior placeholder behaviour for scorm12/cmi5 lessons exactly.

## Open Questions

- **Content URL resolution (Decision 4).** No backend controller resolves `contentRef` today. This change
  calls OpenRegister's generic per-object files endpoint as the best-evidenced guess; confirming (or
  replacing) this is real follow-up work, likely alongside whatever change eventually builds SCORM package
  extraction/hosting.
- Live browser verification of the SCORM API shim against a real SCORM 1.2 package, and of the cmi5 launch
  flow once the sibling change's launch endpoint exists — deferred, no local instance exercised this pass.
