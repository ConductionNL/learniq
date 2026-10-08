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

## Who issued it, and where (security review L5)

- Every invitation that goes out is recorded in Nextcloud's audit log (`admin_audit`) and the app log: who issued it (the staff user's uid, or `occ`), the guardian, the channel and the organisation. Never the code or the link.
- The organisation is no longer a free parameter on the endpoint. A portal organisation slug is the slug of an OpenRegister organisation, so the caller must belong to that organisation in OpenRegister. Otherwise the answer is `403 organisation-not-yours` and nothing is dispatched. Without OpenRegister nobody belongs anywhere and every invitation is refused. The `occ` command runs as the server operator and keeps the organisation as an argument.

## Not changed

- The invitation still needs an e-mail address the school verified, also for a letter. Portaliq refuses a waiting account without an address or an identity (portaliq REQ-PIS-001), so learniq refuses a letter without a valid address before it asks. Issuing the code itself needs no address on portaliq's side; lifting REQ-PIS-001 is a separate decision. A guardian without any address cannot be invited yet.
- A portaliq without the code answers `invitation: unavailable`, and the guardian stays linked on the verified address.
- No BSN is read or stored.
