# Tasks: site-workplace-trainer-portal-design

Specs only so far. Build starts after Ruben approves the specs and portaliq names the keys it accepts.

- [ ] **T1**: `poLearners` over `learner-profile`, forward join on her placements with `via.when` (every state but `terminated`), names only
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T2**: `poWerkprocesAssessments`, direct scope on `assessorId`
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T3**: the assessment form: student by name, werkproces by label from the kwalificatiedossier, codes filled server-side; `steps` with `description`, a `review: true` step, `draft: { retentionDays: 30 }`, `confirmation`, `widget: choices` on the judgement
  - PHPUnit for the werkproces options and the server fill
- [ ] **T5**: the trainer's open steps as portal tasks through OpenRegister's portal-task seam, raised and completed server-side; check first that a praktijkopleider subject is addressable
  - PHPUnit for the step derivation
- [ ] **T6**: `TrainerPortalPages`, with the student cards (`display: cards`, period lookups, no `progress`): overview (`home: true`), Mijn studenten, Beoordelingen, under `group: Mijn omgeving`; default pages `menu: false`
  - PHPUnit for the new class
- [ ] **T7**: the manifest through `PortalLabelTranslator`; Dutch "u" entries with term explanations
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T8**: mbo example set: one praktijkopleider with a portal account and two active placements
  - `python3 scripts/example-sets/mbo.py --check`
- [ ] **T9**: e2e: the trainer opens a student, saves an assessment halfway and finishes it later
  - `tests/e2e/mbo-trainer-flows.spec.ts`

## Follow-ups (not in this change)

- `bpv-hours-registration`: weekly hours per student, a target per placement, approval by the trainer.
- A decision on showing the school supervisor's name to the trainer.
- A planned-visit record for the school's workplace visit.
- The trainer's own company details as a portal page.
