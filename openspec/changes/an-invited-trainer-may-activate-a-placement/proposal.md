---
kind: spec
depends_on: [an-invited-trainer-may-assess]
---

# Proposal: an-invited-trainer-may-activate-a-placement

## Why

learniq#1679 lowered one bar and deliberately left the other. An invited trainer may now write a werkproces assessment at `minTrust: low`, because a praktijkopleider is not a DigiD citizen and not every leerbedrijf has eHerkenning. Signing the praktijkovereenkomst stayed at `minTrust: substantial`, and the live e2e asserts that it still does (`trainer-flows.spec.ts`, step e: her POK signature is refused with 403).

The consequence is visible in the trainer's portal: the overview offers her "Praktijkovereenkomst ondertekenen", and for an invited trainer that button can only ever refuse. A placement therefore waits for a signature she cannot give, and the school chases it by e-mail — which is exactly the paper detour the portal exists to remove.

This change proposes to lower that bar the same way, and with the same honesty: not by pretending the sign-in was stronger, but by recording what it really was.

## Why this is defensible, and where it is not

A POK is an agreement between a school, a student and a leerbedrijf. What makes the trainer's half of it evidence is not the strength of her login; it is that **the school named this person at this company before inviting her** — her `Praktijkopleider` record carries `givenName`, `familyName`, `email`, `trainingCompanyName` and `trainingCompanyKvkNumber`, and the portal subject resolves to that record, server side.

`PokSignature` already has the field that keeps this honest: `assuranceLevel`, an eIDAS enum that is `required`. So a signature made from an invitation says `basic` on its own row, for as long as the row exists, and a school reading its signatures later can tell them apart without asking anybody.

Where it is **not** defensible is a school that must have eHerkenning for a POK — some do, by their own policy or their auditor's. That is why the floor stays configurable and is read on every write, exactly like `bpv_assessment_min_assurance`.

## What changes

- `signPraktijkovereenkomst` asks for `minTrust: low` instead of `substantial`.
- The write moves from a portal `create` to learniq's own endpoint, for the reason learniq#1679 established: a portal create carries no assertion, and the assertion is the only place the session's assurance can be read. A `create` that stamped `assuranceLevel` from the client would be worthless.
- The server writes `signerId`, `signerRole`, `signedAt`, `method` and `assuranceLevel` from the assertion and the trainer's own record; a client value for any of them is replaced.
- A new school setting, `bpv_pok_min_assurance`, default `basic`. A school that sets it to `substantial` refuses a signature below it, and the refusal names the level required so the trainer is told what to do instead of being told no.
- The placement's own transition to `active` stays where it is; this change only makes the signature that unblocks it reachable.

## Why this is proposed as a spec rather than built in this PR

It is small — the shape of learniq#1679, one step smaller, because the record and its `assuranceLevel` already exist. It is also a diploma-track signature, and the last time this bar moved it needed Ruben's own decision. So it is written out to be read first, and it stands alone: nothing in the other three proposals depends on it, and building it is one branch of its own.
