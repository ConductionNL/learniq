# Tasks: each example set gets a themed portal

> Archive pass 2026-10-07: code done; open: 1.3 (live check: `occ learniq:example-set:portal po` on the integration instance, deferred to after merge in #1581).

- [x] 1.1 `ExamplePortalProvisioner`: create, theme, keep or leave the portal of one set; a no-op without portaliq; never throws. Verify: PHPUnit `ExamplePortalProvisionerTest`.
- [x] 1.2 `SeedProfileService::install()` calls it after the import, not for the generated set. Verify: PHPUnit `SeedProfileServiceTest::testInstallImportsTheDescriptorUnderItsOwnConfigId`, `testInstallDelegatesTheGeneratedSetAndRefusesTheRest`.
- [x] 1.3 `occ learniq:example-set:portal <set>`. Verify: live on the integration instance after merge.
