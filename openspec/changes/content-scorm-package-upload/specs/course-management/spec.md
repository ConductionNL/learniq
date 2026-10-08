## ADDED Requirements

### Requirement: The package import accepts a SCORM zip

The course package import MUST accept a zip whose root holds an `imsmanifest.xml` declaring SCORM 1.2 or SCORM 2004. It MUST read the manifest's organizations and resources, create one lesson per SCO in manifest order, and unpack the package into the course's folder in Nextcloud Files. Each lesson MUST get `contentType` `scorm12` or `scorm2004` by the manifest's schema version and `contentRef` set to the SCO's launch file.

#### Scenario: A single-SCO SCORM 1.2 package

- **GIVEN** a course and a SCORM 1.2 zip with one SCO whose launch file is `index.html`
- **WHEN** a teacher uploads it on the import screen and chooses "Pakket importeren"
- **THEN** the course has one new lesson with `contentType: scorm12` and a `contentRef` that points at the unpacked `index.html`
- **AND** the import report lists the SCO as imported

#### Scenario: A package with three SCOs

- **GIVEN** a SCORM 1.2 zip whose manifest lists three SCOs
- **WHEN** it is imported
- **THEN** the course gets three lessons in the manifest's order

#### Scenario: A zip that is not a SCORM package

- **GIVEN** a zip without `imsmanifest.xml` and without a Common Cartridge or Moodle signature
- **WHEN** it is uploaded
- **THEN** the import is refused with a message that names the accepted formats

### Requirement: An imported SCORM 1.2 lesson reports its own completion

A lesson imported from a SCORM 1.2 package MUST play through the SCORM 1.2 runtime, and its completion MUST reach the learner's enrolment through the existing statement ingest.

#### Scenario: The learner finishes the SCO

- **GIVEN** a learner enrolled in a course with an imported SCORM 1.2 lesson
- **WHEN** the package sets `cmi.core.lesson_status` to `completed`
- **THEN** the lesson counts as completed for that enrolment

### Requirement: The import report is honest about what does not play

The import report MUST list a SCORM 2004 SCO as reduced with the reason "SCORM 2004 is not played yet", and MUST list a zip entry outside the package root, with `..` or an absolute path, as dropped. Such an entry MUST NOT be written.

#### Scenario: A SCORM 2004 package

- **GIVEN** a SCORM 2004 zip with one SCO
- **WHEN** it is imported
- **THEN** the lesson exists with `contentType: scorm2004`
- **AND** the report lists it as reduced with the reason

#### Scenario: A zip entry tries to escape the folder

- **GIVEN** a SCORM zip with an entry `../../config.php`
- **WHEN** it is imported
- **THEN** that entry is not written anywhere
- **AND** the report lists it as dropped
