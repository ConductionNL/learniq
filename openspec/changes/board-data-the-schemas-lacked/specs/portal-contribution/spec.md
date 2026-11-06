## ADDED Requirements

### Requirement: A course row says how many places are left

When a course date has a capacity, its row in the public index MUST say how many places are left: the capacity minus the places of live company bookings and live enrolments without a booking. The note is "Vol" or "Nog N plek(ken)" as a warning up to three places, and "N plekken vrij" as positive above that.

#### Scenario: The academy's next course days
- **GIVEN** F-gassen with 4 places and a booking for 3, Waterzijdig inregelen with 6 places and none taken
- **WHEN** a visitor opens the academy's home
- **THEN** F-gassen reads "Nog 1 plek" and Waterzijdig inregelen "6 plekken vrij"
- @e2e exclude covered by PHPUnit `PortalPublicIndexTest::testTheAcademyShowsItsCoursesWithTheirNextDates`

### Requirement: The placement page shows the agreements and the work processes

The student's placement page MUST show the placement's agreements under the heading "Agreements", and a table of her own work processes narrowed to the open placement: code, name, hours and her estimate in words.

#### Scenario: Milan's placement
- **GIVEN** Milan's placement at Bakker Techniek BV
- **WHEN** he opens it
- **THEN** "Afspraken" names "Maandag tot en met woensdag, 08.00 tot 16.30 uur" and crebo 25743, and "Werkprocessen" lists B1-K1-W1 "Bereidt het werk voor", 14 uur, "Goed"
- @e2e exclude covered by PHPUnit `PortalContributionProviderTest::testThePlacementShowsItsAgreementsAndWorkProcesses`
