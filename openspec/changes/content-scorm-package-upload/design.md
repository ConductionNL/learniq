# Design: upload a SCORM package and have it report its own completion

## Context

At development `24b9ae95`:

- `lib/Service/CoursePackageImportService.php::import(packagePath, sourceFilename, importedBy, tenantId)` handles Common Cartridge (`CommonCartridgeParser`) and Moodle backups (`MoodleBackupParser`, `MbzExtractor`), and writes a `CoursePackageImportReport`.
- `Lesson.contentType` enum includes `scorm12` and `scorm2004`; `Lesson.contentRef` is "nc:files path or cmi5 launch URL".
- `src/utils/scorm12Runtime.js` implements the SCORM 1.2 API and posts statements to `POST /api/lrs/statements` (`openspec/specs/course-management/spec.md`, requirement "Run cmi5 + xAPI natively with SCORM shim").
- ADR-002 (`openspec/architecture/ADR-002-content-runtime-cmi5-xapi.md`): cmi5 and xAPI primary, SCORM through a compatibility shim.

## Screen

Board `LqLesmap` ("learniq: importeren en lesmap") on canvas `5NkFW28vZUUij43xzxHg5a`. Its first section, "Een pakket uploaden", takes one file ("Cursuspakketbestand", "Bestand kiezen", "Pakket importeren") and shows the counts "geïmporteerd, verminderd, vervallen" with a table of sources (Titel, Brontype, Resultaat, Doel, Reden). This change adds SCORM to that same section and changes nothing else on the board:

- The explanation reads: "Upload een IMS Common Cartridge 1.3- (.imscc of .zip), een Moodle-back-up (.mbz) of een SCORM-pakket (.zip)."
- A SCO shows in the table with Brontype `scorm-sco`, Doel `Les`, Resultaat `Geïmporteerd`.
- A SCORM 2004 SCO shows as `Verminderd` with Reden "SCORM 2004 wordt nog niet afgespeeld".

## Decisions

### D1: One import screen

The user should not need to know which package format they hold. The service picks the parser from the package contents, the way it already tells a Common Cartridge from a Moodle backup.

### D2: Files stay in Nextcloud Files

The package is unpacked under the course's folder (`/Learniq/<tenant>/<course>/scorm/<lesson>/`), the shape `contentRef`'s example already uses for cmi5. The player keeps serving one launch file per lesson; relative links inside the package resolve against that folder.

### D3: SCORM 2004 is imported, not played

Importing it now means the content is in the course and the report is honest. A follow-up adds the 2004 runtime; the lesson then starts working without a re-import.

### D4: Completion comes from the runtime that exists

No new completion logic. The SCORM 1.2 shim already maps `cmi.core.lesson_status` to xAPI and the LRS ingest already completes the lesson.

## Risks

- Large packages: unpacking runs in the request. Packages over the PHP upload limit are refused with the limit in the message; a background job is a follow-up if schools hit it.
