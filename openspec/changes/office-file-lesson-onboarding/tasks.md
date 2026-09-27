# Tasks: office-file-lesson-onboarding

Feature tier: should (V1). Stacked on lesson-ai-assist-actions (PR 1049).

## Implementation Tasks

### Task 1: Register: LessonOnboardingFile schema, teacherNote block type, demo data, catalogue
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-new-word-or-powerpoint-file-in-the-folder-is-detected-and-the-teacher-is-notified-and-nothing-is-read`
- **files**: `lib/Settings/learniq_register.json`, `lib/Settings/learniq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`, `tests/Unit/Settings/LessonOnboardingRegisterTest.php`
- **acceptance_criteria**:
  - GIVEN the register WHEN read THEN LessonOnboardingFile has the lifecycle, the created notification with a route action, and self-only read and update
  - GIVEN Lesson.blocks WHEN read THEN the type enum holds teacherNote; versions bumped
  - GIVEN gate 101 and check:schema-l10n WHEN run THEN both pass
- [x] Implement
- [x] Test

### Task 2: Folder setting and the file listener
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-chooses-one-lesson-onboarding-folder-in-their-own-files`
- **files**: `lib/Service/LessonOnboarding/OnboardingFolderSetting.php`, `lib/Listener/LessonOnboardingFileListener.php`, `lib/AppInfo/Registrar/OnboardingListenerRegistrar.php`, `lib/AppInfo/Registrar/EventListenerWiring.php`
- **acceptance_criteria**:
  - GIVEN a docx created in the owner's folder WHEN the event fires THEN one detected row is written as the owner
  - GIVEN any other node WHEN the event fires THEN nothing is looked up or written, and no failure escapes
- [x] Implement
- [x] Test

### Task 3: Readers and the draft builder
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-confirmed-word-file-becomes-one-lesson-draft`
- **files**: `lib/Service/LessonOnboarding/DocxLessonReader.php`, `lib/Service/LessonOnboarding/PresentationLessonReader.php`, `lib/Service/LessonOnboarding/LessonDraftBuilder.php`
- **acceptance_criteria**:
  - GIVEN a docx WHEN read THEN headings split sections and images carry their bytes
  - GIVEN no PresentationExtractor WHEN a deck is read THEN the reader says unavailable
  - GIVEN sections WHEN built THEN one richText per section, media after its section, teacherNote after its slide
- [x] Implement
- [x] Test

### Task 4: Importer, controller and routes
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page`
- **files**: `lib/Service/LessonOnboarding/LessonOnboardingImporter.php`, `lib/Controller/LessonOnboardingController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN another teacher's row or a non-detected row WHEN imported THEN 404 or 409 and nothing written
  - GIVEN a docx row WHEN imported THEN one draft lesson, its materials, and the row imported with lessonId
  - GIVEN a pptx row and no reader WHEN imported THEN 503 reader-unavailable and the row stays detected
- [x] Implement
- [x] Test

### Task 5: Review page, menu entry, teacherNote in composer and player
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-a-teacher-note-block-is-shown-to-staff-in-the-composer-and-never-rendered-by-the-lesson-player`
- **files**: `src/views/LessonOnboardingReview.vue`, `src/utils/lessonOnboarding.js`, `src/utils/lessonBlocks.js`, `src/registry.js`, `src/manifest.d/learning.json`, `src/views/LessonComposer.vue`, `src/views/LessonPlayer.vue`, `tests/unit-js/lessonOnboarding.test.mjs`
- **acceptance_criteria**:
  - GIVEN detected rows WHEN the page loads THEN each shows a course picker, import, dismiss, and the pupil-data warning
  - GIVEN a teacherNote WHEN the composer saves THEN its text is kept; the player does not render it
- [x] Implement
- [x] Test

### Task 6: Translations and the user guide page
- **spec_ref**: `openspec/changes/office-file-lesson-onboarding/specs/course-management/spec.md#requirement-nothing-is-extracted-until-the-teacher-confirms-one-file-on-the-review-page`
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/*.js`, `docs/user-guide/user/11-import-word-and-powerpoint.md`
- **acceptance_criteria**:
  - GIVEN every new string WHEN check:l10n-js runs THEN en and nl hold it
- [x] Implement
- [x] Test

## Verification
- [x] `openspec validate office-file-lesson-onboarding --strict` passes
- [ ] Before push: check:strict, lint, format, l10n checks, gate 101 and hydra gates run once, exit codes in the PR body

## Quality checklist
- PHPUnit for every new class (tests/Unit/...), at least three test methods each.
- Newman: not added; the endpoints are exercised by the controller tests.
- Browser: deferred (test plan TC-7).
- Dutch and English strings for every new label.
