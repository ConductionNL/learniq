## ADDED Requirements

### Requirement: A leave request is asked in advance and decided by the head of the school

learniq MUST keep a `leave-request` per pupil and range of days, with who asked, the kind, the reason, the number of school days in the range and the date by which the school must decide. A request of at most ten school days MUST be decided (allowed or refused) by a member of staff with the `administration-manager` role for the pupil's school; a request of more than ten school days MUST be marked `forwarded` and MUST NOT be allowed or refused by the school itself. A refusal MUST carry a decision note. Every decision MUST record who decided and when.

#### Scenario: The directeur allows a wedding
- **GIVEN** Hamza's parents asked leave for Thursday 29 and Friday 30 October for a wedding, on Wednesday 30 September
- **WHEN** the directeur allows it on Monday 5 October
- **THEN** the request reads allowed, with the directeur's name and the moment, and Hamza's attendance on those two days reads as absent with permission
- @e2e exclude spec-only proposal; the build asserts the decision in the handler's unit tests

#### Scenario: More than ten school days
- **GIVEN** a request for twelve school days
- **WHEN** the directeur opens it
- **THEN** it offers no allow or refuse, only "Doorsturen naar de leerplichtambtenaar"
- @e2e exclude as above

#### Scenario: A refusal without a reason
- **GIVEN** an open request
- **WHEN** the teamleider refuses it with an empty note
- **THEN** the refusal is not saved, and the message asks for the reason
- @e2e exclude as above

### Requirement: The decider sees what waits, oldest first, with the deadline in words

The staff list of open leave requests MUST show the pupil, the group, the days, the kind and "Beslis uiterlijk" with the deadline date, oldest request first, and MUST be readable only by staff who may decide for that pupil's school.

#### Scenario: Two requests wait
- **GIVEN** the requests of the El Idrissi family (asked Wednesday) and the Jansen family (asked Friday)
- **WHEN** the directeur of De Wilgenboom opens "Verlof te beslissen"
- **THEN** the El Idrissi request is first, with "Beslis uiterlijk woensdag 7 oktober"
- @e2e exclude staff screen; spec-only proposal
