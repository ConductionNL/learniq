---
kind: code
depends_on: [site-workplace-trainer-portal-design, site-external-assessor-portal-design]
---

# Proposal: invite-a-trainer-and-an-assessor

## Why

The trainer's and the assessor's portals are built, but nobody can sign in to them. learniq ships `occ learniq:portal:invite-guardian` and a guardian invite endpoint, and nothing else. Without an invitation there is no portal account, and without the account's claim (`practicalTrainerId`, `externalAssessorId`) every collection resolves nothing, so the portal would open on an empty page even for a correct account.

The assessor has a second problem: the vocational example set seeds no `ExternalAssessor` and no `PortfolioShare` at all, so even a working account would show him nothing to assess.

## What changes

- **`occ learniq:portal:invite-trainer <praktijkopleiderRef> <organisation> [email]`** and **`occ learniq:portal:invite-assessor <externalAssessorRef> <organisation> [email]`**. Both mirror the guardian invitation: portaliq provisions the account, learniq writes the claim.
- **`BpvPortalInvitation`** holds both roles. It asks portaliq for an account on the role's audience, then writes the claim the role's collections are scoped by.
- **The vocational set seeds the assessor's work:** one active `ExternalAssessor`, two `Portfolio` rows of students who are really on a placement, a `PortfolioEntry` each, and two active `PortfolioShare` rows that already carry the readable copies the server stamps.

## An invitation names a person the school already created

The uuid must resolve to a row in `praktijkopleider` or `external-assessor` that the school created and has not switched off. The invitation never mints one, and a uuid that names nothing is refused with `person-unknown`.

The address is the one on that row. A caller may pass another address, for a correction, but never skip it: an address that is not an address is refused before any event is dispatched.

## Why the claim is the part that matters

A portal account without the claim looks healthy and reads nothing: the collections are scoped by `scopeClaim`, and an absent claim resolves to no rows rather than to an error. A test therefore compares the claim each invitation writes with the `scopeClaim` every collection of that audience declares, so the two cannot drift apart.

## Not in this change

- An HTTP invite endpoint for either role. The guardian has one because the school's own screens call it; these two are invited by an administrator, so the command is enough until a screen needs more.
- The e2e for either portal. It needs the set loaded on an instance, which is the coordinator's step.
