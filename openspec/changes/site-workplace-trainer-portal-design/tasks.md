# Tasks: site-workplace-trainer-portal-design

Built in waves. A key is declared only once portaliq development keeps it; portaliq drops an unknown key without a word.

**Trust:** her reads are `minTrust: low` and her writes `substantial`. Only an OIDC broker mints `substantial` (portaliq `SessionController.php:666`), so a trainer who signs in by invitation or with a Nextcloud account can read her placements and assessments but cannot submit one. Whether every leerbedrijf gets eHerkenning is a decision for Ruben.

- [ ] **T1** (waits for portaliq `via.when`): `poLearners` over `learner-profile`, forward join on her placements with `via.when` (every state but `terminated`), names only. Without the filter a terminated placement would still reveal a name, which this change refuses; until then the trainer reads her placements without the pupils' names.
- [x] **T2**: `poWerkprocesAssessments`, direct scope on `assessorId`, with the judgement in words
  - PHPUnit `PortalContributionProviderTest`, `GuardianSitePagesTest`, `PortalLabelTranslatorTest`
- [ ] **T3** (waits for portaliq `steps`, `draft` and `confirmation` on an action, none of which is on development): the assessment form in steps, with a review step and a saved draft. `widget: choices` on the judgement can land with it.
- [ ] **T5**: the trainer's open steps as portal tasks through OpenRegister's portal-task seam, raised and completed server-side; check first that a praktijkopleider subject is addressable
  - PHPUnit for the step derivation
- [x] **T6a**: `TrainerSitePages`: the overview (`home: true`, `group`), her placements, her three latest assessments (`limit`, `sort`), two `cta` tiles and the inbox; a page per section
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own resolvers (development 29a17ba): nothing dropped
- [ ] **T6b** (waits for portaliq `display: cards` with `progress`, and a heading on a `collection` block): the student cards of the mockup. A collection block carries no label today, so the lists stand on their columns.
- [x] **T7**: the manifest through `PortalLabelTranslator`; Dutch "u" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T7b**: a one-line explanation beside each school term (praktijkovereenkomst, werkproces, BPV) on first use
- [ ] **T8**: mbo example set: one praktijkopleider with a portal account and two active placements
  - `python3 scripts/example-sets/mbo.py --check`
- [ ] **T9**: e2e: the trainer opens a student, saves an assessment halfway and finishes it later
  - `tests/e2e/mbo-trainer-flows.spec.ts`

## Follow-ups (not in this change)

- `bpv-hours-registration`: weekly hours per student, a target per placement, approval by the trainer.
- A decision on showing the school supervisor's name to the trainer.
- A planned-visit record for the school's workplace visit.
- The trainer's own company details as a portal page.
