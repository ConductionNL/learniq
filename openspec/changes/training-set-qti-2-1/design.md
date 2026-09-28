# Design: training-set-qti-2-1

The item XML is copied in form from `scripts/example-sets/he.py` `qti()` (choice branch), which mirrors `src/views/ItemAuthorView.vue` `buildQtiBody`. Readers checked: `QtiChoiceOrderResolver`, `AssessmentDrawResolver`, `ItemAnalysisService`, `src/utils/qtiItemXml.js`, all of which look for `simpleChoice`.

The descriptor version goes from 1.1.0 to 1.2.0, so a reader of the descriptor can see that it changed. The test goes red against the old file (control run: 1 failure), and green against the new one.
