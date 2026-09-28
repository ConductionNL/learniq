# Design: lesson-sharing-consent-gate

## Architecture Overview
```
ExportRequestView (share switch)
   └─ POST /api/course-management/course-package-share
        CourseSharingController::share()        ActionAuth 'course-package.share'
          └─ CourseShareExportService::export()
               ├─ CoursePackageExportService::gatherCourseTree()   (existing reads, RBAC applied)
               ├─ CourseSharingGate::check()                        (pure rules)
               ├─ CoursePackageExportService::toScholiqPayload()    (existing JSON shape)
               ├─ CourseSharePackageBuilder::strip()/sharingBlock() (pure)
               └─ ObjectService::saveObject('course-share-consent')
```
The regular export (`CoursePackageExportController`) is unchanged; `exportScholiqJson()` now builds its payload through the extracted `toScholiqPayload()`, same output.

## API Design

### `POST /api/course-management/course-package-share`
**Request:** `courseId` (string), `noPupilData` (bool), `rightsCleared` (bool).
**Response 200:** the package as `application/json` download, `course-<id>_share.json`.
**Response 422:**
```json
{
  "error": "This course may not leave the school yet.",
  "blockers": [
    {"code": "author-missing", "id": "<course-uuid>", "name": "Nederlands havo 4"}
  ]
}
```
401 without a session; 403 (OCS) when the action matrix refuses; 400 without `courseId`; 500 when the consent record cannot be written.

## Nextcloud Integration
- Controllers: `CourseSharingController` (new, `#[NoAdminRequired]`, guarded by `ActionAuthService::requireAction('course-package.share')`).
- Services: `CourseSharingGate`, `CourseSharePackageBuilder` (pure, no I/O), `CourseShareExportService` (orchestrates; uses OpenRegister's `ObjectService`).
- Mappers/Entities: none.
- Events/Hooks: none.

## Decisions

### D1: A separate share path, the regular export untouched
The regular export is a lossless round-trip for the school itself (backup, move between instances of one school) and stays as it is. Sharing is a different act with a different package, so it gets its own route, action and controller. Alternative considered: a `purpose=share` query parameter on the GET export. Rejected: sharing writes a record, so it should not be a GET, and a separate action lets a school allow export but not sharing.

### D2: The gate is a pure class
`CourseSharingGate::check()` takes arrays and two booleans and returns blockers. No I/O means every rule is unit-tested directly, and the store publish path in the next change calls the same class.

### D3: Confirmations, not a name scan
Pupil data in a lesson hides in free text, images and attached files. A scan for the names of enrolled pupils would miss photos and scanned work, flag common first names, and suggest a safety it cannot give. The honest control is structural stripping of every field that is not content, plus an explicit confirmation by the person who knows the material, recorded with their name. Same for copyright: no system can tell a publisher's excerpt from a teacher's own text.

### D4: What "open" means
Open = CC0 or any CC 4.0 licence, including NC and ND variants (they allow sharing between schools, which is non-commercial). `all-rights-reserved` and an empty licence block. A material's free-text `license` blocks when non-empty and not one of the open codes (compared case-insensitively); an empty material licence falls under the course licence and the rights confirmation.

### D5: Strip school-bound references too
A shared package must not carry the school's session, cohort, curriculum-plan, programme or grade-scale ids: they mean nothing elsewhere and reveal the school's structure. LTI placements go entirely: they point at the school's own tool deployments. An assessment's `accessCode` is a secret.

### D6: The consent record never leaves the school
`CourseShareConsent` holds the confirming user's id; the package holds only the chosen `author`. The record is written before the download is returned, and a failed write fails the export (fail closed).

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Share rules and stripping | Imperative (service) | Runs on an export assembled across several schemas, at request time; not a property of one object. |
| Consent record | Declarative schema | Plain record with an `authorization` block. |

## Security Considerations
- Action matrix: `course-package.share` defaults to admin, like every action; a school broadens it in Admin settings.
- The tree is read through `ObjectService` with RBAC applied, as the regular export does.
- Stripping removes secrets (`accessCode`) and school references. The consent record is readable by staff groups and the confirming user only.
- CSRF: the route is a normal POST under Nextcloud's CSRF check (`@nextcloud/axios` sends the token).

## NL Design System
`NcCheckboxRadioSwitch` for the switch and the confirmations, `NcNoteCard` for the refusal list; no colours.

## File Structure
```
lib/Service/CourseSharingGate.php                 new
lib/Service/CourseSharePackageBuilder.php         new
lib/Service/CourseShareExportService.php          new
lib/Exception/SharingBlockedException.php         new
lib/Service/CoursePackageExportService.php        gatherCourseTree() public, toScholiqPayload() extracted
lib/Controller/CourseSharingController.php        new
appinfo/routes.php                                one POST route
lib/actions.seed.json                             course-package.share
lib/Settings/learniq_register.json                CourseShareConsent schema, info.version
lib/Settings/learniq_mock_register.json           demo rows
src/views/ExportRequestView.vue                   share switch
src/utils/customPages.js                          coursePackageShareUrl()
l10n/                                             strings
tests/Unit/Service/CourseSharingGateTest.php, CourseSharePackageBuilderTest.php, CourseShareExportServiceTest.php
tests/Unit/Controller/CourseSharingControllerTest.php
tests/Unit/Settings/CourseShareConsentRegisterTest.php
```

## Seed Data

### Schema: `course-share-consent`
| Field | Object 1 |
|-------|----------|
| id | `00000000-0000-0000-0000-0000000f0101` |
| courseId | `00000000-0000-0000-0000-00000000c001` |
| courseName | `Voorbeeld: Nederlands havo 4, schrijfvaardigheid` |
| purpose | `download` |
| confirmedBy | `staff-mentor-01` |
| confirmedAt | `2026-09-01T10:00:00Z` |
| noPupilData | `true` |
| rightsCleared | `true` |
| license | `CC-BY-SA-4.0` |
| tenant_id | `00000000-0000-0000-0000-000000000001` |

Three generated demo rows in the demo register.

## Trade-offs
A confirmation can be ticked carelessly; the record makes that visible afterwards rather than preventing it. Stripping by a fixed key list must grow with the schemas; the builder test pins the list.
