# Tasks: portal-certificates

- [x] **T1**: credential copies `learnerName`, `courseName`, `organisationRef`, `validUntilLabel`, `renewalLine` (CertificateCopies, readable-copy stamp)
  - PHPUnit `CertificateCopiesTest`
- [x] **T2**: calculations `weeksUntilExpiry`, `expiryLabel`; credential 0.3.4, register 0.37.0
  - PHPUnit `CertificateCopiesTest::testTheExpiryLineReadsInWeeks`; evaluated once with OpenRegister's own `CalculationEvaluator` (development 1dc6a466): 56 days "Verloopt over 8 weken", 8 days "Verloopt over 1 week", 3 days "Verloopt deze week", 524 days "Geldig", -34 days "Verlopen", none "Geldig"
- [x] **T3**: `employerCertificates`, the overview rows and the Certificaten page
  - PHPUnit `EmployerSitePagesTest::testHerPeoplesCertificatesExpireFirstOnTop`; portaliq `PortalManifestNormaliser` drops nothing of it
- [x] **T4**: the training set's copies
  - `python3 scripts/example-sets/training.py --check`; PHPUnit `CertificateCopiesTest::testTheSeededCertificatesAgreeWithTheServer`
- [x] **T5**: Dutch for the labels and schema strings
- [ ] **T6**: e2e: the certificates on Linda's overview (`tests/e2e/portal-design/warmtepompacademie.spec.ts`, runs on the proof instance)
