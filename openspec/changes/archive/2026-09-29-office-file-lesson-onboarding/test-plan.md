# Test Plan: office-file-lesson-onboarding

No live instance and no browser are allowed in this lane, and openregister PR 4077 is not merged. Every
decision is covered by PHPUnit (`vendor/bin/phpunit --filter <Class>`) and `node --test`.

## Test Cases

### TC-1: The folder setting
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files`
- **type**: api
- **preconditions**: a user folder double holding a folder and a file
- **steps**: PUT a folder path, a file path, an empty path; GET
- **expected result**: the folder id is stored; a file is refused with 400; empty clears; GET answers id and path
- **test command**: `vendor/bin/phpunit --filter 'LessonOnboardingControllerTest|OnboardingFolderSettingTest'`

### TC-2: Detection
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read`
- **type**: functional
- **preconditions**: a teacher with a folder id set
- **steps**: raise NodeCreatedEvent for a docx in the folder, a docx elsewhere, a png in the folder, a docx already recorded, and a lookup that throws
- **expected result**: one row for the first case with the owner as teacher and `_rbac: false`; nothing for the others; the thrown error is logged, not raised; `getContent()` is never called
- **test command**: `vendor/bin/phpunit --filter LessonOnboardingFileListenerTest`

### TC-3: Confirmation guards
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page`
- **type**: security
- **preconditions**: rows of another teacher and rows not in `detected`
- **steps**: POST import
- **expected result**: 404 and 409; no object written
- **test command**: `vendor/bin/phpunit --filter LessonOnboardingControllerTest`

### TC-4: Word structure
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft`
- **type**: functional
- **preconditions**: a docx built in the test with ZipArchive: a title, two headings, a list, a table, an image
- **steps**: read it, build the draft, run the importer with doubles
- **expected result**: two sections plus the untitled lead, list and table lines, one image with bytes; a lesson with `contentType: text` and no `lifecycle`; two materials; the row imported with inputs
- **test command**: `vendor/bin/phpunit --filter 'DocxLessonReaderTest|LessonDraftBuilderTest|LessonOnboardingImporterTest'`

### TC-5: PowerPoint and the missing reader
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-powerpoint-file-becomes-one-lesson-draft-or-waits-when-the-reader-is-missing`
- **type**: functional
- **preconditions**: an extractor double returning three slides (one hidden, one with notes); no extractor
- **steps**: map and import
- **expected result**: two slide blocks and one teacherNote; importNote counts the hidden slide; without the extractor: 503 `reader-unavailable`, nothing written, row untouched
- **test command**: `vendor/bin/phpunit --filter 'PresentationLessonReaderTest|LessonOnboardingImporterTest'`

### TC-6: Teacher notes
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-note-block-is-shown-to-staff-in-the-composer-and-never-rendered-by-the-lesson-player`
- **type**: regression
- **preconditions**: blocks with a teacherNote
- **steps**: serialise; filter for the player; read the register enum
- **expected result**: text kept; filtered out of the player list; enum holds `teacherNote`
- **test command**: `node --test tests/unit-js/lessonOnboarding.test.mjs`, `vendor/bin/phpunit --filter LessonOnboardingRegisterTest`

### TC-7: Live walk-through (after openregister PR 4077)
- **spec_ref**: all requirements
- **type**: persona
- **persona**: a secondary school teacher with a drive of old lessons
- **preconditions**: learniq and OpenRegister with PR 4077 on a test instance
- **steps**: choose a folder, drop a docx and a pptx, follow the notification, import one, dismiss one, open the lesson in the composer and the player
- **expected result**: as the scenarios describe
- **test command**: `/test-functional` (deferred)

## Coverage Summary
All six requirements are covered by TC-1 to TC-6; TC-7 is the manual check once the reader lands.

## Out of Scope
A Playwright journey: a file event needs the shared instance, which lanes may not drive.
