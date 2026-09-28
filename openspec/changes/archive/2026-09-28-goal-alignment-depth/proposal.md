---
kind: code
depends_on:
  - competency-year-scope
---

# Proposal: goal-alignment-depth

## Summary
A lesson, course, assignment or assessment can link to a goal today, but the link says nothing about how far it takes the goal. This change adds `competencyAlignments: [{competencyId, depth}]` to `Lesson`, `Course`, `Assignment` and `Assessment`, where `depth` is a level from the goal's own framework (`CompetencyFramework.proficiencyLevels[].levelId`). The existing flat `competencyIds` stays, and a save-time listener keeps it derived from the alignments so every current reader keeps working.

## Motivation
Round 2 recon B (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/B-curriculum-goals-coverage.md`, section 1, row "Weight/depth on a lesson-goal link (introduce/practise/master)") found the gap: "Missing everywhere `competencyIds` appears". A `Course.competencyIds` or `Lesson.competencyIds` entry "carries no weight or depth: a UUID is either linked or not, nothing like introduce/practise/master".

Curriculum-mapping tools show whether a goal is introduced, practised or mastered in a unit (recon B section 2, Atlas scope-and-sequence views, onatlas.com/atlas-features, read 2026-09-27). The coverage rollup (change 3 of this lane) needs a depth per link to say more than "referenced somewhere".

Plan assumption A4 decides the vocabulary: depth uses each framework's own `proficiencyLevels`, not a fixed three-value enum, because a kwalificatiedossier's scale and a kerndoelen set's are not the same shape (recon B open question 4).

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` gains `competencyAlignments` on four schemas; a new pre-save listener keeps `competencyIds` in step and refuses a depth the framework does not know; demo data, catalogue keys and tests.

## Scope

### In Scope
- `competencyAlignments` on `Lesson`, `Course`, `Assignment` and `Assessment`: array of `{competencyId (required, $ref Competency), depth (nullable levelId)}`, default `[]`.
- `CompetencyAlignmentListener` on `ObjectCreatingEvent` and `ObjectUpdatingEvent` for those four schemas:
  - when the alignments change, `competencyIds` becomes their goal ids, in order, without duplicates;
  - when only `competencyIds` changes on a row that has alignments, the alignments follow (kept depths stay, a new id gets depth `null`, a removed id drops out);
  - a depth that is not a `levelId` of the goal's framework, an unknown goal, or the same goal twice is refused with a message that names the allowed levels.
- The read rule for consumers: a row with no alignments reads its `competencyIds` as alignments with depth `null`.
- Register and per-schema version bumps, demo rows, catalogue keys, PHPUnit tests.

### Out of Scope
- `Item.competencyIds`: authoring metadata only, per its own schema note; unchanged.
- A picker for depth in the form. The field renders as a JSON editor (`widget: json`); a proper picker is a follow-up.
- Using depth in `CompetencyAttainment`. Attainment stays evidence-based; depth describes the plan, not the learner.
- Computing coverage. That is `curriculum-coverage-rollup`.

## Approach
Declarative properties in the register, plus one imperative pre-save listener, because deriving a flat list from a nested array and validating a value against another object's scale are not expressible as `x-openregister-calculations`. The listener follows the existing `PortfolioEntryOwnershipListener` pattern (refuse with `setErrors`) and the `AssessmentResultAudience` pattern (write with `setModifiedData`).

## New Dependencies
None.

## Impact
- `lib/Settings/learniq_register.json`: four schemas gain one property each; `Lesson` and `Course` and `Assignment` 0.3.0 to 0.4.0, `Assessment` 0.2.0 to 0.3.0; `info.version` minor bump.
- `lib/Listener/CompetencyAlignmentListener.php`, `lib/Service/CompetencyAlignmentNormaliser.php`: new.
- `lib/AppInfo/Registrar/IntegrityListenerRegistrar.php`: two registrations.
- `lib/Settings/learniq_mock_register.json`, `l10n/*`: demo rows and keys.
- Readers of `competencyIds` (`GradeEvidenceRollup`, `CompetencyAttainmentRollupHandler`, `SkillsGapDashboard.vue`) are unchanged: the list stays filled.

## Cross-Project Dependencies
None. Stacked on `competency-year-scope` (learniq PR 1019) only because both bump the same register version line and change 3 needs both.

## Risks

### Risk 1: The two lists drift when a writer edits both in one save
**Severity:** Medium. **Mitigation:** the alignments win when both change in one save; the rule is in the spec and tested.

### Risk 2: A school edits a framework's levels after lessons use them
**Severity:** Low. **Mitigation:** the listener only checks depth on the row being saved. Stored rows keep their depth; the rollup counts an unknown depth as "depth not set" rather than dropping the link.

### Risk 3: Editing JSON in a form is unfriendly
**Severity:** Low. **Mitigation:** the refusal message names the valid levels, and a picker is listed as a follow-up. Existing `competencyIds` editing keeps working and fills alignments with depth `null`.

## Rollback Strategy
Revert the register, listener, registrar and catalogue diffs. `competencyIds` is filled on every row that had alignments, so every reader keeps working; stored `competencyAlignments` stay as unvalidated extra data.

## Open Questions
None.
