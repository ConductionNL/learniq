---
kind: code
---

# Upload a SCORM package and have it report its own completion

## Why

A training company that buys e-learning gets it as a SCORM zip. learniq can play a SCORM 1.2 lesson: `src/utils/scorm12Runtime.js` turns the package's `cmi.*` calls into xAPI statements on `POST /api/lrs/statements`, built by the archived `cmi5-xapi-lrs-ingest`. What it cannot do is take the zip. The player serves one lesson file by `contentRef`, so someone has to unpack the package by hand into Nextcloud Files and type the path of its launch file. Nobody outside the team knows to do that. The archived change split this half off as future work, and the course-management spec still lists "a cmi5 package importer" as a follow-up.

One row, one change.

### Matrix rows (`learniq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `cont-play-scorm-package` | Upload a SCORM package and have it report its own completion. | `partial`: the SCORM 1.2 runtime reports completion; unpacking an uploaded zip is not built |

## What changes

- The import screen that already takes a Common Cartridge or Moodle backup also takes a SCORM zip.
- learniq reads `imsmanifest.xml`, finds the SCO launch files and the SCORM version, and unpacks the package into the course's folder in Nextcloud Files.
- Each SCO becomes a lesson with `contentType` `scorm12` or `scorm2004` and `contentRef` set to its launch file. A single-SCO package becomes one lesson.
- The import report lists every SCO and resource as imported, reduced or dropped with a reason, as it does for the other package types.
- SCORM 2004 packages are imported and listed, and the lesson says the 2004 runtime is not available yet. Playing them is out of scope.

## Capabilities

### Modified capabilities

- `course-management`: ADDED requirements for the SCORM package import.

## Impact

- **Backend**: `lib/Service/CoursePackage/ScormManifestParser.php`, a SCORM branch in `CoursePackageImportService::import()` chosen by the presence of `imsmanifest.xml` with a SCORM schema version, unpacking through Nextcloud's file API into the course folder.
- **Register**: none. `Lesson.contentType` already has `scorm12` and `scorm2004`; `CoursePackageImportReport` already holds per-item results.
- **Frontend**: the accepted-file text on the import screen.
- **Security**: zip entries are unpacked only inside the course folder; an entry with `..` or an absolute path is dropped and reported.
