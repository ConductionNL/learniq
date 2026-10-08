# Tasks: upload a SCORM package and have it report its own completion

## 1. Parse

- [ ] 1.1 Add `lib/Service/CoursePackage/ScormManifestParser.php`: read `imsmanifest.xml`, return version (1.2 or 2004), the SCOs in organization order with title and launch href, and the resource file list. Verify: PHPUnit on three fixtures under `tests/fixtures/scorm/` (single SCO 1.2, three SCOs 1.2, one SCO 2004).

## 2. Import

- [ ] 2.1 In `CoursePackageImportService::import()`, detect a SCORM package by its manifest and route to the parser. Verify: PHPUnit, the Common Cartridge and Moodle paths unchanged.
- [ ] 2.2 Unpack into `/Learniq/<tenant>/<course>/scorm/<lesson>/` through Nextcloud's `IRootFolder`; skip and report entries with `..` or absolute paths. Verify: PHPUnit with the escaping fixture.
- [ ] 2.3 Create one `Lesson` per SCO with `contentType` and `contentRef`; write each SCO and dropped entry to the `CoursePackageImportReport`. Validate the created lesson payload against the real `Lesson` schema (Opis). Verify: PHPUnit.

## 3. Screen

- [ ] 3.1 Update the accepted-file text on the import screen to name SCORM, per the board `LqLesmap`. Verify: `npm run check:manifest`, `npm run check:l10n`.

## 4. Close out

- [ ] 4.1 Playwright: upload the single-SCO 1.2 fixture, open the lesson as a learner, let the fixture set `completed`, see the enrolment's progress move.
- [ ] 4.2 Update `course-management` "Run cmi5 + xAPI natively with SCORM shim" to drop "a cmi5 package importer" from the follow-up line only if that importer also lands; otherwise leave it. Set row `cont-play-scorm-package` to built and archive this change.
