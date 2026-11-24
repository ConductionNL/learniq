# Tasks: example-set-declares-its-events

- [x] **T1**: `ExamplePortalContent::events()` writes the declared events once, matched by title; `news()` writes a named event's id as `eventRef`; `dated()` and `moveDates()` move the events; the provisioner writes events before news and reports them
  - PHPUnit `ExamplePortalContentEventsTest`
- [x] **T2**: The vo declaration declares "Informatieavond profielkeuze, klas 3" and its news item names it
  - PHPUnit `ExamplePortalDeclarationsTest::testTheVoSetDeclaresTheEveningItsNewsItemNames`
- [ ] **T3**: Live: load the vo set and answer the evening as a pupil with two seats on the vaartveld article
