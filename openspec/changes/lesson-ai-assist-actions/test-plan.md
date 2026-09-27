# Test Plan: lesson-ai-assist-actions

Hermiq PR 962 is not merged, so no live call is possible. Every decision the composer makes lives in
`src/utils/lessonAssist.js` and `src/utils/lessonBlocks.js`; those run under `node --test` against a stubbed
transport (`npm run test:js-unit`).

## Test Cases

### TC-1: No hermiq, no actions
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-the-lesson-composer-offers-four-ai-assist-actions-through-hermiq-only-when-hermiq-can-answer`
- **type**: functional
- **preconditions**: `appswebroots` without `hermiq`
- **steps**: call `isHermiqEnabled({})` and `isHermiqEnabled({hermiq: '/apps/hermiq'})`
- **expected result**: false, then true
- **test command**: `npm run test:js-unit` (lessonAssist.test.mjs)

### TC-2: Outcome classes
- **spec_ref**: same requirement
- **type**: functional
- **preconditions**: stub transport answering each row of the contract table
- **steps**: run each client action
- **expected result**: `feature-not-enabled` and 404 give `off`; `provider-error` and a missing field give `retry`; 429 gives `busy`; 400, 500 and a thrown error give `failed`; a full answer gives `ok`
- **test command**: `npm run test:js-unit`

### TC-3: Request bodies carry only the contract fields
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-assist-requests-carry-lesson-content-and-goal-titles-only`
- **type**: security
- **preconditions**: goals with ids, a lesson with drafts and ordinary blocks
- **steps**: run each action through the stub and record the body
- **expected result**: key sets match the allowlist per action; no goal id or block id appears; drafts are left out of `lessonText`; limits applied (20,000 characters, 100 titles, 300 characters)
- **test command**: `npm run test:js-unit`

### TC-4: Indexes map back to goal ids
- **spec_ref**: same requirement
- **type**: functional
- **preconditions**: three goals sent in order A, B, C
- **steps**: stub answers indexes 0, 2 and 7
- **expected result**: ids of A and C; 7 ignored; duplicates collapsed
- **test command**: `npm run test:js-unit`

### TC-5: Drafts are marked and never persisted with the marker
- **spec_ref**: `openspec/changes/lesson-ai-assist-actions/specs/course-management/spec.md#requirement-every-assist-result-is-a-draft-the-teacher-keeps-or-discards`
- **type**: regression
- **preconditions**: a block list with one draft
- **steps**: `countPendingDrafts`, `keepDraftBlock`, `serialiseLessonBlocks`
- **expected result**: one pending, then zero; the serialised block holds only `blockId`, `type`, `order`, `text`
- **test command**: `npm run test:js-unit`

### TC-6: Composer behaviour on a live instance (after hermiq PR 962 merges)
- **spec_ref**: all three requirements
- **type**: persona
- **persona**: a primary school teacher writing a rekenen lesson
- **preconditions**: hermiq enabled, `lesson-authoring` feature acknowledged and enabled
- **steps**: confirm the notice, run the four actions, keep one draft, discard one, try to save with one pending, add a suggested goal, save
- **expected result**: as the scenarios describe
- **test command**: `/test-functional` (deferred until the delegate is live)

## Coverage Summary
- The composer offers four actions only when hermiq can answer: covered by TC-1, TC-2; live check TC-6.
- Requests carry lesson content and goal titles only: covered by TC-3, TC-4.
- Every result is a draft: covered by TC-5; live check TC-6.

## Out of Scope
- A Playwright journey: it needs the hermiq delegate merged and enabled. TC-6 is the manual check to run then.
