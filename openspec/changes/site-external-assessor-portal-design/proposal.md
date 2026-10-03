---
kind: code
depends_on: [site-guardian-portal-design]
---

# Proposal: site-external-assessor-portal-design

## Why

Ruben approved the external assessor's day view, `LearniqAssessor.dc.html`, on 2026-10-02. The persona is `ruud-jansen` in hydra `personas/`: a freelance examiner with temporary access, on an iPad in a workshop with poor wifi.

The mockup is an exam day: a table of candidates with time, exam, room and status, an assessment form that keeps every step when the connection drops, joint sign-off with a second assessor, the documents of the day, and a declaration.

What the `external-assessor` audience offers today (`PortalContributionProvider::externalAssessorContribution()`):

- `eaSharedPortfolios`: active `portfolio-share` rows granted to him, with `portfolioId`, `entryIds`, `sharedBy`, `expiresAt` and `lifecycle`. Pointers only. The provider docblock flags reading the shared content as a follow-up.
- No actions. The audience is read-only by design (eportfolio spec, "external-assessor sharing").
- No pages and no Dutch labels.

None of the exam-day features exist in learniq. No schema links an external assessor to an exam, a candidate, a time or a room. `exam-sitting` plans written exams in a test week for cohorts and invigilators. It has no assessor and no candidate. No schema holds a practical exam (proeve van bekwaamheid), a second assessor, a joint sign-off or a declaration.

So this change does two things. It designs the part of the mockup that today's data supports: what has been shared with him, about whom, and until when. It lists the exam day itself as not in this change, with a proposed follow-up.

## What changes

Existing data, new declarations:

- **An overview** `eaOverview`, "Overzicht", with `home: true`: the access notice and the candidates whose work is shared with him.
- **The access sentence**, as in the mockup: "U heeft toegang tot en met {expiresAt}." with `whenEmpty: { expiresAt: "U heeft toegang zonder einddatum." }`, a `richText` `template` block (REQ-SMO-027) on each share's record page. The overview keeps a short "Uw toegang" list of his latest-ending share, plus the fixed line "U ziet alleen de kandidaten die u beoordeelt."
- **A short menu** under `group: Mijn omgeving`: Overzicht, Gedeeld met mij. Berichten is portaliq's own inbox entry. The default collection pages get `menu: false`.
- **Dutch labels** through `PortalLabelTranslator`, in the "u" form.

New work, clearly marked in the specs:

- **NEW: readable shares.** `portfolio-share` gains server-stamped copies `portfolioTitle` and `learnerName`, written when the share is created. The portal joins one hop at most, and the candidate's name is two hops away (share to portfolio to learner).
- **NEW: the shared content.** `eaSharedPortfolioEntries` over `portfolio-entry`, joined forward on `entryIds` of his active shares, projecting title, kind, and the file or reflection. An entry not in a share's selection MUST NOT show.

## Depends on

Portaliq (lane pq; referenced, not respecified):

- `site-mijn-omgeving-components` (portaliq PR #1110): the page keys `group`, `menu: false` and `home: true`; the candidate list and the file item. And `via.when` with `via.validUntilField` (REQ-SMO-023, wave 1 of that change), so a revoked or expired share resolves no entries.
- Not offered by that contract: a `template` outside a record page. REQ-SMO-027 fills a template from the open record, so the mockup's single sentence on the overview, over all his shares, cannot be declared. The overview shows "Uw toegang" as a `collection` block over `eaSharedPortfolios` with `sort: { field: expiresAt, direction: desc }` and `limit: 1`; each share's page carries the sentence.

Learniq:

- `site-guardian-portal-design` for the menu and block keys it asks portaliq for.

## Not in this change

Proposed together as one follow-up, `mbo-practical-exam-assessment`, because they share one missing model (a practical exam with its candidates and assessors):

- **The exam day table** (time, candidate, exam, room, status). No schema links an assessor to an exam or a candidate. `exam-sitting` has no assessor or candidate.
- **"Beoordeling invullen"**, the assessment form. The audience has no actions, on purpose. Writing a judgement on a diploma-track exam needs `minTrust: substantial`, an assessment model and a rule for who may write it.
- **Offline tolerance** ("Het formulier bewaart elke stap. U raakt niets kwijt als de verbinding wegvalt."). It belongs to the form above. Portaliq's `site-multi-step-forms` saves per step on the server; keeping answers on the device while offline is a further portaliq requirement to raise when the form exists.
- **Joint sign-off with the second assessor.** No signature subject covers an exam result. `signature.subjectKind` allows `learning-plan` only.
- **Documents for today** (assessment model, exam rules). No schema ties a document to an exam for an assessor. Shared portfolio files are in this change; exam documents are not.
- **"Declaratie indienen"**, a declaration for his hours. That is finance, not learniq.
- **The exam office phone number.** That is school content on the site, edited in portaliq.

## Impact

- `lib/Portal/PortalContributionProvider.php`, a new `lib/Portal/AssessorPortalPages.php`, `lib/Portal/PortalLabelTranslator.php`, `l10n/nl.json`.
- `lib/Settings/learniq_register.json`: `PortfolioShare.portfolioTitle`, `PortfolioShare.learnerName`, and a server stamp that fills them.
- No new action. The audience stays read-only.
