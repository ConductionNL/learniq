---
kind: code
depends_on: [employer-portal-audience]
---

# Proposal: employer-signs-in-with-eherkenning

## Why

The academy's employer signs in with eHerkenning (board Inloggen: "Als werkgever ... Inloggen met eHerkenning"; school portal plan W2-7). Three things were missing between an invitation and a working session:

- portaliq minted an eHerkenning session with the preset's audience `supplier`, not the account's `employer`, and named no company in the session. Fixed in portaliq #1211 (`the-account-names-the-audience-and-the-company`).
- learniq could invite an employer only through occ; the institute's screens and the proof run need the same over HTTP, as for a guardian.
- No proof that the whole route works: invitation, eHerkenning sign-in through the stub, the employer's own portal with her company in the chip.

## What changes

- **`POST /api/portal/employers/{organisationRef}/invite`** (`PortalEmployerInviteController`): for the administration (`admin`, `administration-managers`, `hr`), into a portal organisation the caller belongs to; the same `EmployerPortalInvitation` as the occ command. The account is provisioned with identity type `eherkenning` and the company's `eherkenningRef`, so the eHerkenning sign-in finds it; portaliq #1211 keeps its `employer` audience and shows `organisationName`.
- **e2e** (`warmtepompacademie.spec.ts`): with `PORTAL_DESIGN_EHERKENNING_ISSUER` the spec invites Jansen, signs Linda in through the stub with the company's eHerkenning reference, and checks her overview and the company in the chip.

## The spin-up (what a fresh instance needs; no secret is written by learniq)

1. `occ learniq:example-set:load training`.
2. portaliq app config of the portal's organisation: `org_presentation_<organisation uuid>` gains `"oidc": {"eherkenning": {"issuer": "http://host.docker.internal:<port>", "clientId": "warmtepompacademie-portal"}}` beside the DigiD entry, and `oidc_secret_<organisation uuid>_eherkenning` any value (the stub ignores it).
3. Run the spec with `PORTAL_DESIGN_EHERKENNING_ISSUER` set to the same issuer.

For the vocational portal the same issuer serves the workplace trainer: the trainer's invitation (`occ learniq:portal:invite-trainer`) creates an account by e-mail, and portaliq joins it on the first sign-in whose verified e-mail matches; its audience `praktijkopleider` wins over the preset.

## Not in this change

- An eHerkenning reference on the workplace trainer's record (the e-mail join covers the proof run).
- Assurance levels per action: reads and writes stay `low` as decided in `employer-portal-audience`.
