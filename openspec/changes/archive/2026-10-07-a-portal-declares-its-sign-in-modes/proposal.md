---
kind: spec
depends_on: [site-pupil-portal-design]
---

# Proposal: a-portal-declares-its-sign-in-modes

## Why

A pupil, a workplace trainer and an external assessor all sign in to the portal with an account the school gave them, which is portaliq's `nextcloud` mode. A guardian signs in with DigiD. The example portal learniq provisions declares only `digid`, so the three audiences learniq built cannot reach the portal it ships for them — the manifest resolves, the menu draws, and the sign-in page offers them nothing they can use.

The live e2e suites work around that: `tests/e2e/helpers/portal-fixture.ts` adds `nextcloud` to the portal's `authentication.modes` before signing in and writes the original list back afterwards. It works, and it is verified to restore, but it is a test suite editing shared configuration. If a run is killed between the two writes, the portal keeps a mode nobody chose, and a mode list is exactly the kind of thing nobody looks at afterwards.

## What changes

- **The example portal declares what it offers.** `ExamplePortalProvisioner` writes the modes the profile's audiences actually need: `digid` and `nextcloud` for a school with pupils and BPV, `digid` alone where the profile has only guardians.
- **The suites stop editing it.** `offerSignInMode()` becomes a check that fails with a clear reason when the portal does not offer the mode, instead of adding it. A test that needs configuration it may not set should say so, not set it.
- **learniq documents which audience needs which mode**, next to the audiences themselves, so the next audience added does not rediscover this by having its sign-in button do nothing.

## What this does not do

It does not decide a school's security policy. A school may remove `nextcloud` from its own portal; that is its call, and the pupil's portal then has no sign-in route, which the setup wizard should say out loud. This change is only about the example a school starts from being coherent with the audiences learniq ships.
