---
kind: config
depends_on: []
---

# Proposal: differentiation-not-styles-copy

## Summary
Learniq helps teachers differentiate by level, goal, time and material, and records support needs. It must never suggest that a pupil has a fixed way of learning that lessons should be matched to: research finds no benefit in that, and profiling minors on it is a privacy risk. The wording is absent from learniq today. This change makes that absence durable: the teacher-facing titles and help texts of the group plan, instruction group, support request and exam accommodation forms are rewritten to speak of support needs and differentiation by level or goal, a test fails the build if style-matching wording ever appears in a product surface, and the settings page carries one short note with the evidence, next to the AI features.

## Motivation
Assumption A8: the product speaks of support needs and differentiation, and never of style matching. Round 2 recon D, section 1 ("Differentiation and support needs"): an exhaustive search found zero traces of the concept in learniq, and the existing mechanisms are the right ones (`GroupPlan`/`GroupPlanSubgroup` for instruction-level grouping, `SupportRequest`, `ExamAccommodation`, `LearnerProfile` without any style field). Section 3 cites three independent sources that reject style matching: Pashler, McDaniel, Rohrer and Bjork (2008, Psychological Science in the Public Interest 9(3)), Paul Kirschner on education myths, and the NRO Kennisrotonde publication "Differentiatie in de klas: wat werkt?", which names what does work: varying instruction time, grouping, varying the material, and explicit strategy instruction. Section 6 (scientific validity): "the clean baseline is the easiest point to hold this line". Proposed-changes row `differentiation-not-styles-copy`.

The recon also found the current help texts on these forms are engineering prose ("reuses LearningPlan.supersedesId's exact version-chain pattern", "stamped server-side by ExamAccommodationApprovalGuard"), which a teacher reads as noise. Moving that rationale to `x-notes`, which the form never shows, and writing plain help text is the "align" half of the change.

## Affected Projects
- [x] Project: `learniq`: `lib/Settings/learniq_register.json` (titles and property descriptions of `GroupPlan`, `GroupPlanSubgroup`, `SupportRequest`, `ExamAccommodation`; engineering rationale moved to `x-notes`; versions), `src/views/LearniqSettings.vue` (one note), `l10n/`, `tests/Unit/Settings/SupportNeedsVocabularyTest.php` (new).

## Scope

### In Scope
- Plain, teacher-facing titles and help texts on the four schemas, in terms of support needs and differentiation by level, goal, time or material. Titles of the rewritten fields move to sentence case ("Plan Period" becomes "Plan period"), and the instruction-level and accommodation-kind values get readable labels.
- `x-notes` on each rewritten property for the engineering rationale the description used to carry.
- A test that scans the product surfaces (`lib/`, `src/`, `templates/`, `appinfo/`, `docs/`, `openspec/specs/`, and the English and Dutch catalogues) for style-matching wording in English and Dutch, and fails on a hit.
- One note in Settings, under AI features, with the evidence and what to do instead.

### Out of Scope
- Any new field, schema or behaviour. No detection, no preference field.
- `openspec/changes/`: working documents that may discuss the research; the canonical specs are scanned.
- Other schemas' help texts (a wider copy sweep is its own change).

## Approach
Declarative copy edits in the register, catalogue keys for every new string, one Vue note, and a scan test. No logic.

## New Dependencies
None.

## Impact
- Four forms read differently; stored data is untouched.
- A future pull request that adds style-matching wording fails its unit tests with the file and line.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: A legitimate text needs the words
**Severity:** Low. **Mitigation:** the scan skips `openspec/changes/`, where research can be discussed; a product surface has no reason to use the words.

### Risk 2: Parallel lanes edit the same schemas
**Severity:** Low. **Mitigation:** only titles, descriptions and `x-notes` change; the orchestrator lands in series.

## Rollback Strategy
Revert the commit; the old texts return.

## Open Questions
None.
