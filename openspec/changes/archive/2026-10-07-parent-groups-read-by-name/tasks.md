# Tasks: parent-groups-read-by-name

- [x] **1.1** `parentGroupMemberships` shows `cohortName` and reads it; `cohortId` stays for the news audience
  - `tests/Unit/Portal/PortalContributionProviderTest.php` ("testParentGroupColumnReadsTheGroupName"; also asserts `enrolment.cohortName` is plain text in the shipped register)
- [x] **1.2** the manifest dump of every other audience is unchanged (checked by dumping `getContribution()` for all four audiences before and after)
