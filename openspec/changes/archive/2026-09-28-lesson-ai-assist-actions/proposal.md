---
kind: code
---

# Proposal: lesson-ai-assist-actions

## Summary
A teacher who writes a lesson in learniq gets no help from an AI model today. This change adds four assist actions to the lesson composer: draft an outline from a goal, suggest questions, rewrite a text block at a lower reading level, and suggest which goals the lesson covers. Each action calls hermiq's `lesson-authoring` delegate, and every answer lands as a draft block or a goal suggestion that the teacher keeps or discards. Learniq sends lesson text and goal titles only, never pupil data, and the actions stay hidden when hermiq is absent or answers that the feature is switched off.

## Motivation
Round 2 recon D (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`, section 1, row "LessonComposer") found the gap: "No AI button or block type exists", and learniq contains "no code path that calls an LLM" (same section, row "Outbound AI/LLM call inside learniq itself"). The recon's section 4 proposes this change as the learniq half of the pair whose hermiq half, `lesson-authoring-ai-delegate`, is open as hermiq PR 962.

Competitor evidence, all vendor claims read in round 1 or on 2026-09-27:
- Moodle ships AI placements in the text editor and the course (generate, summarise, explain) through pluggable providers including self-hosted Ollama (`_round1/moodle/round1/M1-moodle-column.md:291`; recon D section 2).
- Docebo Creator "generates lesson drafts based on your prompt and any uploaded documents" (`_round1/corporate-lms/round1/documented-columns.md:294`, source in `_round1/corporate-lms/round1/sources.md:14`).
- Canvas Ignite AI Agent lets instructors "outline lessons including learning objectives with AI assistance" and generate quizzes, while institutions keep control over enablement (recon D section 2, instructure.com and govtech.com, read 2026-09-27).

Placement: rung 3, actions on an existing page (`LessonComposer`, `src/manifest.d/learning.json`). No new menu entry, no chat surface: the generic assistant was removed on purpose (`openspec/changes/relocate-dataexchange-remove-assistant/`), and recon D section 1 says new AI authoring belongs inline in the composer.

Plan assumption A5 governs the shape: AI outputs are drafts a teacher accepts, and the delegate lives in hermiq, off by default and gated by the AI Act register there. Recon D open question 1 recommended a dedicated HTTP delegate; hermiq PR 962 built exactly that.

## Affected Projects
- [x] Project: `learniq`: the lesson composer gains an assist panel, a one-time notice dialog, draft blocks and goal suggestions; a pure client module talks to hermiq; translations and a node unit test are added.

## Scope

### In Scope
- An assist panel in `LessonComposer` with the four actions, a goal picker fed by the course and lesson goals, a free goal field for the outline, a question count and a reading level.
- A per-block "rewrite simpler" action on each rich text block.
- A notice next to the actions saying the text goes to an AI model and must hold no pupil data, plus a one-time confirmation before the first call.
- Draft blocks: every outline, question list and rewrite lands as a rich text block marked as an AI draft, with the model service named, and with keep and discard actions. The lesson cannot be saved while a draft is still pending.
- Goal suggestions: the model picks from the goal titles learniq sends; learniq maps the returned indexes back to goal ids and the teacher adds each goal with one click.
- Hiding: the panel does not render when hermiq is not enabled; it collapses to a short note when hermiq answers `feature-not-enabled` or the route does not exist.
- A pure client module with an injected transport, tested with a stub, since the hermiq PR is not merged and no live call is possible.

### Out of Scope
- The hermiq delegate itself, its AI Act registration and its DPO gate (hermiq PR 962).
- Tidying extracted text from uploaded files (`office-file-lesson-onboarding`, the next change in this lane).
- A learniq-side on or off switch. Hermiq seeds the feature disabled; a second switch in learniq would be a second source of truth.
- Assigning any pupil to a level or group. The actions draft content only, which keeps them in hermiq's `limited` risk category (recon D section 6).
- A persisted draft marker on `Lesson.blocks`. Drafts are resolved before save, so no schema changes.

## Approach
Frontend only. A pure module `src/utils/lessonAssist.js` builds the allowlisted request bodies, maps goal ids to titles and indexes back to ids, and normalises every answer into one outcome shape; the transport (axios post plus `generateUrl`) is injected, so the unit test runs it against a stub. A new `LessonAssistPanel` component renders the controls and emits drafts; `LessonComposer` inserts them as draft blocks and blocks save until each one is kept or discarded. Hermiq presence is read the way `LearniqSettings.vue` already reads it (`window.OC.appswebroots.hermiq`), so learniq keeps no hard dependency. Details in design.md.

## New Dependencies
None. Hermiq stays an optional runtime peer; `appinfo/info.xml` gains no dependency.

## Impact
- `src/utils/lessonAssist.js`: new.
- `src/components/lesson/LessonAssistPanel.vue`: new.
- `src/dialogs/LessonAssistNoticeDialog.vue`: new.
- `src/views/LessonComposer.vue`: panel mount, draft blocks, per-block rewrite action, goal loading, save guard, `competencyIds` in the save body when a goal was added.
- `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`: new strings.
- `tests/unit-js/lessonAssist.test.mjs`: new.
- `docs/user-guide/`: one page on the assist actions.

## Cross-Project Dependencies
- `hermiq` PR 962 (`lesson-authoring-ai-delegate`) provides `POST /apps/hermiq/api/lesson-authoring/{outline|questions|simplify|goal-suggestions}`. This change consumes its `contract.md` without changing it. Until that PR is merged and a DPO has acknowledged the feature, the actions answer `feature-not-enabled` or 404, and learniq hides them. Nothing breaks when hermiq is absent.

## Risks

### Risk 1: A teacher pastes pupil data into a lesson text
**Severity:** High. **Mitigation:** learniq never adds pupil data itself: the request bodies carry lesson text and goal titles only, built from an allowlist, and the unit test asserts no other field leaves. The notice next to the actions and the one-time confirmation tell the teacher not to put pupil names or data in the text. Hermiq logs lengths, never content.

### Risk 2: An AI answer is published as if a teacher wrote it
**Severity:** Medium. **Mitigation:** every answer is a draft block with a visible AI draft label and the model service name. The lesson cannot be saved while a draft is pending, so each draft becomes lesson text only after the teacher keeps it.

### Risk 3: The contract changes before hermiq PR 962 merges
**Severity:** Low. **Mitigation:** the client reads only the documented fields, ignores unknown ones, and treats any unexpected answer as a transient failure that leaves the lesson untouched.

## Rollback Strategy
Revert the frontend diff. No schema, no stored data and no route changes, so nothing needs migrating back.

## Open Questions
None blocking. Assumptions taken headless are listed in design.md under "Decisions taken headless".
