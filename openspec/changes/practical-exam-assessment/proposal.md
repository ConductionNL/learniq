---
kind: spec
depends_on: [site-external-assessor-portal-design]
---

# Proposal: practical-exam-assessment

## Why

The assessor mockup (`LearniqAssessor.dc.html`) is an exam day: "U beoordeelt vandaag drie examens. Het eerste begint om 9.00 uur in lokaal P1.04", a table of time, candidate, exam, room and status, an assessment form that saves every step, a joint sign-off with the second assessor, and the documents for the day.

What learniq ships today carries almost none of that. `ExternalAssessor` is a person; `PortfolioShare` grants him a candidate's portfolio; `ExamSitting` plans a sitting with a period, an assessment, rooms and a headcount. **Nothing links an assessor to an exam.** That link is the heart of this change, and everything else in the mockup hangs off it: without it there is no "today", no list, no form that belongs to anybody, and no second assessor to sign with.

So the assessor's portal today is the one list he really has — the portfolios shared with him — and the exam day is proposed here rather than faked there.

## What changes

- **The link: `ExamAssessorAssignment`.** One assessor, one `ExamSitting`, one candidate, a role (`first`, `second`) and a lifecycle. It is a record of its own rather than a list on the sitting, because the portal scopes by it: `scopeField: externalAssessorId` on the assignment is a direct scope, the shape every other audience already uses.
- **The verdict: `PracticalExamAssessment`.** The candidate, the sitting, the assessment model used, the criteria scored, the outcome, and — as `WerkprocesAssessment` already does since learniq#1679 — who assessed and at which assurance level.
- **Saving every step.** The form stores a draft on each step, so a dropped connection loses nothing. That is a `lifecycle: draft` on the same record, not a separate autosave store.
- **The joint sign-off.** Both assessors sign the same assessment; the result reaches the exam board only when both have.
- **The documents.** The assessment model and the exam regulations are files on the sitting, read-only for the assessor.

## What the joint sign-off costs today, and the decision it needs

`Signature.subjectKind` is an enum with exactly one member: `learning-plan`. So today nothing but a learning plan can be signed with `Signature`, and a practical exam assessment cannot be. `PokSignature` is the other signature in the register, and it is about the praktijkovereenkomst: its fields (`subjectId`, `subjectVersion`, `signerRole`, `assuranceLevel`, `method`, `evidenceRef`) are exactly what a joint sign-off needs, but naming an exam assessment a "POK signature" would be a lie in the data.

Three ways out, and this proposal recommends the first:

1. **Widen `Signature.subjectKind`** with `practical-exam-assessment`, and let the two assessors sign through `Signature`. Adding an enum member is additive, and the field already carries the vocabulary. It costs one register version and a migration-free deploy.
2. Give `PracticalExamAssessment` its own two signer fields. Cheapest to build, worst to live with: a third signature shape in one app, which no report can read generically.
3. Generalise `PokSignature` into one signature record for everything. The honest end state, and far too large to ride along with an exam form.

Whichever is chosen, the assurance level of each signature MUST be recorded, as learniq#1679 established for assessments: an invited assessor signing with a school account is `basic`, and a school that wants `substantial` for a diploma-track exam sets its own floor.

## What this does not do

- It does not schedule exams. `ExamSitting` already does, and this change only points assessors at sittings that exist.
- It does not invent a declaration flow, although the mockup's footer links one ("Declaratie indienen"). There is no payments record for an external assessor, and inventing one here would hide that.
- It does not give the assessor any write access to a portfolio. His portal stays read-only apart from the assessment he is assigned to.
