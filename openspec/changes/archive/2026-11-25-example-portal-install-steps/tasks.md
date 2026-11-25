# Tasks: example-portal-install-steps

- [x] **T1**: `docs/installation.md`: loads, repair and cron, organisation, issuer, portal accounts
- [x] **T2**: `ExamplePortalAccountGrants`: the load gives Noor, Milan, Aylin and Tom their portal account through portaliq #1381, once
  - PHPUnit `ExamplePortalAccountGrantsTest`
- [x] **T3**: live: Tom signs in after a fresh spin-up (proof run 2)
  - Checked live 25 Nov on a fresh spin-up (NC 34.0.6 + PostgreSQL 16 + OpenRegister main + portaliq and thematiq development, installed by docs/installation.md: `appstoreenabled=false` first, the training set loaded, the portal's organisation set, the set loaded again, which gave Tom his portal account): Tom Verbeek signs in on warmtepompacademie and reads his next course day and his certificate (`tests/e2e/portal-design/warmtepompacademie.spec.ts` "MobielHome", desktop). The same run: website 3/3, Linda's MijnOverzicht and MijnLijst/Detail pass; Certificaten (order) red and the eHerkenning test skipped (no issuer), both outside this change
- [x] **T4**: install guide after proof run 2: `loaClaim`/`loaMap` in the issuer recipe; manual install with the app id `learniq`, `npm ci`, the portaliq and thematiq builds, `appstoreenabled=false` first; no register wizard, no OpenConnector requirement, no `occ app:repair scholiq`; the repair's maintenance window; how to find portal ids and the organisation uuid
- [x] **T5**: the demo trainer Petra Bakker (mbo) gets a Nextcloud account and a `praktijkopleider` portal account with `practicalTrainerId` through the load (portaliq #1381); a real trainer keeps the invitation and eHerkenning
  - PHPUnit `ExamplePortalAccountGrantsTest::testTheDeclaredLearnersAreRealPeopleOfTheSet`, `ExamplePortalDeclarationsTest::testAccountsAndAudiencesExistInTheSet`
