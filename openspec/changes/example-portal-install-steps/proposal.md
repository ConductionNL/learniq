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
- Giving pupils, students and course participants a portal account. Sign-in answered `no_portal_account` for Tom Verbeek. Participant-portal (#1763) does not create one.

## What changes

- `docs/installation.md` gets a section on loading the school sets from the command line, with the repair step, the organisation, the issuer and the portal accounts.

## Not in this change

- No code that creates a portal account for a pupil, student or participant. portaliq's provision event (`PortalAccountProvisionRequestedEvent`) mints a pending account with a new subjectRef, and the `nextcloud` sign-in needs an active account whose subjectRef is the Nextcloud user id. Writing portaliq's register directly from learniq would bypass portaliq's own rule that such accounts are never created automatically. That needs a portaliq change first.
