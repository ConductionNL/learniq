# Tasks: docx-through-documentextractor

## Implementation Tasks

### Task 1: Read Word files through DocumentExtractor, with the fallback
- **spec_ref**: `openspec/changes/docx-through-documentextractor/specs/course-management/spec.md#requirement-a-word-file-is-read-by-openregisters-document-reader-when-there-is-one`
- **files**: `lib/Service/LessonOnboarding/DocumentLessonReader.php`, `lib/Service/LessonOnboarding/DocumentBlockText.php`, `lib/Service/LessonOnboarding/DocumentImageLoader.php`, `lib/Service/LessonOnboarding/OfficeLessonExtractor.php`, `tests/Unit/Service/LessonOnboarding/DocumentLessonReaderTest.php`
- [x] Test with a stub extractor written first and red
- [x] Implement

## Verification
- [x] `openspec validate docx-through-documentextractor` passes
- [x] `composer check:strict`, `npm run lint`, hydra gates, each with its exit code in the PR body
