# Tasks: past-course-days-drop-off

- [x] **T1**: `upcoming` follows the last course day when today is known (`EmployerBookingFacts`, `EmployerBookingProjection`)
  - PHPUnit `EmployerBookingFactsTest::testABookingStopsBeingComingAfterItsLastDay`, `PortalEmployerBookingsTest`
- [x] **T2**: `PastCourseDaysJob`, hourly, in info.xml
  - PHPUnit `PastCourseDaysJobTest`
- [ ] **T3**: live: proof run 4
