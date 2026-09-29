# Design: office-file-lesson-onboarding

## Architecture Overview

```
Nextcloud Files ── NodeCreatedEvent ──> LessonOnboardingFileListener
                                          │ .docx/.pptx? owner's folder? parent == folder? not seen?
                                          ▼
                              LessonOnboardingFile (detected)  ── x-openregister-notifications ──> teacher
                                          │                                (action: /apps/learniq/course-packages/import)
Review section on the import page ────────┤ list own rows (OR API), Dismiss = PATCH lifecycle
                                          │
   POST /api/lesson-onboarding/files/{id}/import {courseId}
                                          ▼
                              LessonOnboardingController ──> LessonOnboardingImporter
                                                               │ read file by id in the teacher's files
                                                               ├─ docx: DocxLessonReader (ZipArchive + DOM)
                                                               │        fallback: OR WordExtractor (flat text)
                                                               ├─ pptx: PresentationLessonReader ─> OR PresentationExtractor (duck-typed)
                                                               ▼
                                                  LessonDraftBuilder (pure: sections -> blocks)
                                                               ▼
                                     CoursePackageObjectWriter (Lesson, Material) + CoursePackageFileWriter (images)
                                                               ▼
                                     TransitionEngine: row import {lessonId, courseId, importNote}
```

Everything lands in OpenRegister objects (ADR-001). The only PHP with behaviour is the listener (a file event
bridge) and the importer (document parsing), both ADR-031 exceptions; lifecycle and notification are declared on
the schema.

## API Design

### `GET /api/lesson-onboarding/folder`
**Response:** `{"folderId": 4711, "path": "/Lessen inbox"}` or `{"folderId": null, "path": null}`.

### `PUT /api/lesson-onboarding/folder`
**Request:** `{"path": "/Lessen inbox"}`; `{"path": ""}` clears.
**Response:** as GET. **400** when the path is not a folder in the caller's files.

### `POST /api/lesson-onboarding/files/{id}/import`
**Request:** `{"courseId": "<course-uuid>"}`
**Response 200:**
```json
{"lessonId": "<lesson-uuid>", "lessonName": "Breuken", "courseId": "<course-uuid>", "blocks": 4, "materials": 2, "notes": []}
```
**Errors:** 400 (no `courseId`), 404 (no such row, or not the caller's), 409 (row not `detected`),
410 (the file is gone), 422 (course not found, or the file holds no readable lesson content),
503 `{"reason": "reader-unavailable"}` (pptx without OpenRegister's `PresentationExtractor`).

All three: `#[NoAdminRequired]`, CSRF required, the caller's user id is the only identity used.

## Database Changes
None. One new OpenRegister schema and one enum value, imported by the existing register repair step.

## Nextcloud Integration
- Controllers: `LessonOnboardingController` (thin; ADR-022).
- Services: `OnboardingFolderSetting` (`IConfig` user value `lesson_onboarding_folder_id`), `DocxLessonReader`,
  `PresentationLessonReader`, `LessonDraftBuilder`, `LessonOnboardingImporter`.
- Listener: `LessonOnboardingFileListener` on `OCP\Files\Events\Node\NodeCreatedEvent` and `NodeRenamedEvent` (a file moved into the folder), registered through a new
  `OnboardingListenerRegistrar` in `EventListenerWiring::registerAll()`.
- OCP: `IRootFolder` (user folder, `getById`), `IConfig`, `IUserSession`, `IEventListener`.
- OpenRegister: `ObjectService` (row create with `_rbac: false` in the listener, reads in the importer),
  `TransitionEngine` (row `import`), `PresentationExtractor` and `WordExtractor` through the server container,
  only when the class exists.

## Decisions

### D1: A per-teacher setting, not an OpenRegister intake source
OpenRegister's `intake-source` register (recon D recommended it) is admin-only for create and update, and it
describes a source whose records land straight in a target schema for a poller. D17 needs a per-teacher folder
and a confirmation per file. The folder is a per-user `IConfig` value holding the folder's file id, so a rename
keeps working. The setting endpoint resolves the path in the caller's own files and refuses anything that is not
a folder.

### D2: Listener filters before it looks anything up
Order: node is a `File`; name ends in `.docx` or `.pptx`; owner exists; owner's setting is non-zero; parent id
equals the setting; no row for this file id and teacher yet. Only then one object is written. Only direct children
of a folder the teacher owns are detected, so a sharee's own folder settings never see someone else's file. The
whole handler is wrapped in a `Throwable` catch that logs a warning, the integriq and OpenRegister precedent: an
upload must never fail because of learniq.

### D3: The detection row is written without RBAC, as the owner
The event runs as whoever wrote the file (the teacher, their sync client, or someone they shared the folder with).
The listener writes with `_rbac: false`, `_multitenancy: false`, the owner as `currentUser` and the owner's
`tenant_id`, after its own checks proved the file sits in the owner's folder. Reads and updates of the row are
self-only through the schema's `authorization` block.

### D4: Notification and lifecycle are declared
`x-openregister-notifications.detected` fires on creation to `teacherId` over `nc-notification`, with a primary
action `{kind: route, app: learniq, route: course-packages/import}`. The lifecycle is `detected -> imported | dismissed`
and `dismissed -> detected` (restore). `import` declares `lessonId`, `courseId` and `importNote` as inputs so the
importer passes them through `TransitionEngine::transition()`.

### D5: Word structure is read in learniq, WordExtractor is the fallback
`WordExtractor` returns one flat string: headings lose their level and images vanish, so it cannot give "headings
to sections, images to Materials". `DocxLessonReader` reads `word/document.xml`, `word/styles.xml` (style names),
`word/_rels/document.xml.rels` (images) and `docProps/core.xml` (title) with ZipArchive and DOMDocument, the same
technique OpenRegister's `PresentationExtractor` uses, with its guards: a size cap per part and no DOCTYPE. When the
structural read gives no text at all, the importer asks `WordExtractor` for flat text and makes one section of it.
A structured Word reader in OpenRegister is a named follow-up; the reader sits behind one method so it can move.

### D6: PowerPoint through OpenRegister, duck-typed
`PresentationLessonReader` checks `class_exists(PresentationExtractor::class)` and `method_exists(..., 'extract')`,
then gets it from the server container. Missing means `reader-unavailable`, and the importer writes nothing. The
return shape is `{slides: [{number, hidden, title, body[], notes, images[]}], truncated}` (openregister PR 4077).

### D7: A section is one text block
A heading and everything under it become one `richText` block that starts with `## heading`. One block per
paragraph turned a ten-page lesson plan into a hundred blocks the teacher would have to merge. Images follow
their section as `media` blocks. Slides map the same way: one block per slide, notes as a `teacherNote` block.

### D8: Images are written next to course-package imports
An image's bytes go through `CoursePackageFileWriter::writeBytesToFiles()` into
`Scholiq/<tenant>/course-imports/` in the teacher's files, named `<document>-<fileId>-<image>` so two documents
never overwrite each other's `image1.png`. The `Material` gets `kind: other`, the path as `fileRef`, the course
and the lesson.

### D9: The lesson is a draft by construction
The importer never sends `lifecycle`; the schema's initial state is `draft`. `order` is one past the highest
`order` among the course's lessons. `contentType` is `text`, so the blocks render and `contentRef` is not needed.

### D10: Teacher notes are a block type
`Lesson.blocks[].type` gains `teacherNote` with `text` as payload. The composer renders and saves it, the player
filters it out. OpenRegister enforces only the schema-level `authorization` block (learniq#949 states it does not
read `x-property-rbac`), so notes are hidden, not access-controlled. A staff-only schema is the follow-up.

## Decisions taken headless
- A section maps to one text block, not one block per paragraph (D7).
- Hidden slides are left out and counted in `importNote`, since the author chose not to show them.
- Images inside slides are not extracted; the deck is linked as a `Material`.
- The detection row keeps the file name and path so the review page can list it; that is not content.
- Image materials use `kind: other`: the enum has no image kind and `slides` would mislabel them.
- The branch is stacked on PR 1049 instead of cut from `development`, because both change the block serialiser.
- The review lives as a section on the existing "Import course package" page, not as a page of its own: a new custom page failed the gate 69 ratchet (26 to 27), and importing a package and importing Office files are the same job for a teacher. The menu label "Import course package" is unchanged; renaming it to cover both is a copy decision for the product owner.

## Security Considerations
- IDOR: the import endpoint loads the row as the caller and refuses unless `teacherId` equals the caller (404).
  The file is resolved by id inside the caller's own user folder, never by a caller-supplied path.
- The course is read with RBAC on; lessons and materials are created with RBAC on, so OpenRegister decides
  whether the caller may create lessons.
- The setting endpoint resolves paths inside the caller's files only.
- XML: parts are size-capped and a part with a DOCTYPE is refused (XXE, entity expansion), as in
  `PresentationExtractor`. Zip entries are read by name, never extracted to disk.
- Pupil data: nothing is read before confirmation; the confirmation shows the warning; the row holds no content.
- Logging carries file ids and counts, never document text.

## NL Design System
Nextcloud components (`NcSelect` with `inputLabel`, `NcButton`, `NcNoteCard`, `NcEmptyContent`) and CSS
variables only. The warning is an `NcNoteCard type="warning"` next to the import action. Busy states use
`aria-busy`; results go to a polite live region.

## File Structure
```
lib/
  Controller/LessonOnboardingController.php
  Listener/LessonOnboardingFileListener.php
  AppInfo/Registrar/OnboardingListenerRegistrar.php, EventListenerWiring.php
  Service/LessonOnboarding/OnboardingFolderSetting.php
  Service/LessonOnboarding/DocxLessonReader.php
  Service/LessonOnboarding/PresentationLessonReader.php
  Service/LessonOnboarding/LessonDraftBuilder.php
  Service/LessonOnboarding/LessonOnboardingImporter.php
  Settings/learniq_register.json, learniq_mock_register.json
appinfo/routes.php
src/
  components/lesson/LessonOnboardingPanel.vue (section on CoursePackageImportView)
  utils/lessonOnboarding.js, utils/lessonBlocks.js
  views/LessonComposer.vue, views/LessonPlayer.vue
  views/CoursePackageImportView.vue
tests/Unit/... (listener, reader, adapter, builder, importer, controller, register)
tests/unit-js/lessonOnboarding.test.mjs
```

## Seed Data

### Schema: `lesson-onboarding-file`
Generated into `learniq_mock_register.json` by `generate_mock_register.py --keep` (three objects, schema-valid by
construction). Curated values, if an app owner wants them later:

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| teacherId | `jdevries` | `jdevries` | `mbakker` |
| fileName | `Breuken vergelijken.docx` | `Fotosynthese.pptx` | `Kerstviering.docx` |
| format | `docx` | `pptx` | `docx` |
| lifecycle | `detected` | `imported` | `dismissed` |

**Related items per object:** none; the file lives in the teacher's Nextcloud files.

## Declarative-vs-imperative decision
| Behaviour | Path | Why |
|---|---|---|
| Row lifecycle (detected, imported, dismissed) | declarative `x-openregister-lifecycle` | a plain state machine |
| Notify the teacher on detection | declarative `x-openregister-notifications` | created trigger, field recipient, route action |
| Detect a file | imperative listener | a Nextcloud file event, not an object event |
| Build a lesson from a document | imperative importer | document parsing (ADR-031 exception) |

## Trade-offs
- A learniq docx reader duplicates a little of what an OpenRegister structured reader would do; it is small,
  tested, and replaceable. Waiting for an OpenRegister change would leave docx without headings or images.
- The listener only sees files the teacher owns; a department folder shared to the teacher is out of scope.
