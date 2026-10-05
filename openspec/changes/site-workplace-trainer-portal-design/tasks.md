# Tasks: site-workplace-trainer-portal-design

Built in waves. A key is declared only once portaliq development keeps it; portaliq drops an unknown key without a word.

**Trust:** her reads are `minTrust: low`. Her assessment is `low` too since `an-invited-trainer-may-assess` (Ruben, 4 October 2026): an invited trainer may assess, the row records who assessed and at what assurance, and a school may demand eHerkenning through `bpv_assessment_min_assurance`. Her POK signature still asks for `substantial`, which is a separate decision.

- [ ] **T1** (waits for portaliq `via.when`): `poLearners` over `learner-profile`, forward join on her placements with `via.when` (every state but `terminated`), names only. Without the filter a terminated placement would still reveal a name, which this change refuses; until then the trainer reads her placements without the pupils' names.
- [x] **T2**: `poWerkprocesAssessments`, direct scope on `assessorId`, with the judgement in words
  - PHPUnit `PortalContributionProviderTest`, `GuardianSitePagesTest`, `PortalLabelTranslatorTest`
- [ ] **T3** (waits for portaliq `steps`, `draft` and `confirmation` on an action): the assessment form in steps, with a review step and a saved draft. `widget: choices` on the judgement can land with it. The werkproces is still picked by code, not by label: that waits on the same wave.
- [x] **T3a**: the assessment posts to learniq's own endpoint, which records who assessed and at what assurance (`an-invited-trainer-may-assess`)
  - PHPUnit `PortalWerkprocesControllerTest`, `PortalWerkprocesAssessmentTest`
- [ ] **T5**: the trainer's open steps as portal tasks through OpenRegister's portal-task seam, raised and completed server-side; check first that a praktijkopleider subject is addressable
  - PHPUnit for the step derivation
- [x] **T6a**: `TrainerSitePages`: the overview (`home: true`, `group`), her placements, her three latest assessments (`limit`, `sort`), two `cta` tiles and the inbox; a page per section
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own resolvers (development 29a17ba): nothing dropped
- [x] **T6b**: the placement cards carry their heading ("Mijn stageplaatsen") and project the waiting and returned hours; the overview opens with the greeting and the hours to approve as a highlight (lane L2's block contract)
  - PHPUnit `GuardianSitePagesTest`
  - the cards still name the company, not the student: a student's name waits for T1 (`via.when`), so two students at one company read alike
- [x] **T7**: the manifest through `PortalLabelTranslator`; Dutch "u" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T7b**: a one-line explanation beside each school term (praktijkovereenkomst, werkproces, BPV) on first use
- [x] **T8**: a praktijkopleider can be given a portal account (`occ learniq:portal:invite-trainer`); the mbo set already seeds 36 trainers and 151 placements (`invite-a-trainer-and-an-assessor`)
  - PHPUnit `BpvPortalInvitationTest`; `python3 scripts/example-sets/mbo.py --check`
- [ ] **T9**: e2e: the trainer opens a student, saves an assessment halfway and finishes it later
  - `tests/e2e/mbo-trainer-flows.spec.ts`

## Follow-ups (not in this change)

- `bpv-hours-registration`: weekly hours per student, a target per placement, approval by the trainer.
- A decision on showing the school supervisor's name to the trainer.
- A planned-visit record for the school's workplace visit.
- The trainer's own company details as a portal page.
