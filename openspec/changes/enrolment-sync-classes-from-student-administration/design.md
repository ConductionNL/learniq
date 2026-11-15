# Design: keep classes and enrolments in step with the student administration system

## Context

At development `24b9ae95`:

- `lib/Service/ExchangeImportLanding.php` lands `lvs-results`, `oso` and `migration-import` records from `ExchangeRecordsReceivedEvent`. It runs in integriq's background job, system level, and returns refusal codes with field names, never values.
- `Cohort`: `name`, `programmeId`, `courseId`, `teacherIds`, `learnerIds`, `ncGroupId`, `period`, `academicYear`, `kind`, `programmeYear`, `locationId`, `teacherAssignments`, `notes`, `reportCardTemplateId`; lifecycle `planned`, `active`, `completed`, `archived`; `CohortMembershipGuard`.
- `Enrolment.source`, `cohortId`, lifecycle with `withdraw` (reason required).
- data-exchange spec: "Records integriq hands back for an import land in learniq" (REQ at line 594).
- integriq archived `integriq-adapter-rostering-imports`: the roster sources; Untis through OneRoster REST.

## Screen

Board `LqGroepen` ("learniq: groepen") on canvas `5NkFW28vZUUij43xzxHg5a` draws the groups list with the header actions "Importeren", "Downloaden" and "Nieuwe groep", and the line "6 van 42 groepen". The sync adds one line under the title, in the same style as that count: "Bijgewerkt uit Magister om 07.00, 3 wijzigingen". "Importeren" stays for a one-off file. Board `LqGroep` ("learniq: groep") shows the group's data block (Naam, Soort groep, Opleiding, Leerjaar, Schooljaar) and "Inschrijvingen 27". For a synced group these fields show read only with "uit Magister" beside them; "Notities", "Werkgroepen" and "Rapportsjabloon" stay editable, as the board draws them.

## Decisions

### D1: Same landing, new kind

The landing exists, runs as the system and refuses with codes. A fourth kind costs one branch and keeps one place where integriq's records enter learniq.

### D2: Upsert on the external id

A class is matched on `externalId` and `externalSource`, never on its name: names change at the year switch, ids do not.

### D3: Leaving withdraws, never deletes

A pupil's grades, attendance and submissions hang off their enrolments. A leaver's enrolments are withdrawn with a reason, so the history stays readable and the reports stay correct.

### D4: The source owns membership, learniq owns the rest

Members, teachers, name and year come from the source and are read only for a synced cohort. Everything learniq adds (work groups, notes, the report template, Talk room) stays learniq's.

## Risks

- A pupil the source sends that learniq does not know: refused with `ROSTER-UNKNOWN-PUPIL` and the field name, so integriq keeps it as a dead letter; the `migration-import` kind creates the profile first.
