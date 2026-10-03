# course-management Specification

## ADDED Requirements

### Requirement: The lesson composer offers four AI assist actions through hermiq, only when hermiq can answer

<!-- @e2e exclude The actions need hermiq PR 962 merged and a DPO-acknowledged feature on a live instance, neither exists yet. The decision logic (visibility, payloads, outcome classes) is covered by tests/unit-js/lessonAssist.test.mjs. -->

`LessonComposer` MUST offer four assist actions: draft an outline from one or more goals, suggest questions
for the lesson, rewrite one rich text block at a lower reading level, and suggest which goals the lesson
covers. Each action MUST call hermiq's `lesson-authoring` delegate
(`POST /apps/hermiq/api/lesson-authoring/{outline|questions|simplify|goal-suggestions}`) and MUST NOT call
any model or vendor directly. The actions MUST NOT render when the hermiq app is not enabled for the user,
read from `window.OC.appswebroots.hermiq` exactly as `LearniqSettings.vue` reads it, so learniq keeps no hard
dependency on hermiq. When hermiq answers `available: false` with `reason: feature-not-enabled`, or the route
answers 404, the actions MUST collapse to a note saying AI help is switched off and MUST stay hidden for the
rest of the browser session. A `provider-error`, a 429 or any other failure MUST show a plain message and
MUST leave the lesson and the actions unchanged.

#### Scenario: A teacher without hermiq sees no assist actions

- **GIVEN** a Nextcloud instance where the hermiq app is not enabled
- **WHEN** a teacher opens `LessonComposer` for a lesson
- **THEN** no assist panel and no "rewrite simpler" block action render
- **AND** no request is sent to `/apps/hermiq/`

#### Scenario: Hermiq answers that the feature is switched off

- **GIVEN** hermiq is enabled but its `lesson-authoring` AI feature is `disabled`
- **WHEN** the teacher runs "Suggest questions"
- **THEN** hermiq answers `{available: false, reason: "feature-not-enabled"}`
- **AND** the panel collapses to a note that AI help is switched off
- **AND** no block is added to the lesson

#### Scenario: The model gives no usable answer

- **GIVEN** hermiq's feature is enabled and the model call fails
- **WHEN** the teacher runs "Draft an outline"
- **THEN** hermiq answers `{available: false, reason: "provider-error"}`
- **AND** the panel shows that the AI model gave no usable answer
- **AND** the actions stay available for another try

### Requirement: Assist requests carry lesson content and goal titles only

<!-- @e2e exclude Request shape is a pure function of the lesson state; asserted by tests/unit-js/lessonAssist.test.mjs ("request bodies carry only the contract fields", "goal ids never leave learniq"). -->

Every assist request body MUST be built from an allowlist per action and MUST carry only `lessonText`,
`goalTitles`, `language`, `questionCount` and `readingLevel` as hermiq's contract defines them for that
action. It MUST NOT carry a pupil name, number, grade, note, any other pupil data, or any object id. Goal ids
MUST be mapped to their titles before the call, and the `index` values hermiq returns MUST be mapped back to
goal ids after it; an index outside the list sent MUST be ignored. `lessonText` MUST be the rich text of the
lesson's blocks that are not pending drafts, cut to 20,000 characters; `goalTitles` MUST hold at most 100
titles of at most 300 characters each. Next to the actions the panel MUST say that the text goes to an AI
model and must not contain pupil data, and before the first call in a browser the teacher MUST confirm a
notice that says the same.

#### Scenario: Goal suggestions map back to goal ids

- **GIVEN** a lesson whose course links three goals, sent in the order A, B, C
- **WHEN** hermiq answers `suggestedGoals: [{index: 0}, {index: 2}, {index: 7}]`
- **THEN** learniq offers goals A and C as suggestions
- **AND** index 7 is ignored
- **AND** the request body held the three titles and no goal id

#### Scenario: The first assist call asks for confirmation

- **GIVEN** a teacher who has never confirmed the AI notice in this browser
- **WHEN** they run any assist action
- **THEN** a dialog says the lesson text goes to an AI model and must not contain pupil data
- **AND** the request is sent only after they choose to continue
- **AND** a later action in the same browser runs without the dialog

### Requirement: Every assist result is a draft the teacher keeps or discards

<!-- @e2e exclude Needs a live hermiq answer; the draft insertion and the save guard are covered by tests/unit-js/lessonAssist.test.mjs ("draft blocks are marked and never serialised with the marker", "pending drafts block save"). -->

An outline, a question list or a rewrite MUST be inserted as a `richText` block marked as an AI draft: an
outline or question list at the end of the lesson, a rewrite directly after the block it came from. A draft
block MUST show that it is an AI draft, MUST name the model service hermiq reports in `provider`, and MUST
offer "Keep" and "Discard". Keeping MUST turn it into an ordinary `richText` block; discarding MUST remove it.
The lesson MUST NOT be saved while any draft is pending, and the draft marker MUST never be written to
`Lesson.blocks`. A goal suggestion MUST be added to `Lesson.competencyIds` only when the teacher adds it, and
the addition MUST be saved with the next "Save lesson". No assist result MUST change `Lesson.lifecycle`.

#### Scenario: A teacher keeps an AI outline

- **GIVEN** hermiq returns an outline for the goal "De leerling kan breuken vergelijken en ordenen"
- **WHEN** the draft block appears at the end of the lesson labelled as an AI draft with its model service
- **AND** the teacher edits it and chooses "Keep"
- **THEN** the block becomes an ordinary rich text block
- **AND** "Save lesson" writes it to `Lesson.blocks` without any draft marker

#### Scenario: A pending draft blocks the save

- **GIVEN** a lesson with one AI draft block not yet kept or discarded
- **WHEN** the teacher chooses "Save lesson"
- **THEN** nothing is sent to OpenRegister
- **AND** the composer asks the teacher to keep or discard the AI drafts first

#### Scenario: A teacher adds a suggested goal

- **GIVEN** goal suggestions list a goal the lesson does not yet link
- **WHEN** the teacher adds it and saves the lesson
- **THEN** `Lesson.competencyIds` holds that goal's id
- **AND** `Lesson.lifecycle` is unchanged
