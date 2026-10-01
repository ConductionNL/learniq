# Tasks: each example set gets a themed portal

- [x] 1.1 `ExamplePortalProvisioner`: create, theme, keep or leave the portal of one set; a no-op without portaliq; never throws. Verify: PHPUnit `ExamplePortalProvisionerTest`.
- [x] 1.2 `SeedProfileService::install()` calls it after the import, not for the generated set. Verify: PHPUnit `SeedProfileServiceTest::testInstallImportsTheDescriptorUnderItsOwnConfigId`, `testInstallDelegatesTheGeneratedSetAndRefusesTheRest`.
- [x] 1.3 `occ learniq:example-set:portal <set>`. Verify: live on the integration instance after merge.
