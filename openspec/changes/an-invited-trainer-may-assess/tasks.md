# Tasks: an-invited-trainer-may-assess

- [x] **T1**: register 0.34.37: `WerkprocesAssessment` 0.3.0 gains `assessorName`, `assessorCompany`, `assessorCompanyKvkNumber` and `assuranceLevel`
  - `npm run check:register`; PHPUnit `PortalWerkprocesAssessmentTest::testTheStoredRowPassesTheRealSchema`
- [x] **T2**: `createWerkprocesAssessment` becomes an `endpoint-forward` at `minTrust: low`, posting to `/apps/learniq/api/portal/werkproces-assessments`
  - PHPUnit `PortalContributionProviderTest`, `GuardianSitePagesTest`
- [x] **T3**: `PortalWerkprocesController` verifies the assertion, takes the trainer from its claim and whitelists the form's fields
  - PHPUnit `PortalWerkprocesControllerTest`, over the real verifier
- [x] **T4**: `PortalWerkprocesAssessment` stamps the identity and the assurance, checks the placement is hers, and honours the school's floor
  - PHPUnit `PortalWerkprocesAssessmentTest`
- [ ] **T5**: the trainer's form in steps with a draft, once portaliq accepts `steps`, `draft` and `confirmation` on an action
- [ ] **T6**: a live check on an instance with a praktijkopleider portal account (the mbo example set)

## Waiting on Ruben

- The POK signature keeps `minTrust: substantial`, so an invited trainer still cannot sign a praktijkovereenkomst, and `PokActivationGuard` needs that signature before a placement activates. Lowering it is a separate decision (see the proposal).
