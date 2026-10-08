## ADDED Requirements

### Requirement: A load moves every date to the week it runs in

Loading an example set MUST move every date of the set by the whole weeks between Monday 5 October 2026 and the Monday of the week the load runs in (Europe/Amsterdam). This covers ISO dates, ISO date-times, ISO weeks, Dutch day-month phrases and day-month-year numbers, in the seed objects and in the portal's pages and news. A date-time MUST keep its weekday and its wall-clock time. A value without a date MUST NOT change.

#### Scenario: A load three weeks after the boards' week
- **GIVEN** the vo set and a load on Thursday 22 October 2026
- **WHEN** the operator loads it
- **THEN** Noor's lessons of Monday 5 October are on Monday 19 October at the same times, and a lesson titled "maandag 5 oktober 2026" reads "maandag 19 oktober 2026"
- @e2e exclude covered by PHPUnit `DemoDatesTest::testALoadInAnotherWeekKeepsTheWeekdayPattern` over the four shipped sets; the rendered "Je rooster vandaag" by `tests/e2e/portal-design/vaartveld.spec.ts`

### Requirement: A reload in another week moves what exists, and a reload in the same week writes nothing

The load MUST remember the offset each set carries. A load in another week MUST first move the set's existing objects, and its portal's declared pages and news, by the difference, then import what is missing. A load in the same week MUST move nothing. A set loaded before offsets were kept MUST count as offset 0.

#### Scenario: A reload two weeks later
- **GIVEN** the po set loaded with offset 7
- **WHEN** the operator loads it again with offset 21
- **THEN** the existing objects move by 14 days before the import, and the offset 21 is remembered
- @e2e exclude covered by PHPUnit `SeedProfileServiceTest::testAReloadInALaterWeekMovesWhatExistsFirst` and `ExampleSetDatesTest`

#### Scenario: A reload in the same week
- **GIVEN** the po set loaded this week
- **WHEN** the operator loads it again
- **THEN** no existing object is written
- @e2e exclude covered by PHPUnit `SeedProfileServiceTest::testASameWeekReloadMovesNothingThatExists`
