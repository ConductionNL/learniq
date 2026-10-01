## ADDED Requirements

### Requirement: Loading an example set gives its school a themed portal

When portaliq is installed, loading an example set MUST leave its school with a portal in portaliq whose `theme` is the matching thematiq example set: po `example-basisschool`, vo `example-voortgezet`, mbo `example-college`, he `example-college`, training `example-opleider`, corporate `example-opleider`. The portal MUST be found by its slug (po `wilgenboom`). A missing portal MUST be created with `status: published`. An existing portal without a theme MUST get the example theme and keep every other field. A portal whose theme is set MUST NOT be changed. Without portaliq nothing MUST be read or written. A failure to write the portal MUST NOT fail the import of the set.

#### Scenario: A set whose school has no portal gets a new themed one
- **GIVEN** portaliq is installed and no portal has the slug `esdoornveen`
- **WHEN** the operator loads the vo set
- **THEN** a published portal `esdoornveen` titled "Ouderportaal Esdoornveen" exists with theme `example-voortgezet`
- @e2e exclude a write through OpenRegister with no screen of its own, covered by PHPUnit `ExamplePortalProvisionerTest::testAMissingPortalIsCreatedWithTheExampleTheme`

#### Scenario: An existing portal without a theme gets the example theme
- **GIVEN** the hand-made portal `wilgenboom` with no theme
- **WHEN** the operator loads the po set, or runs `occ learniq:example-set:portal po`
- **THEN** `wilgenboom` has theme `example-basisschool` and keeps its title, authentication and organisation
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAnUnthemedPortalGetsTheThemeAndKeepsItsFields`; the themed page itself is portaliq's to test

#### Scenario: A theme an administrator chose is kept
- **GIVEN** portal `wilgenboom` with theme `rijkshuisstijl`
- **WHEN** the operator loads the po set again
- **THEN** the theme stays `rijkshuisstijl` and nothing is written
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testAChosenThemeIsKeptAndAReloadWritesNothing`

#### Scenario: Without portaliq loading a set writes no portal
- **GIVEN** portaliq is not installed
- **WHEN** the operator loads the po set
- **THEN** the set is imported, one log line says the set gets no portal, and OpenRegister is not asked for a portal
- @e2e exclude covered by PHPUnit `ExamplePortalProvisionerTest::testWithoutPortaliqNothingIsReadOrWritten`
