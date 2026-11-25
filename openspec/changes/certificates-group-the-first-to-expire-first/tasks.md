# Tasks: certificates-group-the-first-to-expire-first

- [x] **T1**: `employerCertificates` declares `groupOrder: 'rows'` beside `groupByField: courseName` and `defaultSort: expiresAt asc`
  - Files: lib/Portal/EmployerSitePages.php, tests/Unit/Portal/golden/PortalManifests.json
  - Test: PHPUnit `EmployerSitePagesTest::testTheCertificateGroupsReadTheFirstToExpireFirst`, `PortalManifestGoldenTest`
- [ ] **T2**: Live: Linda's "Certificaten" on the warmtepompacademie portal shows F-gassen above BRL 6000-21 (learniq live lane; needs portaliq#2824 on the instance)
  - e2e tests/e2e/portal-design/warmtepompacademie.spec.ts
