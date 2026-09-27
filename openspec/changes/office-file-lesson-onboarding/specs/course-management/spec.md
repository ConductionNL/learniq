# course-management Specification

## ADDED Requirements

### Requirement: A teacher chooses one lesson onboarding folder in their own files

<!-- @e2e exclude The folder picker is Nextcloud's own file picker; the setting's validation is server-side and covered by LessonOnboardingControllerTest (testSetFolderStoresTheIdOfAFolderInTheUsersFiles, testSetFolderRefusesAFile, testSetFolderWithAnEmptyPathClearsIt) and OnboardingFolderSettingTest. -->

A teacher MUST be able to choose one folder in their own Nextcloud files as their lesson onboarding folder,
through `PUT /apps/learniq/api/lesson-onboarding/folder` with a `path` relative to their files. The system
MUST resolve the path in the calling user's own file tree, MUST refuse a path that is not a folder, and MUST
store the folder's file id as a per-user setting, so a rename keeps the choice. An empty `path` MUST clear the
setting. `GET` on the same route MUST return the stored folder's id and current path, or nulls when none is
set or the folder no longer exists.

#### Scenario: A teacher picks a folder

- **GIVEN** a teacher with a folder `Lessen inbox` in their files
- **WHEN** they choose it as their onboarding folder
- **THEN** the setting holds that folder's file id
- **AND** `GET /api/lesson-onboarding/folder` answers its id and the path `/Lessen inbox`

#### Scenario: A file is not a folder

- **GIVEN** a teacher who passes the path of a `.docx` file
- **WHEN** the request is handled
- **THEN** it is refused with 400 and the setting is unchanged

### Requirement: A new Word or PowerPoint file in the folder is detected and the teacher is notified, and nothing is read

<!-- @e2e exclude A file event cannot be raised from a browser test without driving the shared instance; the listener is covered by LessonOnboardingFileListenerTest (testADocxInTheFolderIsRecordedForItsOwner, testAFileOutsideTheFolderIsIgnored, testAnotherTypeIsIgnoredBeforeAnyLookup, testAFileRecordedBeforeIsNotRecordedTwice, testAFailureNeverReachesTheUpload) and the notification shape by LessonOnboardingRegisterTest. -->

The system MUST listen for `OCP\Files\Events\Node\NodeCreatedEvent`. For a file whose name ends in `.docx` or
`.pptx` (case-insensitive) and whose parent folder is the onboarding folder of the file's owner, it MUST
create one `LessonOnboardingFile` row in state `detected` carrying `teacherId` (the owner), `fileId`,
`fileName`, `filePath`, `mimeType`, `format` (`docx` or `pptx`) and `detectedAt`. It MUST NOT open or read
the file's content. It MUST NOT create a second row for a file id it already recorded for that teacher. Every
other node MUST be ignored before any setting or object lookup, and no failure in the listener MUST reach the
file operation that raised the event. The `LessonOnboardingFile` schema MUST declare a notification on
creation to the `teacherId` user with an action that opens the import page (`course-packages/import`).

#### Scenario: A teacher drops a Word file in the folder

- **GIVEN** a teacher whose onboarding folder is `Lessen inbox`
- **WHEN** `Breuken.docx` is created in that folder
- **THEN** one `LessonOnboardingFile` row exists with `teacherId` the teacher, `format: docx` and `lifecycle: detected`
- **AND** the teacher gets a Nextcloud notification that links to the review page
- **AND** the file content has not been read

#### Scenario: A file elsewhere or of another type is ignored

- **GIVEN** the same teacher
- **WHEN** `Breuken.docx` is created in another folder, or `foto.png` in the onboarding folder
- **THEN** no row is created

### Requirement: Nothing is extracted until the teacher confirms one file on the review page

<!-- @e2e exclude Needs a detected row and a live file on the shared instance; the confirmation guard is covered by LessonOnboardingControllerTest (testImportRefusesARowOfAnotherTeacher, testImportRefusesARowThatIsNotDetected) and the page's request shapes by tests/unit-js/lessonOnboarding.test.mjs. -->

The review section on the "Import course package" page (`/course-packages/import`) MUST list the calling teacher's `LessonOnboardingFile` rows in state
`detected`, each with a course picker, an "Import as lesson draft" action and a "Dismiss" action. Next to the
import action the page MUST say that a lesson is visible to everyone in the school and that a file with pupil
names, marks or notes about a pupil does not belong in a lesson. Extraction MUST happen only through
`POST /apps/learniq/api/lesson-onboarding/files/{id}/import` with a `courseId`, and that endpoint MUST refuse
a row that belongs to another teacher (404) or that is not `detected` (409). Dismissing MUST move the row to
`dismissed` without reading the file.

#### Scenario: A teacher dismisses a file

- **GIVEN** a detected row for `Rooster.docx`
- **WHEN** the teacher chooses "Dismiss"
- **THEN** the row moves to `dismissed`
- **AND** no lesson is created and the file is not read

#### Scenario: Another teacher's row cannot be imported

- **GIVEN** a detected row whose `teacherId` is another user
- **WHEN** a teacher posts an import for it
- **THEN** the answer is 404 and no lesson is created

### Requirement: A confirmed Word file becomes one lesson draft

<!-- @e2e exclude Covered by DocxLessonReaderTest (headings split sections, lists and tables become text, images are returned with their bytes, the core title names the lesson), LessonDraftBuilderTest and LessonOnboardingImporterTest (testADocxBecomesOneDraftLessonWithMaterials, testTheLessonIsNeverPublished). -->

On confirmation of a `docx` row the system MUST read the document's structure and create one `Lesson` in the
chosen course with `contentType: text`, the next free `order` in that course, and no lifecycle other than the
initial `draft`. Each heading (a paragraph styled `Heading1` to `Heading6` or `Title`, or with an outline
level) MUST start a section; the section MUST become one `richText` block whose markdown starts with the
heading, followed by its paragraphs, list items as `- ` lines and table rows as `cell | cell` lines. Text
before the first heading MUST become an untitled first section. Each embedded image MUST be written to the
teacher's files, recorded as a `Material` with its `fileRef` and the lesson id, and placed as a `media` block
after its section's text. The original document MUST become a `Material` with `kind: document`, its own
`fileRef` and the lesson id. The lesson name MUST be the document's core title, else its first `Title`
paragraph, else the file name without extension. When the structure yields no text, the system MUST fall back
to OpenRegister's `WordExtractor` flat text, one section, when that class is available. The row MUST move to
`imported` with `lessonId` and `courseId`.

#### Scenario: A lesson plan with two headings and an image

- **GIVEN** `Breuken.docx` with the headings "Start" and "Instructie", three paragraphs and one image under "Instructie"
- **WHEN** the teacher imports it into the course "Rekenen groep 6"
- **THEN** one draft lesson "Breuken" exists with a `richText` block starting "## Start", a `richText` block starting "## Instructie" and a `media` block after it
- **AND** two `Material` rows exist for the image and for `Breuken.docx`, both linked to the lesson
- **AND** the row is `imported` with the lesson's id

### Requirement: A confirmed PowerPoint file becomes one lesson draft, or waits when the reader is missing

<!-- @e2e exclude Covered by PresentationLessonReaderTest (testMissingExtractorIsReportedAsUnavailable, testSlidesMapToSections, testHiddenSlidesAreLeftOut), LessonDraftBuilderTest (notes become a teacherNote block) and LessonOnboardingImporterTest (testAPptxWithoutTheReaderLeavesTheRowDetected). -->

On confirmation of a `pptx` row the system MUST call OpenRegister's
`OCA\OpenRegister\Service\TextExtraction\PresentationExtractor::extract()` only when that class exists. Each
visible slide MUST become a section: one `richText` block starting with the slide title as a markdown heading
(or "Slide N" when it has none), followed by its body paragraphs; non-empty speaker notes MUST become a
`teacherNote` block directly after it. Hidden slides MUST be left out and counted in the row's `importNote`,
and a truncated deck MUST say so there too. The lesson MUST be created as for a Word file (course, order,
`contentType: text`, `draft`), named after the first slide title or the file name, and the deck MUST become a
`Material` with `kind: slides` and its `fileRef`. When the class is missing, the import MUST answer 503 with
`reason: reader-unavailable`, MUST create nothing, and MUST leave the row `detected`.

#### Scenario: A deck with speaker notes

- **GIVEN** `Fotosynthese.pptx` with three slides, the second hidden, the third with speaker notes
- **WHEN** the teacher imports it
- **THEN** the draft lesson has a block for slide 1, a block for slide 3 and a `teacherNote` block with slide 3's notes
- **AND** the row's `importNote` says one hidden slide was left out

#### Scenario: OpenRegister has no presentation reader yet

- **GIVEN** an OpenRegister without `PresentationExtractor`
- **WHEN** the teacher imports a `pptx` row
- **THEN** the answer is 503 with `reason: reader-unavailable`
- **AND** no lesson exists and the row stays `detected`

### Requirement: A teacher note block is shown to staff in the composer and never rendered by the lesson player

<!-- @e2e exclude Covered by tests/unit-js/lessonOnboarding.test.mjs ("teacherNote keeps its text when serialised", "the player's block list leaves teacher notes out") and LessonOnboardingRegisterTest (teacherNote is a block type). -->

`Lesson.blocks[].type` MUST accept `teacherNote`, whose payload is `text`. `LessonComposer` MUST render a
`teacherNote` block with a label saying learners do not see it in the lesson player, MUST let a teacher add
one, and MUST keep its `text` on save. `LessonPlayer` MUST NOT render a `teacherNote` block.

#### Scenario: Notes stay out of the player

- **GIVEN** a lesson with a `richText` block and a `teacherNote` block
- **WHEN** a learner opens it in the lesson player
- **THEN** only the `richText` block renders
