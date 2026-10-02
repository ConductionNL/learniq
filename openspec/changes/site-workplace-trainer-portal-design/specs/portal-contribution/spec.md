## ADDED Requirements

### Requirement: A workplace trainer lands on what is waiting for her

The `praktijkopleider` audience MUST declare pages. The first MUST be `poOverview`, "Overzicht", showing open steps first, then one card per student with an active or upcoming placement, each with the placement period and the next step. A next step MUST come from data the trainer can read: a POK to sign, an assessment to finish, or an assessment to write. Every block MUST read only her own placements, assessments and shares. Design of record: `LearniqTrainer.dc.html`.

#### Scenario: Karin sees her two students
- GIVEN trainer Karin Smit with active placements for Daan and Lotte
- WHEN she signs in on the stageportaal
- THEN "Overzicht" shows a card for Daan and one for Lotte with their placement periods
- @e2e exclude planned: written with the build in tests/e2e/mbo-trainer-flows.spec.ts (specs-only change)

#### Scenario: An assessment to write is a task
- GIVEN Lotte's placement is active and Karin submitted no assessment for it
- WHEN Karin opens "Overzicht"
- THEN the open steps include "Vul een beoordeling in" for Lotte
- @e2e exclude planned: written with the build in tests/e2e/mbo-trainer-flows.spec.ts (specs-only change)

#### Scenario: Another trainer's student never shows
- GIVEN a placement whose trainer is not Karin
- WHEN Karin opens "Mijn studenten"
- THEN that student is not there
- @e2e exclude server scope; pinned by PortalContributionProviderTest on the direct `practicalTrainerId` scope

### Requirement: NEW: A trainer sees her students by name

The `praktijkopleider` audience MUST declare `poLearners` over `learner-profile`, joined forward through her own placements, projecting `givenName` and `familyName` only. A learner whose placement with her is `terminated` MUST NOT show. This is new work: the placements carry only `learnerRef` today.

#### Scenario: Names, nothing more
- GIVEN Karin's placement for Daan Visser
- WHEN she opens Daan's card
- THEN she reads "Daan Visser" and no birth date, address or contact data
- @e2e exclude projection; pinned by PortalContributionProviderTest written with the build

### Requirement: NEW: A trainer reads the assessments she wrote

The `praktijkopleider` audience MUST declare `poWerkprocesAssessments`, direct scope on `assessorId`, projecting the placement, the werkproces label, the judgement, the notes, `assessedAt` and `lifecycle`. She MUST see her drafts and her submitted assessments. She MUST NOT see another assessor's assessments.

#### Scenario: Karin finds her draft
- GIVEN Karin saved a draft assessment for Lotte
- WHEN she opens "Beoordelingen"
- THEN the draft shows with "Maak de beoordeling af"
- @e2e exclude planned: written with the build in tests/e2e/mbo-trainer-flows.spec.ts (specs-only change)

### Requirement: NEW: A trainer assesses a werkproces in plain words

The assessment form MUST let the trainer pick the student by name from her own placements and the werkproces by its label from that placement's kwalificatiedossier. The codes MUST be filled by the server from that choice. The trainer MUST be able to save the form as a draft and finish it later through an update action on her own drafts. `assessorId` MUST stay stamped from her own claim. The school's confirmation MUST stay staff-only. `minTrust` MUST stay `substantial`.

#### Scenario: Saving halfway
- GIVEN Karin halfway through the assessment for Lotte
- WHEN she saves and leaves
- THEN a draft assessment exists with her answers, assessor Karin
- AND opening it later continues where she stopped
- @e2e exclude planned: written with the build in tests/e2e/mbo-trainer-flows.spec.ts (specs-only change)

#### Scenario: A code sent by the client is ignored
- GIVEN an assessment create that sends a `werkprocesCode` outside the placement's kwalificatiedossier
- WHEN the server saves it
- THEN the save is refused
- @e2e exclude server rule; covered by the bpv werkproces resolver tests

### Requirement: Every trainer label on the site reads in Dutch, school terms explained

The `praktijkopleider` manifest MUST pass through `PortalLabelTranslator`. Every label MUST have a Dutch entry in the "u" form. The first use of a school term on a page (praktijkovereenkomst, werkproces, kwalificatiedossier, BPV) MUST carry a one-line explanation.

#### Scenario: The trainer site reads Dutch
- GIVEN the Esdoorn Techniek College site in Dutch
- WHEN Karin opens "Overzicht"
- THEN every heading and button from learniq is Dutch, and "praktijkovereenkomst" is explained where it first appears
- @e2e exclude planned: written with the build in tests/e2e/mbo-trainer-flows.spec.ts (specs-only change)
