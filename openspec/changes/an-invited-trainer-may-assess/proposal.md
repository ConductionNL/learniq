---
kind: code
depends_on: [site-workplace-trainer-portal-design]
---

# Proposal: an-invited-trainer-may-assess

## Why

Ruben decided on 4 October 2026: "Let an invited trainer assess too."

A werkproces assessment asked for `minTrust: substantial`, and only an OIDC broker mints that (portaliq `SessionController.php:666`). A praktijkopleider is not a DigiD citizen, and not every leerbedrijf has eHerkenning, so a trainer who signed in from her invitation could read her students but never record what she had just watched them do. The school then had no assessment at all, which is worse evidence than one whose assurance it knows.

## What changes

- **The bar drops.** `createWerkprocesAssessment` asks for `minTrust: low`, so an invited or Nextcloud-signed-in trainer may submit.
- **The evidence carries its own weight.** Every assessment now records who assessed and how sure the school is of that: `assessorName`, `assessorCompany`, `assessorCompanyKvkNumber` and `assuranceLevel`. The server writes all four; a client value is dropped.
- **The write moves to learniq's own endpoint.** A portal `create` writes straight into OpenRegister and carries no assertion, so nothing in learniq can see how the trainer signed in. A forward does carry the signed assertion, and the assertion holds `trust`. The action becomes an `endpoint-forward` to `/apps/learniq/api/portal/werkproces-assessments`.
- **A school can still demand eHerkenning.** `bpv_assessment_min_assurance` (default `basic`) is the floor. Set it to `substantial` and a session below it is refused, with the required level named.

## Why an invitation is enough to carry a diploma-track assessment

The invitation is not anonymous. A school invites a **named person at a named leerbedrijf**: the `Praktijkopleider` record holds `givenName`, `familyName`, `email`, `trainingCompanyName` and `trainingCompanyKvkNumber`, and the school created that record before inviting. The portal subject resolves to exactly that record, so the server knows who is writing without asking the browser.

So the assessment no longer rests on the strength of the sign-in alone. It rests on:

1. the school having named this person at this company;
2. the assessment being readable afterwards as hers, with her company and its KvK number on the row;
3. the row saying plainly how sure the school may be: `basic` for an invitation or a Nextcloud account, `substantial` for eHerkenning or DigiD.

A school that wants the stronger guarantee raises the floor. The point of the change is that the school chooses, and that the choice is visible on every row afterwards.

## Not changed

- **The POK signature keeps `minTrust: substantial`.** Signing a praktijkovereenkomst is a contract signature, not an assessment, and Ruben's decision named assessing. `PokSignature` already records its own `assuranceLevel`, so the two acts can be told apart later.
  **Consequence for Ruben:** `PokActivationGuard` needs a praktijkopleider signature before a placement becomes active, so a trainer who only ever signs in by invitation still cannot activate a POK. Lowering that bar is a separate decision, and this change does not take it.
- Who may read what. The trainer's reads were already `low`.
