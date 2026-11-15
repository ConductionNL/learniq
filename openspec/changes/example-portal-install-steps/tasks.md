# Tasks: example-portal-install-steps

- [x] **T1**: `docs/installation.md`: loads, repair and cron, organisation, issuer, portal accounts
- [x] **T2**: `ExamplePortalAccountGrants`: the load gives Noor, Milan, Aylin and Tom their portal account through portaliq #1381, once
  - PHPUnit `ExamplePortalAccountGrantsTest`
- [ ] **T3**: live: Tom signs in after a fresh spin-up (proof run 2)
- [x] **T4**: install guide after proof run 2: `loaClaim`/`loaMap` in the issuer recipe; manual install with the app id `learniq`, `npm ci`, the portaliq and thematiq builds, `appstoreenabled=false` first; no register wizard, no OpenConnector requirement, no `occ app:repair scholiq`; the repair's maintenance window; how to find portal ids and the organisation uuid
- [x] **T5**: the demo trainer Petra Bakker (mbo) gets a Nextcloud account and a `praktijkopleider` portal account with `practicalTrainerId` through the load (portaliq #1381); a real trainer keeps the invitation and eHerkenning
  - PHPUnit `ExamplePortalAccountGrantsTest::testTheDeclaredLearnersAreRealPeopleOfTheSet`, `ExamplePortalDeclarationsTest::testAccountsAndAudiencesExistInTheSet`
