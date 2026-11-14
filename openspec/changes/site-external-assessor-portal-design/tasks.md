# Tasks: site-external-assessor-portal-design

Built in waves. T3 and T4b waited on portaliq's `via.when`, `via.validUntilField` and `richText` `template`; all three are now on portaliq development.

**Trust:** his reads stay `minTrust: low`, and the audience writes nothing. A freelance assessor signs in by invitation or with eHerkenning; only a broker mints `substantial` (portaliq `SessionController.php:666`), so a `substantial` floor would lock out an invited assessor. The rows are already narrowed to the shares granted to him, and only while they are active.

- [x] **T1**: register: `PortfolioShare.portfolioTitle`, `PortfolioShare.learnerName`, a server stamp on create, a back-fill for existing shares
  - PHPUnit for the stamp; `npm run check:register`
- [x] **T2**: `eaSharedPortfolios` projects both copies and names its columns Kandidaat, Portfolio and Toegang tot en met
  - PHPUnit `GuardianSitePagesTest`, `PortalLabelTranslatorTest`
- [x] **T3**: `eaSharedPortfolioEntries` through the share's `entryIds` (and `portfolioId` for a whole-portfolio share), with `via.when` and `via.validUntilField: expiresAt`
  - A share's selection through `targetField: entryIds`, `match: id`. A whole-portfolio share (no selection: `entryIds` null or empty, as `PortfolioShareGrantHandler` reads it) through a second collection, `eaWholePortfolioEntries`: the server stamps `wholePortfolioId` on the share (`ReadableCopies`, back-filled by `BackfillReadableCopies`) only when it selects nothing, and the join reads `targetField: wholePortfolioId`, `match: scopeField` on the entries' `portfolioId`, under the same `when` and `validUntilField`. A share with a selection stamps none, so the entries it left out stay closed. The share page lists both.
  - PHPUnit `PortalContributionProviderTest`; portaliq reader test for the joined filter
- [x] **T4a**: `AssessorSitePages`: the overview (`home: true`, `group`) listing his shares with the longest access first (`sort`), and the "Met u gedeeld" page
  - PHPUnit `GuardianSitePagesTest`; run through portaliq's own resolvers: nothing dropped
- [x] **T4b** (waits for portaliq `richText` `template` and `whenEmpty`): the access sentence "U heeft toegang tot en met …". Until then each row names the date his access runs to.
  - Each share's own page `eaShare` (`record` on `eaSharedPortfolios`, out of the menu) carries the sentence with `whenEmpty`, and lists that portfolio's shared entries. `PortalLabelTranslator` translates `whenEmpty` words. PHPUnit `AssessorSitePagesTest`.
- [x] **T5**: the manifest through `PortalLabelTranslator`; Dutch "u" entries
  - PHPUnit `PortalLabelTranslatorTest`; `npm run check:l10n`
- [x] **T6**: the mbo set seeds an active external assessor, two portfolios and two active shares, and he can be given a portal account (`occ learniq:portal:invite-assessor`)
  - PHPUnit `VocationalCollegeExampleSetTest`, `BpvPortalInvitationTest`; `python3 scripts/example-sets/mbo.py --check`
- [ ] **T7**: e2e: the assessor reads his access date and opens a shared entry. The data and the account now exist; the suite waits on an instance with the mbo set loaded.
  - `tests/e2e/mbo-assessor-flows.spec.ts`

## Follow-ups (not in this change)

- `mbo-practical-exam-assessment`: the exam day, the assessment form, offline tolerance, joint sign-off and exam documents (see design).
