# Tasks: parent-figures-singular-and-plural

- [x] **1.1** the three attendance cards declare their unit as `{one, other}`, and the late-minutes detail its label
  - PHPUnit `ParentRecordPageTest::testTheFigureCardsCountInSingularAndPlural`
- [x] **1.2** PortalLabelTranslator translates both forms of a `unit` or `label` map and nothing else
  - PHPUnit `PortalLabelTranslatorTest::testTheFigureCardsArriveInDutchSingularAndPlural`, `::testOnlyVisibleStringsAreTranslated`, `::testEveryParentLabelHasADutchEntry` (both now read `unit/one` and `unit/other`)
- [x] **1.3** "day", "time" and "minute in total" in `en` and `nl`, marked in `ai-translated.json`, `.js` regenerated (`npm run l10n:build`)
