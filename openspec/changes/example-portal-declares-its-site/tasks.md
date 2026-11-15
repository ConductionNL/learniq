# Tasks: example-portal-declares-its-site

- [x] **T1**: `ExamplePortalDeclarations` reads `lib/Settings/portals/<set>.json`; declarations for po, vo, mbo, training from the boards
  - PHPUnit `ExamplePortalDeclarationsTest`, `ExamplePortalProvisionerTest::testEveryDesignedSchoolDeclaresItsSite`
- [x] **T2**: `ExamplePortalProvisioner` writes portal, menus, pages and news once; fills empty portal fields; leaves an older set's portal alone
  - PHPUnit `ExamplePortalProvisionerTest`
- [x] **T3**: `ExampleThemeResolver`: designed theme when thematiq ships it, else the fallback
  - PHPUnit `ExamplePortalProvisionerTest`
- [x] **T4**: `ExampleAccountProvisioner` and `occ learniq:example-set:load <set> [--no-accounts]`
  - PHPUnit `ExampleAccountProvisionerTest`
- [ ] **T5**: live: load a set on a second portal slug on the shared instance, and the four sets on the fresh spin-up
