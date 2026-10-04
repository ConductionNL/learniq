---
kind: spec
depends_on: [site-guardian-portal-design]
---

# Proposal: permission-slips

## Why

The guardian mockup (`Main.dc.html`) puts two things under "Dit moet u nog doen". One is the parent conference, which learniq ships. The other is "Geef toestemming voor het schoolreisje — Groep 6 gaat op donderdag 22 oktober naar het Openluchtmuseum. Voor 16 oktober", and learniq has nothing behind it.

The register holds one consent record, `CourseShareConsent`, and it is about sharing course material between schools. There is no record of a school asking a guardian for permission, no answer, no deadline and nothing that tells a teacher who has not answered yet — which is the part a school actually needs the evening before a trip.

This is a small, self-contained feature with a real audience: every primary school runs it on paper today.

## What changes

- **The ask: `PermissionRequest`.** What is being asked (title, explanation, the date it is about), who it is for (a cohort, or named learners), the deadline to answer by, whether an unanswered request counts as a refusal, and a lifecycle (`draft`, `open`, `closed`).
- **The answer: `PermissionResponse`.** One per learner per request: yes or no, who answered, when, and at which assurance level — a guardian answering over DigiD is `substantial`, one answering from a school account is `basic`. A school that wants the higher level for a medical consent can then demand it, the way `bpv_assessment_min_assurance` already works for assessments.
- **The guardian's portal** gains the request as a task on her overview with its deadline, and an action to answer it for her own child. Both scope through her existing guardian claim, so no new scoping shape is introduced.
- **The teacher's screen** gains the one list the paper slip never gives: who has answered yes, who has answered no, and who has not answered at all, per request.

## Decisions this proposal takes, so a reviewer can reject them cheaply

1. **One response per child, not per guardian.** Two guardians answering differently is a conflict for the school to resolve, not for the record to hold twice. The response names which guardian answered.
2. **An unanswered request is not a yes.** The default is that silence means no, with `treatSilenceAsRefusal` on the request so a school can say otherwise for something harmless.
3. **No payment.** A school trip often costs money, and `payments` is its own capability in this app. A permission slip that quietly became an invoice would be the wrong thing to build here.
4. **No file.** The mockup asks a yes-or-no question. A request that needs a signed form is a signature, which is the exam change's problem, not this one's.

## What this does not do

It does not touch attendance. A child whose guardian refuses the trip is at school that day and belongs in the normal register, and connecting the two would hide a decision a mentor should make.
