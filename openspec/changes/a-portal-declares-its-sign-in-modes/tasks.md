# Tasks: a-portal-declares-its-sign-in-modes

- [ ] **T1**: `ExamplePortalProvisioner` writes `authentication.modes` from the profile's audiences, and leaves an existing portal's modes alone on a re-run
  - PHPUnit on the provisioner, from the caller
- [ ] **T2**: `tests/e2e/helpers/portal-fixture.ts`: `offerSignInMode()` becomes `requireSignInMode()`, skipping with a named reason instead of writing
- [ ] **T3**: the three audience suites stop restoring anything, because they change nothing
- [ ] **T4**: the audience table in learniq's portal documentation names the sign-in mode each audience needs
