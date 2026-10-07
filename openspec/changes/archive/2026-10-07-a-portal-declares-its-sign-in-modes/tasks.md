# Tasks: a-portal-declares-its-sign-in-modes

- [x] **T1**: `ExamplePortalProvisioner` writes `authentication.modes` from the profile's audiences, and leaves an existing portal's modes alone on a re-run
  - PHPUnit on the provisioner, from the caller
- [x] **T2**: `tests/e2e/helpers/portal-fixture.ts`: `offerSignInMode()` becomes `requireSignInMode()`, skipping with a named reason instead of writing
- [x] **T3**: the three audience suites stop restoring anything, because they change nothing
- [x] **T4**: the audience table in learniq's portal documentation names the sign-in mode each audience needs

## Done in example-portal-declares-its-site

- T1: the modes come from each set's declaration (`lib/Settings/portals/<set>.json`); an existing portal's modes are kept, a missing setting is filled. PHPUnit `ExamplePortalProvisionerTest`.
- T2, T3: `requireSignInMode()` skips with the portal's modes in the reason; the pupil, trainer and assessor suites restore nothing.
- T4: `docs/Integrations/index.md`, "Portaliq: who signs in how".
