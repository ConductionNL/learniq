# Tasks: remaining-lists-read-pupil-names

- [x] **1.1** the attendance flags, exam accommodations and BSA flags index pages declare a teacher's columns, the pupil first by name
  - node test "the three reported lists lead with the pupil and read as a teacher reads them"
- [x] **1.2** the other 38 index pages on a schema with a pupil user id declare the columns they showed, the pupil by name, without the tenant id and the repeated pupil fields
  - node tests "an index page on a schema with a pupil user id declares its columns", "no index page shows a pupil as a user id", "a list that only gained the pupil name kept its other columns"
- [x] **1.3** every declared column is a visible property; every added heading has a Dutch entry
  - node tests "every declared column is a property the list may show", "every heading these lists add has a Dutch entry"
- [x] **1.4** `parentExcuseRequests` declares `defaultSort` on `dateFrom`, newest first
  - PHPUnit `PortalContributionProviderTest`
- [x] **1.5** live check on the primary-school instance: the attendance flags as a coordinator, the absences as a guardian
