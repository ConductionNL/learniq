# Design: segment-example-datasets-corporate

The descriptor contract is `openspec/changes/segment-wizard-choice/contract.md`; the pattern is `segment-example-datasets-po`.

## Architecture Overview

```
scripts/example-sets/corporate.py  (deterministic, seed 20250901, stdlib only)
   │  calendar: 2025-08-18 .. 2026-07-10, working days minus public holidays and the Christmas closure
   │  departments → people (200, 16 of them start during the year) → profiles, department cohorts, Staff
   │  certificates held before the year → expiries inside it → renewal enrolments (credential-renewal)
   │  renewals and new starters → first training group after the expiry or start → attendance → new certificate
   │  e-learning campaigns → lesson completions → completion → certificate
   │  certificates and completions → attainments → skills gaps → plan goals
   │  completions → point awards → totals and levels; paid enrolments → orders, payments, entitlements
   ▼
lib/Settings/profiles/corporate.json   one object per line, strict JSON, 5815 objects
   │
   ├─ ExampleSetDescriptorContractTest   (loads: shape, namespace, references, schema validity)
   ├─ CorporateExampleSetTest            (story: renewals, cohorts, gaps and goals, points, payments, removal list)
   └─ SeedProfileService / wizard        (offered as "Company", loaded, removed by occ)
```

## Decisions

### Decision 1: a generator, committed next to the set
Same reason as the primary school set: consistency is the product. A renewal starts the day after the old certificate expires, lands in the first training group after that day, and issues the new certificate at the end of that group's last session. Across 243 renewals, 1026 credentials and 625 point awards that only holds when one program derives all of it. `--check` compares the output with the file, and `CorporateExampleSetTest` runs it whenever `python3` is available.

### Decision 2: renewal the way CredentialRenewalListener does it
The listener (`credential-renewal-listener` design) creates the renewal enrolment when a credential transitions to `expired`: `source: credential-renewal`, `mandatory: true`, the regulation slug copied, no due date. The set writes exactly that. Every credential issued before the year whose expiry falls inside it is `expired` and names its renewal; the renewal's course is the one the old course names in `renewalCourseSlug` (BHV basis and herhaling renew into herhaling, NEN 3140 basis into herinstructie, VCA Basis into itself). Credentials issued before the year that are still valid at its end stay `issued`. Nine renewals are still open on 10 July (four employees who never finished the code of conduct course, a long-term ill BHV'er, and three NEN 3140 and one forklift certificate that expire after the last session of the year), because a real year ends that way and the compliance view should have something to show.

### Decision 3: where the certificates come from
| Course | Who | Before the year | During the year |
|---|---|---|---|
| Gedragscode en integriteit (annual e-learning) | everyone | 184 certificates from the 2024 campaign and last year's onboarding, all expiring in the year | 184 renewals, 16 new starters; 4 still unfinished |
| Informatiebeveiliging en phishing (annual e-learning) | everyone | none: introduced this year | bulk enrolment on 6 October, due 31 January; 5 unfinished |
| AVG in de praktijk (every two years) | the office departments | none | campaign from 2 March, due 30 April; 3 unfinished |
| BHV basis and herhaling (annual) | 29 BHV'ers, 6 new | 29 certificates from September 2024 | three herhaling days and a catch-up day; a two-day basis course in February |
| VCA Basis and VOL (ten years) | everyone in Operatie | 12 VOL and 111 Basis diplomas, five expiring in the year | two exam days; one new starter fails and passes in group 2; starters after March are booked on a group planned for September 2026 |
| NEN 3140 (three years) | Installatie en service, Werkplaats | a certificate per technician, a third expiring in the year | four herinstructie mornings, two basis days |
| Heftruck (five years) | Magazijn en logistiek | a certificate per warehouse employee | two herhaling mornings, two basis days |
| F-gassen (five years) | 14 holders, 6 new | 14 certificates | one two-day course with exam |
| Warmtepompen, projectmatig werken | 12 and 4 participants | none | certificates without expiry from the external academies |

### Decision 4: toolbox meetings record exceptions only
Like the primary school set's Decision 3: the three operational departments hold a toolbox meeting on the first Tuesday of every month from September to June, in their own slot before the first training of the day starts. A mark is stored only for absence or lateness (127 marks over 30 meetings); the cohort roster is the attendance list. Classroom trainings record every participant, because the attendance list is the evidence a certificate rests on.

### Decision 5: the skills gap and the plans come from the same rule
`SkillsGapDashboard.vue` treats a competency as required when a programme of one of the learner's enrolled courses requires it, or when its `requiredForRoles` meets the learner's roles, and as a gap when no attainment with a proficiency level exists. The generator applies that rule to write each development plan: every gap becomes an open goal starting with the competency's title. The "Vakopleiding servicemonteur" programme requires VCA, NEN 3140, heat pump installation and fault finding (F-gas is a specialism, not a requirement), and every service technician is enrolled in its leerlijn, so the dashboard shows 34 heat pump gaps: the strategic gap the warmtepomp course addresses. DIG-01 is required for everyone and attained by finishing the security course; LEI-01 and LEI-02 are required for managers.

### Decision 6: points follow completions, levels follow points
One active rule ("Training afgerond", 50 points per completed enrolment) and one draft streak rule. Each employee's `LearnerEngagement` holds the sum of their awards, the highest level that sum reaches (Starter 0, Op weg 100, Gevorderd 150, Kampioen 250), the longest run of consecutive award days, and a current streak of 0, since the year ended in July. Engagement scores exist for the security e-learning only, computed with `EngagementScoreEvaluator`'s formula from the lesson durations and the gap between the last two completions.

### Decision 7: paid courses are paid by the employer
Three fee items (warmtepompen, an Excel licence, projectmatig werken), never voluntary, standard VAT rate. Each participant has one order paid by the employer (`payerKind: employer`, payer name and a `.example` address), one line, one successful Mollie payment (one order first fails and is paid on the second attempt) and an active entitlement. One Excel order placed on 29 June is still open: pending entitlement, pending enrolment. One project course order is cancelled with the withdrawn enrolment it belonged to.

### Decision 8: the promoted seed, made fictional
The register's only corporate seed row ("NIS2 board awareness session", provider "SecureBoard B.V.", learner "learner-001", submitted and verified by "admin") becomes a batch of nine verified records (`batchId: nis2-bestuur-2026-03`) for the director, the seven managers and the compliance officer, provider "Voorbeeld SecureBoard B.V.", recorded and verified by the compliance officer, valid twelve months like the seed row.

### Decision 9: no Regulation rows
`Regulation.slug` is a declared property with pattern `^[A-Z0-9_-]+$`; the contract fixes the top-level `slug` of every example object as `corporate-regulation-NNN`. No value satisfies both, so the set carries none and the regulation slugs live on courses, enrolments and credentials (proposal Risk 2).

## Declarative-vs-imperative decision (ADR-031)
No behaviour is added. The set is data; derived values a listener or evaluator would compute (renewal enrolments, progress percentages, engagement scores, point totals and levels, attainments) are written consistently by the generator, because seeding runs as a system operation that withholds lifecycle events.

## Security Considerations
No code path changes. The data is fictional (proposal Risk 4). Credentials carry an explicit unsigned marker instead of a signature, so `CredentialVerifyController` can never report one as valid. Loading and removal stay admin-only and shell-only, as `segment-wizard-choice` defines.

## Seed Data
The set is the seed data. Counts per schema (bucket order is load order):

| schema | objects | notes |
|---|---|---|
| school | 1 | Voorbeeldbedrijf Esdoorn Techniek B.V., 00X5 |
| vestiging | 2 | Hoofdkantoor, Werkplaats en magazijn Havenkwartier |
| room | 5 | training room, meeting room, practice hall, canteen, online |
| competency-framework / competency | 1 / 18 | four groups, fourteen competencies, three levels |
| course / lesson / programme | 19 / 16 / 2 | compliance, certification, development and paid courses; e-learning lessons; two programmes |
| cohort | 33 | eight departments, 25 training groups (one planned for September 2026) |
| staff | 6 | director, HR adviser, compliance officer, training coordinator, two trainers |
| learner-profile | 200 | 184 employed all year, 16 starters |
| learning-plan-template | 1 | Persoonlijk ontwikkelplan (POP) |
| enrolment | 723 | 243 renewals, 184 bulk, the rest by HR, managers and employees |
| session | 62 | 32 classroom sessions, 30 toolbox meetings |
| attendance-record | 310 | every classroom participant, toolbox exceptions |
| lesson-completion | 1324 | e-learning progress with quiz scores |
| credential | 1026 | 243 expired (each with its renewal), 783 issued |
| external-training-record | 31 | NIS2 batch, trade fair, congress, webinar, cloud course, driving course, a rejected and a waiting record |
| competency-attainment | 573 | what the skills gap reads |
| learning-plan / learning-plan-evaluation | 200 / 125 | one plan per employee, mid-year reviews in February and March |
| engagement-risk-threshold / engagement-score / engagement-risk-flag | 1 / 198 / 3 | security e-learning |
| point-rule / engagement-level / point-award / learner-engagement / leaderboard | 2 / 4 / 625 / 200 / 3 | company-wide and two department leaderboards |
| fee-item / order / order-line / payment-transaction / entitlement | 3 / 25 / 25 / 24 / 24 | paid courses |

Related items (files, notes, tasks): none; the set carries no `_relatedItems`.

## Risks / Trade-offs
- [Import time] → proposal Risk 1.
- [No regulations] → proposal Risk 2 and Decision 9.
- [Evidence files] → proposal Risk 3.
- [Starters and department rosters] → a starter is on the department roster all year, because a cohort roster has no start date; the generator gives a starter no toolbox mark, training or enrolment before their first working day.

## Migration Plan
No data migration: the retired seed row was never imported. Deploy is the PR. Rollback: revert; remove a loaded set with `occ learniq:example-set:remove corporate --apply` first.

## Open Questions
None for this set. The contract amendment for schemas with their own `slug` property belongs to `segment-wizard-choice`.
