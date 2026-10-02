# Tasks: site-workplace-trainer-portal-design

Specs only so far. Build starts after Ruben approves the specs and portaliq names the keys it accepts.

- [ ] **T1**: `poLearners` over `learner-profile`, forward join on her placements, names only, terminated placements excluded
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T2**: `poWerkprocesAssessments`, direct scope on `assessorId`
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T3**: the assessment form: student by name, werkproces by label from the kwalificatiedossier, codes filled server-side
  - PHPUnit for the werkproces options and the server fill
- [ ] **T4**: an update action on her own draft assessments (`rowWhen lifecycle in [draft]`)
  - PHPUnit `PortalRowActionConditionsTest`
- [ ] **T5**: the trainer's open steps, server-side, from placements, signatures and her assessments
  - PHPUnit for the step derivation
- [ ] **T6**: `TrainerPortalPages`: overview, Mijn studenten, Beoordelingen, Berichten
  - PHPUnit for the new class
- [ ] **T7**: the manifest through `PortalLabelTranslator`; Dutch "u" entries with term explanations
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T8**: mbo example set: one praktijkopleider with a portal account and two active placements
  - `python3 scripts/example-sets/mbo.py --check`
- [ ] **T9**: e2e: the trainer opens a student and saves an assessment as a draft
  - `tests/e2e/mbo-trainer-flows.spec.ts`

## Follow-ups (not in this change)

- `bpv-hours-registration`: weekly hours per student, a target per placement, approval by the trainer.
- A decision on showing the school supervisor's name to the trainer.
- A planned-visit record for the school's workplace visit.
- The trainer's own company details as a portal page.
