# Contract: office-file-lesson-onboarding

## Consumers
- `learniq` frontend (`src/components/lesson/LessonOnboardingPanel.vue`, a section of `CoursePackageImportView`): the three learniq endpoints below.
- `learniq` backend consumes two OpenRegister PHP interfaces, listed under "Consumed interfaces".

## Endpoints

### `GET /apps/learniq/api/lesson-onboarding/folder`
**Auth**: Nextcloud session (`#[NoAdminRequired]`).

**Response (200):**
```json
{ "folderId": 4711, "path": "/Lessen inbox" }
```
`folderId` and `path` are `null` when no folder is set or the folder no longer exists.

**Errors:**
| Code | Condition |
|------|-----------|
| 401  | No session |

### `PUT /apps/learniq/api/lesson-onboarding/folder`
**Auth**: Nextcloud session, CSRF.

**Request:**
```json
{ "path": "/Lessen inbox" }
```
An empty `path` clears the setting.

**Response (200):** as GET.

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | `path` is not a folder in the caller's files |
| 401  | No session |

### `POST /apps/learniq/api/lesson-onboarding/files/{id}/import`
**Auth**: Nextcloud session, CSRF. `{id}` is a `LessonOnboardingFile` uuid.

**Request:**
```json
{ "courseId": "00000000-0000-0000-0000-000000000000" }
```

**Response (200):**
```json
{ "lessonId": "00000000-0000-0000-0000-000000000000", "lessonName": "Breuken", "courseId": "00000000-0000-0000-0000-000000000000", "blocks": 4, "materials": 2, "notes": ["1 hidden slide left out"] }
```

**Errors:**
| Code | Condition |
|------|-----------|
| 400  | No `courseId` |
| 401  | No session |
| 404  | No such row, or the row belongs to another teacher |
| 409  | The row is not `detected` |
| 410  | The file no longer exists in the teacher's files |
| 422  | The course does not exist, or the file holds no readable lesson content |
| 503  | `{"error": "...", "reason": "reader-unavailable"}`: a pptx and no OpenRegister `PresentationExtractor` |

## Error Codes
| Code | Meaning | Condition |
|------|---------|-----------|
| 200 | Done | Setting read or stored; lesson draft created |
| 400 | Bad request | Missing `courseId`; a folder path that is not a folder |
| 401 | Unauthenticated | No session |
| 404 | Not found | Row missing or not the caller's |
| 409 | Conflict | Row already imported or dismissed |
| 410 | Gone | The file was deleted or moved out of reach |
| 422 | Unprocessable | Course missing; no readable content |
| 503 | Unavailable | pptx reader not installed |

Every error body is `{"error": "<plain sentence>"}`, plus `reason` on 503.

## Consumed interfaces (OpenRegister)
- `OCA\OpenRegister\Service\TextExtraction\PresentationExtractor::extract(OCP\Files\File): ?array`, returning
  `{slides: list<{number: int, hidden: bool, title: string, body: list<string>, notes: string, images: list<{target, external, name, description}>}>, truncated: bool}` or `null`. From openregister PR 4077, not merged. Called only when the class exists.
- `OCA\OpenRegister\Service\TextExtraction\WordExtractor::extract(OCP\Files\File): ?string` (on `development`).
  Called only when the class exists, as the docx fallback.

## Versioning
Unversioned, like every learniq REST endpoint. New optional response fields may be added.

## Breaking Change Policy
A change to the OpenRegister return shapes above breaks the adapter; `PresentationLessonReader` treats an
unexpected shape as "no readable content" (422) rather than failing. A rename of the learniq fields ships with
the page in the same PR.

## SLA
The import reads one file and writes one lesson plus its materials in one request; a large deck (500 slides, the
extractor's cap) is the worst case. The listener adds one setting read and one parent id to an upload of a
`.docx` or `.pptx` file, and nothing to any other upload.
