---
kind: spec
depends_on: [example-portal-declares-its-site]
---

# Proposal: example-portal-install-steps

## Why

Portal proof run 1 (06 Oct) installed the four school portals on a fresh instance. The loads worked, but four steps were nowhere written down (defects 15 and 16):

- `occ maintenance:repair` after the loads. The first repair still named 919 profiles, stamped 668 readable copies and saved 638 attendance summaries.
- Setting the portals' organisation.
- Setting the DigiD and eHerkenning issuer. There is no screen or API for it; the proof wrote portaliq's app config by hand.
- Giving pupils, students and course participants a portal account. Sign-in answered `no_portal_account` for Tom Verbeek. Participant-portal (#1763) does not create one, and portaliq had no way for an app to create an active `nextcloud` account until #1381.

## What changes

- `docs/installation.md` gets a section on loading the school sets from the command line, with the repair step, the organisation and the issuer.
- **The load gives the declared learners their portal account.** An account in `lib/Settings/portals/<set>.json` may carry `portal: {audience, claims}`. After the Nextcloud accounts, `ExamplePortalAccountGrants` asks portaliq for an active `nextcloud` account (portaliq #1381, `PortalAccountProvisionRequestedEvent` with `nextcloudUid` and `portal`) and then the `learnerRef` claim. Noor (vo), Milan and Aylin (mbo) get `student`, Tom (training) `participant`. A complete account is kept without asking portaliq anything, so a second load writes nothing. A portal without an organisation waits; an older portaliq is asked nothing.

## Not in this change

- Guardians, trainers and employers keep their invitations.
