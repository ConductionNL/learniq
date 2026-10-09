## ADDED Requirements

### Requirement: Noor's story carries what her pupil boards read

The vo example set MUST give the economie lesson of Monday 5 October the short change reason "Niet in lokaal 1.08", the words of the MobielDetail board. It MUST hold one `conference-invitation` per pupil invited to the H4b mentor-talk round, as `ConferenceInvitations` writes them on a live save: `booked` for a pupil with a time in the round, `open` for the others while the round is open for booking. Noor Bakker's invitation MUST be open.

#### Scenario: Noor is asked to pick a time for the mentor talk
- **GIVEN** the vo set loaded on a fresh instance
- **WHEN** the H4b invitations are read
- **THEN** Noor's invitation is open, the two classmates with a time are booked, and every row names the round "Mentorgesprekken H4b, oktober 2026" with its last moment to book
- @e2e exclude seed data, covered by PHPUnit `SecondarySchoolExampleSetTest::testEveryH4bPupilIsInvitedToTheMentorTalks`; the Berichten board check waits on the pupil-facing invitation (FIX-L)

#### Scenario: The changed lesson gives a short reason
- **GIVEN** the vo set
- **WHEN** Noor's economie lesson of Monday 5 October is read
- **THEN** its reason reads "Niet in lokaal 1.08" and its room is lokaal 0.21
- @e2e exclude seed data, covered by PHPUnit `SecondarySchoolExampleSetTest::testNoorBakkersMondayInH4bComesOutOfTheData`
