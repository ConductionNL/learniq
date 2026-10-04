# Tasks: site-external-assessor-portal-design

Built in waves. T3 waits on portaliq's `via.when` and `via.validUntilField`, which are not on development.

**Trust:** his reads stay `minTrust: low`, and the audience writes nothing. A freelance assessor signs in by invitation or with eHerkenning; only a broker mints `substantial` (portaliq `SessionController.php:666`), so a `substantial` floor would lock out an invited assessor. The rows are already narrowed to the shares granted to him, and only while they are active.

- [x] **T1**: register: `PortfolioShare.portfolioTitle`, `PortfolioShare.learnerName`, a server stamp on create, a back-fill for existing shares
  - PHPUnit for the stamp; `npm run check:register`
- [x] **T2**: `eaSharedPortfolios` projects both copies and names its columns Kandidaat, Portfolio and Toegang tot en met
  - PHPUnit `GuardianSitePagesTest`, `PortalLabelTranslatorTest`
- [ ] **T3**: `eaSharedPortfolioEntries` through the share's `entryIds` (and `portfolioId` for a whole-portfolio share), with `via.when` and `via.validUntilField: expiresAt`
  - PHPUnit `PortalContributionProviderTest`; portaliq reader test for the joined filter
- [x] **T4a**: `AssessorSitePages`: the overview (`home: true`, `group`) listing his shares with the longest access first (`sort`), and the "Met u gedeeld" page
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own resolvers: nothing dropped
- [ ] **T4b** (waits for portaliq `richText` `template` and `whenEmpty`): the access sentence "U heeft toegang tot en met …". Until then each row names the date his access runs to.
- [x] **T5**: the manifest through `PortalLabelTranslator`; Dutch "u" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [ ] **T6**: mbo example set: one external assessor with a portal account and two portfolio shares
  - `python3 scripts/example-sets/mbo.py --check`
- [ ] **T7**: e2e: the assessor reads his access date and opens a shared entry
  - `tests/e2e/mbo-assessor-flows.spec.ts`

## Follow-ups (not in this change)

- `mbo-practical-exam-assessment`: the exam day, the assessment form, offline tolerance, joint sign-off and exam documents (see design).
