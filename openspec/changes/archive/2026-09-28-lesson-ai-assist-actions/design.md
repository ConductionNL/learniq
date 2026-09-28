# Design: lesson-ai-assist-actions

## Architecture Overview

```
LessonComposer.vue ──mounts──> LessonAssistPanel.vue ──uses──> src/utils/lessonAssist.js
      │    ▲                        │                                │  (pure; transport injected)
      │    └── emits draft / goal ──┘                                ▼
      │                                          @nextcloud/axios POST generateUrl(
      │                                            '/apps/hermiq/api/lesson-authoring/<action>')
      └── PATCH /apps/openregister/api/objects/learniq/Lesson/:id  {blocks, competencyIds?}
```

Frontend only. The composer keeps its OpenRegister-only data path (ADR-022): it still loads and saves the
lesson through OpenRegister's object endpoints, and it gains one extra read of the course's and lesson's
`Competency` rows for goal titles. The only new outbound call is to hermiq's delegate. Learniq adds no
controller, no route, no schema and no PHP.

Pieces:
- `src/utils/lessonAssist.js`: pure functions. Request builders per action (allowlist), goal payload
  (`{titles, ids}` from `{id, title}` goals), index back-mapping, lesson text assembly, outcome
  classification, draft text from a result, and `createLessonAssistClient({post, urlFor})`.
- `src/utils/lessonBlocks.js`: `serialiseLessonBlocks(blocks)`, moved verbatim out of
  `LessonComposer.serialisableBlocks()` so the node test can prove the draft marker never persists, plus the
  draft helpers `makeDraftBlock`, `keepDraftBlock` and `countPendingDrafts`.
- `src/components/lesson/LessonAssistPanel.vue`: the controls, the notice line, busy and message states, the
  goal suggestions list, the session-wide `off` state; opens the notice dialog before the first call.
- `src/dialogs/LessonAssistNoticeDialog.vue`: an `NcDialog` with "Continue" and "Cancel" (the modal isolation
  rule keeps every dialog in its own file under `src/dialogs/`).

## API Design

No learniq endpoint. The consumed endpoints are listed in `contract.md`, taken from hermiq PR 962.

## Nextcloud Integration
- Controllers: none.
- Services: none. Hermiq presence is `window.OC.appswebroots.hermiq !== undefined`, the check
  `LearniqSettings.vue` already uses for the hermiq link. The page does not reload when an admin enables
  hermiq, which is acceptable for a flag that changes a few times per instance lifetime.
- Frontend: `@nextcloud/axios` (session plus `requesttoken`), `@nextcloud/router` `generateUrl`,
  `@nextcloud/vue` `NcDialog`, `NcSelect`, `NcButton`, `NcNoteCard`.
- Events/Hooks: none.

## Decisions

### D1: Drafts are resolved before save, not persisted with a marker
A draft block carries a local `assistDraft: {action, provider}` field. `serialiseLessonBlocks` writes only the
required trio plus the type's payload, so the marker cannot reach OpenRegister. Save is refused while
`countPendingDrafts(blocks) > 0`. Alternative: add an `aiDraft` boolean to the block schema and filter it in
`LessonPlayer`. Rejected: a schema change plus a player change for a state that should last minutes, and a
persisted draft is exactly the "saved as final without a teacher action" the contract forbids.

### D2: The per-session `off` state lives in the module, not in storage
`feature-not-enabled` and 404 set a module-level flag, so moving between lessons does not re-probe. A reload
probes again, which is how a newly enabled feature becomes visible. Alternative: `sessionStorage`. Rejected:
nothing gains from surviving a reload, and a stale flag would hide the feature after an admin enabled it.

### D3: The notice is confirmed once per browser, with a fallback
The confirmation is stored under `learniq:lesson-assist-notice-confirmed` in `localStorage`, read and written
inside `try/catch`. When storage throws (private window, blocked site data), the confirmation lasts for the
page's lifetime instead. The one-line notice next to the actions is always visible, so the teacher is never
left without the warning.

### D4: Rewrite works per block
The "rewrite simpler" action sits on each rich text block and sends that block's text only, so the draft lands
right after its source and the teacher compares the two. The reading level comes from the panel (default
`B1`). Alternative: rewrite the whole lesson in one call. Rejected: one long draft loses the block structure.

### D5: Goal candidates are the course's and the lesson's goals
`Course.competencyIds` and `Lesson.competencyIds` are unioned, capped at 100, and fetched by id. That set feeds
the goal picker (outline, questions) and goal suggestions. A free field adds a goal in the teacher's own words
for the outline, which covers a course with no linked goals. The candidate order is fixed at load, so a
returned index maps to the id at that position. Alternative: all `Competency` rows of the tenant. Rejected: an
SLO import holds thousands, far past hermiq's 100-title limit.

### D6: Outcome classes, not reasons, drive the UI
`classifyOutcome` turns every answer into one of `ok`, `off`, `retry`, `busy`, `failed` (table in
`contract.md`). The component switches on the class only, so a new hermiq reason falls into `retry` without a
learniq change.

## Decisions taken headless
- Hiding on `feature-not-enabled` shows a short note ("AI help is switched off") instead of removing the panel
  silently, so a teacher knows why the actions vanished. The brief said "hide the buttons"; the buttons are
  hidden.
- A 404 from the hermiq route counts as switched off (an older hermiq without the delegate), not as an error.
- Question lists are inserted as one draft block with a numbered markdown list, not one block per question.
- Goal suggestions already linked to the lesson are shown as "already linked" and cannot be added twice.

## Security Considerations
- No pupil data: request bodies come from per-action allowlists; the node test asserts every body's key set
  and that no id appears in it. The lesson text is the teacher's own lesson content.
- CSRF: `@nextcloud/axios` sends the `requesttoken`; hermiq requires it.
- Rate limit: hermiq's per-user limit answers 429, which the panel shows as "busy".
- Drafts never auto-save or auto-publish; `Lesson.lifecycle` is untouched by every assist path.
- The model output is inserted as markdown into `CnMarkdownEditor`, the same editor the teacher types into; it
  renders through the existing markdown path of `LessonPlayer`, which already sanitises teacher-entered text.

## NL Design System
Nextcloud components and CSS variables only (`--color-border`, `--color-text-maxcontrast`,
`--color-primary-element-light` for the draft marker). The draft label is text, not colour alone (WCAG 1.4.1).
Busy states use `aria-busy` and a polite live region; every icon-only button has an `aria-label` that names
the block position, as the existing block controls do.

## File Structure
```
src/
  utils/lessonAssist.js                    new
  utils/lessonBlocks.js                    new (serialiser moved out of LessonComposer)
  components/lesson/LessonAssistPanel.vue  new
  dialogs/LessonAssistNoticeDialog.vue     new
  views/LessonComposer.vue                 modified
tests/unit-js/lessonAssist.test.mjs        new
l10n/en.json, l10n/nl.json, l10n/*.js      new strings
docs/user-guide/user/                      one new page
```

## Seed Data
Not applicable: no schema is introduced or changed. The feature reads existing `Lesson`, `Course` and
`Competency` rows, which the example sets already seed.

## Declarative-vs-imperative decision
No lifecycle, aggregation, calculation, notification, relation or widget is added. The only behaviour is a
frontend call to an external delegate (hermiq), which ADR-031 lists as a legitimate imperative seam.

## Trade-offs
- Reading hermiq presence from `window.OC.appswebroots` is the app's existing pattern, not the initial state
  API. Moving both call sites to initial state is a separate refactor; doing it here would change
  `PageController` for no gain to this feature.
- Without a live hermiq, the component wiring is proven by build and lint only; the decisions it makes are in
  the pure module, which the node test covers.
