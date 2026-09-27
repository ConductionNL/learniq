---
kind: config
depends_on:
  - segment-wizard-choice
  - segment-example-datasets-po
---

# Proposal: segment-example-datasets-corporate

## Summary
The company example set: `lib/Settings/profiles/corporate.json`, one fictional company (Voorbeeldbedrijf Esdoorn Techniek B.V., an installation and service firm in the fictional town of Esdoornhaven) through the complete 2025-2026 training year. A head office and a workshop and warehouse site, eight departments as cohorts, 200 employees with their managers, an HR adviser, a compliance officer, a training coordinator and two in-house trainers on Staff. Compliance e-learning and classroom certification issue 1026 credentials; every certificate that expires during the year opens a renewal enrolment, and 234 of the 243 renewals issue the next certificate. Around that: toolbox meetings with their attendance, external training records, a competency framework with the skills gaps it shows, a development plan per employee, engagement scores, points and leaderboards, and orders with entitlements for three paid courses. 5815 objects that agree with each other. The one curated corporate seed row that sat dark in the register (the NIS2 board awareness session) moves into it.

## Motivation
Decision D21 (Ruben, 2026-09-27): six example sets, one lane per set; this lane builds the company one. Recon A section 1 found learniq's only corporate data in one `ExternalTrainingRecord` `x-openregister-seed` row ("NIS2 board awareness session", provider "SecureBoard B.V.") that no import path reads, next to a generated demo register that mixes primary school, MBO, higher education and company schemas with placeholder values. The primary school lane (`segment-example-datasets-po`, Out of Scope) left that row for this lane. Recon A open question 4 recommends promoting curated seed into its set rather than leaving it dark, the move decidesk's `seed-profiles` made.

Evidence: the round 1 corpus row 14.6 (`_round1/compare/findings.md:51`, "Feature flags per segment (hide corporate menus for a school)") cites Canvas and Moodle feature options per account; recon A section 2 checked Moodle's demo strategy (one canned site, "Mount Orange School") and found no competitor offering example data per organisation kind. The company set is where learniq's corporate schemas (Credential renewal, ExternalTrainingRecord, CompetencyFramework, LearningPlan, EngagementScore, Leaderboard, Order and Entitlement) finally show a company instead of "Voorbeeld Reporteruserid 1".

## Affected Projects
- [x] Project: `learniq`: new `lib/Settings/profiles/corporate.json` and its generator `scripts/example-sets/corporate.py`; the `ExternalTrainingRecord` seed block in `lib/Settings/learniq_register.json` emptied (schema 0.2.0 to 0.2.1, `info.version` 0.25.1 to 0.25.2); a new content test; one catalogue key pair.

## Scope

### In Scope
- The set, written against `openspec/changes/segment-wizard-choice/contract.md` and passing `ExampleSetDescriptorContractTest`.
- A deterministic generator (`python3 scripts/example-sets/corporate.py`, `--check` for CI and review).
- The credential renewal story the way `CredentialRenewalListener` runs it: an expiring certificate is `expired`, names a renewal enrolment (`source: credential-renewal`, no due date, mandatory) in the course its own course renews into, and a completed renewal issues exactly one new certificate after the old one lapsed.
- Promoting the NIS2 seed row: the board awareness session becomes a batch of verified external records for the director, the seven managers and the compliance officer, with a fictional provider.
- `CorporateExampleSetTest`: the set's promises and its internal consistency.

### Out of Scope
- The other four sets (sibling lanes).
- `Regulation` rows. The schema declares its own `slug` property (`^[A-Z0-9_-]+$`), and the contract fixes the top-level `slug` as `corporate-regulation-NNN`, so no Regulation object can pass the contract test. Courses, enrolments and credentials carry `regulationSlug` values (`GEDRAGSCODE`, `INFORMATIEBEVEILIGING`, `AVG`, `NIS2`, `BHV`, `VCA`, `NEN3140`, `HEFTRUCK`, `FGASSEN`) that light up the compliance roll-up once a regulation with that slug exists. A contract amendment is the follow-up (see Risk 2).
- Signed attestations and signatures: both carry an HMAC with the tenant key, which a seed cannot produce honestly.
- A live import and purge on an instance (lanes keep off the shared instance).

## Approach
Generate, don't hand-write. One Python script builds the calendar (working days minus public holidays and the Christmas closure), the departments and their people, the certificates each person held before the year, the expiries that fall inside it and the renewals they open, the sessions each renewal and each new starter lands in, and then derives everything that depends on those facts: attendance, lesson completions, new certificates, attainments, the skills gaps and the plan goals that address them, point awards and totals, and the orders for paid courses. Output is strict JSON with one object per line.

## New Dependencies
None. The generator uses the Python standard library only.

## Impact
- `lib/Settings/profiles/corporate.json` (new, about 4.2 MB, one object per line).
- `lib/Settings/learniq_register.json`: one seed block emptied, `ExternalTrainingRecord` 0.2.0 to 0.2.1, `info.version` 0.25.1 to 0.25.2. No property changes.
- `l10n/en.json`, `l10n/nl.json` (+ built `.js`): the card description.
- Tests: one new test class.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: loading takes minutes
**Severity:** Medium. **Mitigation:** 5815 objects in one wizard request, 28% more than the primary school set. Seeding runs as a system operation, the import is idempotent by uuid (a timed-out request finishes on a second run), and the card shows the object count. Measured on a live instance: not in this lane.

### Risk 2: the compliance roll-up stays empty until regulations exist
**Severity:** Medium. **Mitigation:** the contract cannot carry `Regulation` rows today (Out of Scope). Every course, enrolment and credential already carries the regulation slug, so creating the nine regulations by hand, or a contract amendment that lets a schema with its own `slug` property use `@self.slug` for the envelope, turns the roll-up on without touching this set.

### Risk 3: verified external records carry no evidence file
**Severity:** Low. **Mitigation:** `ExternalTrainingVerificationGuard` judges evidence files on `verify`; seeding withholds guards and cannot attach files (`_relatedItems` needs a user context the wizard import may lack). The records say what the evidence was in `evidenceNote`.

### Risk 4: a name resembles a real person or company
**Severity:** Low. **Mitigation:** first names are common Dutch names, surnames are invented compounds of a bird name and a place suffix, the company, town, streets and every external provider carry "Voorbeeld" or are invented, postcodes start with 0, e-mail addresses and issuer DIDs use the reserved `.example` domain, the BRIN-shaped code 00X5 ends in a digit, and every credential's signature reads "voorbeeldgegevens-niet-ondertekend". No BSN anywhere.

## Rollback Strategy
Revert the PR: the set disappears from the wizard and the seed row returns. An instance that loaded the set removes it first with `occ learniq:example-set:remove corporate --apply`.
