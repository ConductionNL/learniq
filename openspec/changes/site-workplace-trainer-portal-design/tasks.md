# Tasks: site-workplace-trainer-portal-design

Built in waves. A key is declared only once portaliq development keeps it; portaliq drops an unknown key without a word.

**Trust:** her reads are `minTrust: low`. Her assessment is `low` too since `an-invited-trainer-may-assess` (Ruben, 4 October 2026): an invited trainer may assess, the row records who assessed and at what assurance, and a school may demand eHerkenning through `bpv_assessment_min_assurance`. Her POK signature still asks for `substantial`, which is a separate decision.

- [ ] **T1** (waits for portaliq `via.when`): `poLearners` over `learner-profile`, forward join on her placements with `via.when` (every state but `terminated`), names only. Without the filter a terminated placement would still reveal a name, which this change refuses; until then the trainer reads her placements without the pupils' names.
- [x] **T2**: `poWerkprocesAssessments`, direct scope on `assessorId`, with the judgement in words
  - PHPUnit `PortalContributionProviderTest`, `GuardianSitePagesTest`, `PortalLabelTranslatorTest`
- [x] **T3**: the assessment form in steps (which work process, your judgement, a review step), `draft: {retentionDays: 30}`, a confirmation, the judgement as choice cards (`placement-steps-and-assessment-draft`, W2-8). Step titles and descriptions read in Dutch (PortalLabelTranslator reaches `steps`). The draft is saved by portaliq once portaliq #1152 (drafts) lands; the server keeps the key today. The werkproces is still picked by code, not by label (needs a werkproces list per placement; follow-up).
  - PHPUnit `PlacementPagesTest`; portaliq `PortalManifestNormaliser` keeps steps, draft and confirmation
- [x] **T3b**: the placement shows where it stands: five steps (agreement signed, work plan made, midterm review, final review, placement finished) read from the agreement's signatures, the visit reports and the placement; on the student's "Mijn stage" and the trainer's placement page (`steps` provider `bpvPlacementSteps`)
  - PHPUnit `BpvPlacementStepsTest` (Milan de Groot on 5 October 2026: done, done, current, todo, todo), `PlacementPagesTest`
- [x] **T3a**: the assessment posts to learniq's own endpoint, which records who assessed and at what assurance (`an-invited-trainer-may-assess`)
  - PHPUnit `PortalWerkprocesControllerTest`, `PortalWerkprocesAssessmentTest`
- [ ] **T5**: the trainer's open steps as portal tasks through OpenRegister's portal-task seam, raised and completed server-side; check first that a praktijkopleider subject is addressable
  - Not built in W2-8: the portal-task seam addresses a subject by its portal account, and no trainer account carries a task audience today; the placement steps (T3b) show the same moments without a task. Stays open.
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
