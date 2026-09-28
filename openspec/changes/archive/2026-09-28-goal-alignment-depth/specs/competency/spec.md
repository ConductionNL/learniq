# competency Specification

## ADDED Requirements

### Requirement: Lessons, courses, assignments and assessments align to goals with a depth

`Lesson`, `Course`, `Assignment` and `Assessment` MUST each declare an optional `competencyAlignments` property:
an array (default `[]`) of objects with `competencyId` (required, `format: uuid`, `$ref: Competency`) and `depth`
(nullable string, `maxLength: 64`). `depth` MUST be either `null` (depth not set) or a `levelId` from the
`proficiencyLevels` of the `CompetencyFramework` that owns the aligned `Competency` (plan assumption A4). No fixed
depth enum exists. The existing `competencyIds` property on the four schemas MUST stay, with its type and meaning
unchanged. `Item.competencyIds` MUST NOT gain alignments: it stays authoring metadata.

#### Scenario: A lesson practises one goal and introduces another

<!-- @e2e exclude Register shape plus a pre-save listener; the property renders through the existing Lesson form and data widget. Covered by GoalAlignmentDepthRegisterTest and CompetencyAlignmentListenerTest::testAlignmentsDeriveCompetencyIds. -->

- **GIVEN** a `CompetencyFramework` whose `proficiencyLevels` are `introduce`, `practise` and `master`
- **AND** two leaf `Competency` rows under it
- **WHEN** a teacher saves a `Lesson` with `competencyAlignments: [{competencyId: <goal A>, depth: "practise"},
  {competencyId: <goal B>, depth: "introduce"}]`
- **THEN** the lesson persists with both alignments
- **AND** its `competencyIds` is `[<goal A>, <goal B>]`

#### Scenario: A depth may be left open

<!-- @e2e exclude Same listener path; covered by CompetencyAlignmentListenerTest::testNullDepthIsAccepted. -->

- **GIVEN** a leaf `Competency`
- **WHEN** a teacher saves an `Assignment` with `competencyAlignments: [{competencyId: <goal>, depth: null}]`
- **THEN** the assignment persists, and its `competencyIds` is `[<goal>]`

### Requirement: competencyIds stays derived from the alignments

On create and update of `Lesson`, `Course`, `Assignment` and `Assessment`, a pre-save listener
(`CompetencyAlignmentListener`, registered on OpenRegister's `ObjectCreatingEvent` and `ObjectUpdatingEvent`) MUST
keep the two lists in step:

- When `competencyAlignments` differs from the stored value (or is non-empty on create), `competencyIds` MUST be
  set to the alignments' `competencyId` values, in alignment order, without duplicates. An alignment list emptied
  on update MUST empty `competencyIds`.
- When only `competencyIds` changes on a row whose stored alignments are non-empty, `competencyAlignments` MUST
  follow: an alignment whose goal is still listed keeps its depth, a newly listed goal gets `depth: null`, and an
  alignment whose goal is no longer listed is dropped.
- When both change in one save, the alignments MUST win.
- A row that never had alignments MUST keep its `competencyIds` exactly as written.

The listener MUST act only on objects in the `learniq` register with one of the four schema slugs, and MUST NOT
fail another app's write when it cannot resolve a schema.

#### Scenario: A legacy row keeps its flat list

<!-- @e2e exclude Back-compat listener path; covered by CompetencyAlignmentListenerTest::testLegacyRowIsLeftAlone. -->

- **GIVEN** a `Course` saved with `competencyIds: [<goal>]` and no `competencyAlignments`
- **WHEN** the listener handles the save
- **THEN** it writes nothing, and `competencyIds` stays `[<goal>]`

#### Scenario: Editing only the flat list updates the alignments

<!-- @e2e exclude Listener sync path; covered by CompetencyAlignmentListenerTest::testFlatListEditUpdatesAlignments. -->

- **GIVEN** a stored `Lesson` with `competencyAlignments: [{competencyId: <goal A>, depth: "master"}, {competencyId:
  <goal B>, depth: "introduce"}]`
- **WHEN** an update changes only `competencyIds` to `[<goal A>, <goal C>]`
- **THEN** `competencyAlignments` becomes `[{competencyId: <goal A>, depth: "master"}, {competencyId: <goal C>,
  depth: null}]`

#### Scenario: Emptying the alignments empties the flat list

<!-- @e2e exclude Listener sync path; covered by CompetencyAlignmentListenerTest::testEmptiedAlignmentsEmptyTheFlatList. -->

- **GIVEN** a stored `Assessment` with one alignment and `competencyIds: [<goal>]`
- **WHEN** an update sets `competencyAlignments: []`
- **THEN** `competencyIds` becomes `[]`

### Requirement: A depth the goal's framework does not know is refused

Before a `Lesson`, `Course`, `Assignment` or `Assessment` with changed `competencyAlignments` is saved, the listener
MUST refuse the write (OpenRegister reject mode: `setErrors` plus `stopPropagation`) when any alignment names a
`competencyId` that does not resolve to a `Competency`, names the same `competencyId` twice, or carries a non-null
`depth` that is not a `levelId` of the aligned goal's framework. The refusal message MUST name the goal's code and
the allowed level ids, so a teacher can correct it without looking them up.

#### Scenario: A depth from another framework is refused

<!-- @e2e exclude Reject-mode listener path; covered by CompetencyAlignmentListenerTest::testUnknownDepthIsRefusedWithTheAllowedLevels. -->

- **GIVEN** a goal whose framework's levels are `nog-niet-competent` and `competent`
- **WHEN** a teacher saves an `Assignment` aligning that goal with `depth: "master"`
- **THEN** the save is refused
- **AND** the message names the goal's code and the levels `nog-niet-competent` and `competent`

#### Scenario: The same goal twice is refused

<!-- @e2e exclude Reject-mode listener path; covered by CompetencyAlignmentListenerTest::testDuplicateGoalIsRefused. -->

- **GIVEN** a leaf `Competency`
- **WHEN** a teacher saves a `Course` with two alignments naming that goal
- **THEN** the save is refused with a message that names the goal's code

### Requirement: Readers treat a flat-only row as alignments without depth

Any reader of goal links (the coverage rollup first) MUST resolve a row's effective alignments as its
`competencyAlignments` when non-empty, else its `competencyIds` mapped to `{competencyId, depth: null}`. A stored
depth that is no longer a `levelId` of the framework MUST be read as `null`, never as a missing link.

#### Scenario: A course from before this change counts as aligned without depth

<!-- @e2e exclude Read rule for the rollup that ships in curriculum-coverage-rollup; pinned here by CompetencyAlignmentNormaliserTest::testEffectiveAlignmentsFallBackToTheFlatList. -->

- **GIVEN** a `Course` with `competencyIds: [<goal>]` and no alignments
- **WHEN** a reader resolves its effective alignments
- **THEN** it gets `[{competencyId: <goal>, depth: null}]`
