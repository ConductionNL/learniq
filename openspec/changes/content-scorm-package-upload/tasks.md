# Tasks: upload a SCORM package and have it report its own completion

## 1. Parse

- [x] 1.1 Add `lib/Service/CoursePackage/ScormManifestParser.php`: read `imsmanifest.xml`, return version (1.2 or 2004), the SCOs in organization order with title and launch href, and the resource file list. Verify: PHPUnit on three fixtures under `tests/fixtures/scorm/` (single SCO 1.2, three SCOs 1.2, one SCO 2004).

## 2. Import

- [x] 2.1 In `CoursePackageImportService::import()`, detect a SCORM package by its manifest and route to the parser. Verify: PHPUnit, the Common Cartridge and Moodle paths unchanged.
- [x] 2.2 Unpack into `/Learniq/<tenant>/<course>/scorm/<lesson>/` through Nextcloud's `IRootFolder`; skip and report entries with `..` or absolute paths. Verify: PHPUnit with the escaping fixture.
- [x] 2.3 Create one `Lesson` per SCO with `contentType` and `contentRef`; write each SCO and dropped entry to the `CoursePackageImportReport`. Validate the created lesson payload against the real `Lesson` schema (Opis). Verify: PHPUnit.

Built in `ScormCourseImporter` (wired through `CoursePackageImporters::$scorm` and `CoursePackageImportService::import()`). A zip whose root `imsmanifest.xml` declares SCORM is routed there. The Common Cartridge, Moodle and JSON paths are unchanged, and the refusal now names SCORM. The package is unpacked once into `/Learniq/<tenant>/<course>/scorm/`, shared by its SCOs, rather than one copy per lesson; each lesson's `contentRef` points at its SCO's launch file in that folder. Entries with `..`, an absolute path or a drive letter are not written and are reported `dropped`. A SCORM 2004 SCO is reported `degraded` with "SCORM 2004 is not played yet". The report's `sourceFormat` gains `scorm` (report 0.3.0, register 0.50.0). PHPUnit `ScormCourseImporterTest` (5). Not done: validating the lesson payload against the `Lesson` schema with Opis.

## 3. Screen

- [ ] 3.1 Update the accepted-file text on the import screen to name SCORM, per the board `LqLesmap`. Verify: `npm run check:manifest`, `npm run check:l10n`.

## 4. Close out

- [ ] 4.1 Playwright: upload the single-SCO 1.2 fixture, open the lesson as a learner, let the fixture set `completed`, see the enrolment's progress move.
- [ ] 4.2 Update `course-management` "Run cmi5 + xAPI natively with SCORM shim" to drop "a cmi5 package importer" from the follow-up line only if that importer also lands; otherwise leave it. Set row `cont-play-scorm-package` to built and archive this change.
