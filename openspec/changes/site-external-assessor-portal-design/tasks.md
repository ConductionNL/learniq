# Tasks: site-external-assessor-portal-design

Specs only so far. Build starts after Ruben approves the specs. T3 waits on portaliq's joined-schema filter on `via`.

- [ ] **T1**: register: `PortfolioShare.portfolioTitle`, `PortfolioShare.learnerName`, a server stamp on create, a back-fill for existing shares
  - PHPUnit for the stamp; `npm run check:register`
- [ ] **T2**: `eaSharedPortfolios` projects both copies
  - PHPUnit `PortalContributionProviderTest`
- [ ] **T3**: `eaSharedPortfolioEntries` through the share's `entryIds` (and `portfolioId` for a whole-portfolio share), only active and unexpired shares
  - PHPUnit `PortalContributionProviderTest`; portaliq reader test for the joined filter
- [ ] **T4**: `AssessorPortalPages`: overview with the access notice, "Gedeeld met mij", "Berichten"
  - PHPUnit for the new class
- [ ] **T5**: the manifest through `PortalLabelTranslator`; Dutch "u" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T6**: mbo example set: one external assessor with a portal account and two portfolio shares
  - `python3 scripts/example-sets/mbo.py --check`
- [ ] **T7**: e2e: the assessor reads his access date and opens a shared entry
  - `tests/e2e/mbo-assessor-flows.spec.ts`

## Follow-ups (not in this change)

- `mbo-practical-exam-assessment`: the exam day, the assessment form, offline tolerance, joint sign-off and exam documents (see design).
