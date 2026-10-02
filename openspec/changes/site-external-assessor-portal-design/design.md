# Design: site-external-assessor-portal-design

## Design of record

`LearniqAssessor.dc.html` in the portal-design canvas (artifact `3Jy3r5e5f9v9ktCLxisNG6`): the examenportaal of Esdoorn Techniek College for external assessor Ruud Jansen.

## Mockup to declaration

| Mockup element | This change | Data |
|---|---|---|
| "U heeft toegang tot en met vrijdag 16 oktober" | shown | latest `expiresAt` of his active shares |
| "U ziet alleen de kandidaten die u beoordeelt" | shown | true by scope: only his shares |
| Exam day table | not in this change | no assessor-exam link |
| Candidate name | shown per share (NEW) | `PortfolioShare.learnerName` stamp |
| "Beoordeling invullen" | not in this change | audience is read-only |
| "Opdracht bekijken" | shared portfolio entries (NEW) | `portfolio-entry` via `entryIds` |
| "Zo beoordeelt u" steps | not in this change | describes the missing form |
| "Documenten voor vandaag" | not in this change | no exam document link |
| "Declaratie indienen" | not in this change | finance |

## Readable shares (NEW)

The portal resolves one `via` hop. The share points at a portfolio, the portfolio at a learner. The assessor needs the candidate's name on the share itself. Two strings are stamped on create, the pattern `ReportCard.periodName` and the guardian change's `GradeEntry.courseName` use:

- `portfolioTitle` from `Portfolio.title`.
- `learnerName` from the learner's `givenName` and `familyName`.

They are copies for display. They are never read back as identity. A client cannot write them. A share made before the stamp existed shows "Kandidaat" until the stamp is back-filled, which is a task.

## Shared entries (NEW)

```
eaSharedPortfolioEntries
  schema: portfolio-entry      scopeField: id      scopeClaim: externalAssessorId
  via: { schema: portfolio-share, scopeField: sharedWithExternalAssessorId, targetField: entryIds,
         when: { field: lifecycle, in: [active] }, validUntilField: expiresAt }
  fields: portfolioId, title, evidenceKind, attachmentRef, reflectionText
```

The forward join keeps an entry whose own id is in the `entryIds` of one of his shares. A share that grants the whole portfolio (empty `entryIds`) needs a second collection joined on `portfolioId` with `match: scopeField`. Both declare `via.when: { field: lifecycle, in: [active] }` and `via.validUntilField: expiresAt` (portaliq REQ-SMO-023), so a `revoked` share or one past `expiresAt` grants nothing. An empty `expiresAt` grants, as the contract says. A malformed filter fails the join closed. The entries collection ships only after portaliq's wave 1 lands that filter.

`submissionId`, `werkprocesAssessmentId`, `externalTrainingRecordId` and `credentialId` stay out. They point at records the assessor has no scope on.

## The access date

`expiresAt` lives on each share, not on the assessor. The mockup's sentence "U heeft toegang tot en met <date>" needs a text with a value from data. Portaliq's contract has no block for that: a `richText` block is fixed text. The overview therefore shows "Uw toegang" as a `collection` block over `eaSharedPortfolios`, sorted on `expiresAt` descending, limit 1. Where a share without an end date sorts is portaliq's choice, so the mockup's "the school ends the access" sentence is not declared. Raised with lane pq. The scope itself does not depend on the notice: an expired share must already resolve no rows (see above).

## Proposed follow-up: mbo-practical-exam-assessment

Out of this change, sketched so Ruben can decide whether to propose it:

- A practical exam record (proeve van bekwaamheid): programme, kwalificatiedossier part, date, time, room, candidate, two assessors (an internal one and an external one).
- An assessment record per assessor, with the school's assessment model, written in the portal at `substantial` trust.
- Joint sign-off: a `signature` subject kind for the exam result, signed by both assessors.
- Exam documents linked to the exam and readable by its assessors for the exam's day.
- Access per assessor bounded by the exam period, not by a portfolio share.
