---
kind: code
depends_on:
  - lesson-ai-assist-actions
---

# Proposal: office-file-lesson-onboarding

## Summary
A teacher who already has lessons in Word or PowerPoint has to retype them into learniq. This change lets a teacher pick an onboarding folder in Nextcloud Files. Learniq notices each new `.docx` or `.pptx` file that lands there and notifies the teacher, but reads nothing until the teacher confirms that file in a small review section (decision D17). On confirmation a Word file becomes one `Lesson` draft (a text block per heading section, images as `Material` rows) and a PowerPoint file becomes one `Lesson` draft (a text block per slide, speaker notes as a teacher note block); the original file stays linked as a `Material`. Nothing is published: every lesson lands in `draft`.

## Motivation
Round 2 recon D (`/home/rubenlinde/memcap-work/learniq-mi/learniq/_round2/recon/D-ai-lessons-onboarding-styles.md`), section 1, found three gaps: learniq has no folder watching ("Files-app folder watching / NodeCreatedEvent listener inside learniq itself: missing"), the course package importer "does not cover docx/pptx, a structurally different format family", and OpenRegister's `WordExtractor` returns "flat text (search/indexing use, not structure)". Section 4 proposes this change; section 5 question 4 recommended option C, detect and notify, then extract on confirmation, which Ruben took as decision D17 ("the confirmation is where the pupil-data warning lives").

Competitor evidence (vendor claims, recon D section 2):
- Studytube: "transform existing knowledge into courses by uploading SCORM, PDF, or PPT files" (`_round1/corporate-lms/round1/documented-columns.md:294`).
- Docebo Creator: lesson drafts "based on your prompt and any uploaded documents" (same row, source in `_round1/corporate-lms/round1/sources.md:14`).
- Moodle CourseAI: "a fully structured course in under three minutes" from uploaded materials (moodle.com/news/moodle-plugin-courseai, read 2026-09-27).
No Dutch K-12 competitor frames document upload as onboarding (recon D section 2), so this is a differentiator for the teacher who switches with a drive full of lessons.

Placement: rung 3, a section on the existing "Import course package" page (`CoursePackageImportView`, Learning menu). Bringing existing lessons in is one job, so both sources share one page; no new page, no new menu entry, and the app's custom-page count stays at 26 (gate 69 ratchet).

Plan assumption A6: the onboarding folder detects and notifies; extraction and lesson creation happen only after the teacher confirms.

## Affected Projects
- [x] Project: `learniq`: a per-teacher onboarding folder setting, a file listener, a `LessonOnboardingFile` schema with a lifecycle and a notification, a review section on the import page, a docx reader, an adapter for OpenRegister's `PresentationExtractor`, an importer that writes `Lesson` and `Material` drafts, and a `teacherNote` block type.

## Scope

### In Scope
- A teacher chooses one onboarding folder in their own files (`PUT /api/lesson-onboarding/folder`), stored as a per-user setting.
- A `NodeCreatedEvent` listener records every `.docx` or `.pptx` created directly in that folder as a `LessonOnboardingFile` row in state `detected`. It reads the file name and type only, never the content.
- A declarative notification on that row tells the teacher, with a link to the import page.
- A review section on the import page lists the teacher's detected files, with a course picker, an "Import as lesson draft" action and a "Dismiss" action, and says at the confirmation step that a lesson is visible to the whole school, so a file with pupil data does not belong there.
- On confirmation (`POST /api/lesson-onboarding/files/{id}/import`): a docx becomes one `Lesson` draft (one `richText` block per heading section, each embedded image a `Material` plus a `media` block), a pptx becomes one `Lesson` draft (one `richText` block per visible slide, speaker notes as a `teacherNote` block), and the original file becomes a `Material` with its `fileRef`. The row moves to `imported` with the lesson id.
- The pptx path calls OpenRegister's `PresentationExtractor` (openregister PR 4077) duck-typed; until that class exists the import answers "not available yet" and the file stays in the list.
- A `teacherNote` block type on `Lesson.blocks`, shown in the composer and never rendered by the lesson player.

### Out of Scope
- Tidying extracted text with AI. The hermiq delegate could do it later; this change sends nothing to a model.
- `.doc`, `.odt`, `.ppt`, `.odp` and PDF. Only the two Office Open XML formats are read.
- Subfolders and shared folders owned by someone else: only files created directly in a folder the teacher owns are detected.
- Images inside PowerPoint slides: the deck itself stays linked as a `Material`.
- A staff-only store for teacher notes. The player hides them, but a learner who reads the raw lesson object through the API can see them (Risk 2).

## Approach
One listener, one importer, one page. The listener follows the fleet's `NodeCreatedEvent` pattern (integriq `NextcloudFileEventListener`, OpenRegister `FileChangeListener`): cheap filters first, a broad catch so an upload never fails because of learniq. The detection row carries the lifecycle and the notification declaratively (ADR-031). The importer is the one legitimate PHP seam (document parsing, ADR-031 exception) and writes through the existing `CoursePackageObjectWriter` and `CoursePackageFileWriter`. The docx structure is read with ZipArchive and DOMDocument, the technique OpenRegister's `PresentationExtractor` uses, because `WordExtractor` drops headings and images; `WordExtractor` stays the fallback for a document whose structure yields no text. Details in design.md.

Stacked on `lesson-ai-assist-actions` (PR 1049): both change the composer's block serialiser, which that change moved into `src/utils/lessonBlocks.js`.

## New Dependencies
None. `ext-zip` is already a learniq requirement. OpenRegister's `PresentationExtractor` and `WordExtractor` are called only when present.

## Impact
- `lib/Settings/learniq_register.json`: new `LessonOnboardingFile` schema; `Lesson.blocks[].type` gains `teacherNote`; version bumps.
- `lib/Settings/learniq_mock_register.json`: three demo rows for the new schema.
- `lib/Listener/LessonOnboardingFileListener.php`, `lib/AppInfo/Registrar/OnboardingListenerRegistrar.php`, `EventListenerWiring.php`: new listener and its wiring.
- `lib/Service/LessonOnboarding/`: folder setting, docx reader, presentation adapter, draft builder, importer.
- `lib/Controller/LessonOnboardingController.php`, `appinfo/routes.php`: three routes.
- `src/components/lesson/LessonOnboardingPanel.vue`, `src/utils/lessonOnboarding.js`, `src/views/CoursePackageImportView.vue`: the review section on the import page.
- `src/views/LessonComposer.vue`, `src/views/LessonPlayer.vue`, `src/utils/lessonBlocks.js`: the `teacherNote` block.
- Tests, translations, a user guide page.

## Cross-Project Dependencies
- `openregister` PR 4077 (`pptx-structured-reader`) provides `OCA\OpenRegister\Service\TextExtraction\PresentationExtractor::extract(File): ?array`. Not merged; learniq degrades to "not available yet" for pptx until it is. Docx does not depend on it.
- `openregister` `WordExtractor::extract(File): ?string` (on `development`) is the docx fallback.
- `lesson-ai-assist-actions` (learniq PR 1049), the base of this branch.

## Risks

### Risk 1: A teacher imports a file that holds pupil data
**Severity:** High. **Mitigation:** nothing is read before the teacher confirms that one file (D17). The confirmation step says that a lesson is visible to everyone in the school and that a file with pupil names, marks or notes does not belong there. The detection row stores only the file name, path and type, and only the teacher can read it.

### Risk 2: Teacher notes are hidden, not protected
**Severity:** Medium. **Mitigation:** the lesson player never renders a `teacherNote` block and the composer labels it. OpenRegister enforces only the schema-level `authorization` block, not per-property or per-block rules, so a learner who reads the lesson object through the API can see the notes. Documented in the guide and the design; a staff-only schema for notes is a named follow-up.

### Risk 3: The listener slows every upload on the instance
**Severity:** Medium. **Mitigation:** the listener returns before any lookup unless the node is a file with a `.docx` or `.pptx` name; then it reads one user setting and one parent id. Every failure is caught and logged, so an upload never fails because of learniq.

### Risk 4: The pptx reader is not merged
**Severity:** Low. **Mitigation:** duck-typed call with a "not available yet" answer; the detection row stays `detected`, so the teacher can import the deck once OpenRegister ships the reader.

## Rollback Strategy
Revert the PR. The new schema and the `teacherNote` enum value are additive; existing lessons are untouched. Imported lessons stay as ordinary draft lessons. Detection rows can be left in place or deleted by an admin.

## Open Questions
None blocking. Assumptions taken headless are listed in design.md under "Decisions taken headless".
