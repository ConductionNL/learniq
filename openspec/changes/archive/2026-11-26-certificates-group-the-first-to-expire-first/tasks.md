# Tasks: certificates-group-the-first-to-expire-first

- [x] **T1**: `employerCertificates` declares `groupOrder: 'rows'` beside `groupByField: courseName` and `defaultSort: expiresAt asc`
  - Files: lib/Portal/EmployerSitePages.php, tests/Unit/Portal/golden/PortalManifests.json
  - Test: PHPUnit `EmployerSitePagesTest::testTheCertificateGroupsReadTheFirstToExpireFirst`, `PortalManifestGoldenTest`
- [x] **T2**: Live: Linda's "Certificaten" on the warmtepompacademie portal shows F-gassen above BRL 6000-21 (learniq live lane; needs portaliq#2824 on the instance)
  - e2e tests/e2e/portal-design/warmtepompacademie.spec.ts: `Certificaten` 2/2 (desktop, phone) twice on NC 34.0.6 + PostgreSQL 16, OpenRegister main, portaliq development (with #2824), training set. The order held from the first run; the row links read the bare address, so the column now carries `linkLabel: 'Check'` ("Controleren")
  - Test: PHPUnit `EmployerSitePagesTest::testEachCertificateRowLinksToItsCheckPageWithAWord`, `ParticipantSitePagesTest::testHisCertificatesLinkToTheirCheckPageWithAWord`, `PortalManifestGoldenTest`
