# Tasks: guardian-reports-and-documents

- [x] **T1** `SchoolDocument` 0.1.0 in the register (0.47.0), with Dutch schema strings.
- [x] **T2** `GuardianDocuments` (`childReports`, `schoolPapers`) over `PaperQuery` and `PortalFileEntries`; provider methods `childDocuments` and `schoolDocuments` through `PortalRecordProviders`. Test: `GuardianDocumentsTest`.
- [x] **T3** `ParentDocumentsPage`: `parentSchools` and the page with two documents blocks for each record; `parentChildren` declares `childDocuments`; the label translator reads `subtitle`, `emptyLabel` and `markdown`. Test: `ParentDocumentsPageTest`, golden manifests.
- [x] **T4** po.json: "Rapporten en documenten" under "Van school". Test: `ParentDocumentsPageTest::testTheMenuPutsItUnderVanSchool`, `GuardianWilgenboomBoardsTest`.
- [x] **T5** English and Dutch strings. Test: `ParentDocumentsPageTest::testTheWordsAreDutch`.
- [ ] **T6** Example data: a schoolgids and a jaarkalender with files for De Wilgenboom, and the PDFs of Vera's and Sami's reports. Not built: the example sets seed objects without files.
