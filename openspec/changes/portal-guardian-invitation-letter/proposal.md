# Proposal: the school can invite a guardian by letter

## Why

An invited guardian now gets a mail with a one-time link (`portal-guardian-invitation-mail`). Not every guardian reads mail, and a school sends letters home anyway. Portaliq can make a short one-time code for a waiting account (portaliq change `invitation-code-from-a-letter`). The guardian signs in, types the code under "My account", and sees her child.

## What changes

- The invitation takes a channel: `mail` (the default) or `letter`.
- On `letter` learniq asks portaliq for a code instead of a mail. The answer carries `invitation: code`, the code and its expiry. The school prints the code in a letter.
- `POST /api/portal/guardians/{guardianRef}/invite` takes `channel`. `occ learniq:portal:invite-guardian` takes `--letter` and prints the code.
- An unknown channel is refused with `channel-unknown` before anything is dispatched.

## Who sees the code

The administration that sends the letter: the same people who may invite (`admin`, `administration-managers`). A mailed link is still seen by nobody at the school, and stays the first choice.

## Not changed

- The invitation still needs an e-mail address the school verified, also for a letter. Portaliq refuses a waiting account without an address or an identity. A guardian without any address cannot be invited yet.
- A portaliq without the code answers `invitation: unavailable`, and the guardian stays linked on the verified address.
- No BSN is read or stored.
