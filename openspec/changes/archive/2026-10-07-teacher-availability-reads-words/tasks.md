# Tasks: teacher-availability-reads-words

- [x] **1.1** the availability carries `teacherName`; ReadableCopies derives it from the teacher's account and ReadableCopyStamp writes it on create and update, replacing a sent value; an unknown teacher stores none
  - `tests/Unit/Listener/ReadableCopyStampTest.php` ("testAnAvailabilityNamesItsTeacher", real ObjectCreatingEvent; "testStampedRowsPassTheRealSchemas" validates the stamped row against the shipped fragment)
- [x] **1.2** BackfillReadableCopies writes `teacherName` on stored availabilities and skips one that has it
  - `tests/Unit/Repair/BackfillReadableCopiesTest.php`
- [x] **1.3** the `timeBlocks` formatter reads blocks as day and times in the reader's language and time zone, never as JSON
  - node test `tests/unit-js/teacherAvailabilityReadsWords.test.mjs`
- [x] **1.4** the list names the round, the teacher and the times; the page leaves the user id and tenant out; every label has Dutch
- [x] **1.5** register 0.34.40, schema version bumped, app version moved
