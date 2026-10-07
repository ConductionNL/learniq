# Proposal: the guardian gets a mail when the school invites her

## Why

When the school invites a guardian, learniq asks portaliq for a waiting account on her verified address and writes the `learniq.guardianRef` claim on it (REQ-PID-004). Nothing is sent to her. She has to hear from the school that the portal exists, and she only reaches the account when her sign-in carries that same address. A sign-in through the integriq broker carries none, so she lands on an empty account and sees no child.

Portaliq can now mail a one-time link for a waiting account (portaliq change `invitation-secret-joins-the-signed-in-account`). A guardian who follows it and signs in, by any route, gets the waiting account joined into her own.

## What changes

- After the account is provisioned and the claim is written, learniq dispatches portaliq's `PortalAccountInvitationRequestedEvent` for it.
- The invite answer gains `invitation`: `sent`, `not-sent` (the mail did not leave) or `unavailable` (this portaliq sends none, or it refused).
- `occ learniq:portal:invite-guardian` says in one line what came of the mail.
- The link never comes back to learniq. Nobody at the school sees it.

## Not changed

- Who may invite, and who may be invited.
- The guardian is linked whether or not the mail leaves. A first sign-in that carries the verified address still finds the account.
- With a portaliq from before the event, the invitation works as it did and answers `invitation: unavailable`.
- No BSN is read or stored.
