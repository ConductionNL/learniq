---
kind: code
depends_on: [course-content-metadata]
---

# Proposal: lesson-sharing-consent-gate

## Summary
Before a course package leaves the school, someone has to be able to say "this may leave". Today `CoursePackageExportService` writes a full, lossless package for anyone the action matrix allows, with the school's own references, file paths and even an assessment's access code in it. This change adds a share export next to the existing one. It runs a gate first: the course has an open licence and an author, no lesson or material carries a closed licence, and the exporting teacher confirms two things in so many words, that the package holds no pupil data and that the school may share everything in it. The share package drops school-bound and personal fields. Each successful share export leaves a `CourseShareConsent` record: who confirmed what, when, under which licence.

## Motivation
Round 2 recon E, proposed-changes row `lesson-sharing-consent-gate`: "Before `CoursePackageExportService` can produce a package intended to leave the school, require an explicit 'may this leave the school' confirmation covering (a) no unstripped pupil personal data in the package and (b) no publisher-copyrighted method material without clearance."

Section 3 (Copyright): the education exception in the Auteurswet (art. 16 and 12.5) covers use in class, not redistribution. A teacher's slide deck built on a publisher's method often embeds publisher text under that exception only, and "is not clear to share even though the teacher authored the surrounding lesson. A sharing feature needs an explicit 'this may leave the school' gate, not an implicit one." Section 6 (Legal, minors): pupil work, names or photos can end up in a shared lesson unless the export path strips them.

Assumption A10: "a shared package carries a licence, NL-LOM metadata and a 'may leave the school' check". Decision D22: sharing runs through OpenRegister's store plane; the next change publishes exactly the package this gate produces.

Competitor evidence: Wikiwijs publishes every arrangement under an open Creative Commons licence (recon E section 2, wikiwijs.nl, read 2026-09-27); licence choice is the precondition of sharing there too.

## Affected Projects
- [x] Project: `learniq`: `lib/Service/CourseSharingGate.php` (new), `lib/Service/CourseSharePackageBuilder.php` (new), `lib/Service/CourseShareExportService.php` (new), `lib/Service/CoursePackageExportService.php` (tree and payload builders made public), `lib/Controller/CourseSharingController.php` (new), `appinfo/routes.php`, `lib/actions.seed.json`, `lib/Settings/learniq_register.json` (new `CourseShareConsent` schema, `info.version`), `lib/Settings/learniq_mock_register.json`, `src/views/ExportRequestView.vue`, `src/utils/customPages.js`, `l10n/`, tests.

## Scope

### In Scope
- `CourseSharingGate::check()`: returns the list of reasons a course may not leave: no licence, `all-rights-reserved`, no author, a lesson or material with a non-open licence, pupil data not confirmed, rights not confirmed.
- `CourseShareExportService::export()`: gathers the tree through `CoursePackageExportService`, runs the gate, strips the package, adds a `sharing` block (licence, author, subject, levels, language, goals covered, shared at), writes a `CourseShareConsent`, returns the learniq JSON package. The package carries the chosen `author`, never the Nextcloud user id of whoever confirmed.
- Stripping: `@self`, `tenant_id`, material `fileRef` paths, school-bound references (`sessionId`, `cohortId`, `curriculumPlanId`, `curriculumPlanComponentId`, `programmeIds`, `gradeEntryComponentId`, `gradeScaleId`), an assessment's `accessCode`, and all LTI placements (they are the school's own tool deployments).
- `POST /api/course-management/course-package-share` (`courseId`, `noPupilData`, `rightsCleared`), behind a new action `course-package.share` (admin by default, like every action in the matrix): 422 with `blockers` when the gate refuses. The existing export endpoint is untouched.
- `CourseShareConsent` schema: course, course name, purpose, confirmed by, confirmed at, both confirmations, licence. Read by the course-authoring staff groups and the person who confirmed.
- The export page gains a "Share outside the school" switch with the two confirmations and shows the reasons when the gate refuses.

### Out of Scope
- Publishing to a registry (next change, `lesson-sharing-via-store-plane`).
- Scanning free text or files for pupil names. The teacher's confirmation covers content; see design D3 for why no automatic scan.
- A moderation or take-down process between schools.
- Common Cartridge as a share format: the share package is learniq JSON, the format the store install imports.

## Approach
A pure gate class (no I/O) that the export service calls, so the rules are unit-testable in isolation and reusable by the store publish path. A new thin controller with its own route and action keeps the existing export byte-for-byte as it was; sharing writes a record, so it is a POST, not a GET.

## New Dependencies
None.

## Impact
- One new POST route and action; the existing export route is unchanged.
- One new schema.
- The export page gets one switch and two checkboxes.

## Cross-Project Dependencies
None.

## Risks

### Risk 1: A confirmation is ticked without reading
**Severity:** Medium. **Mitigation:** the wording names the concrete cases (pupil names, photos, work; publisher method material), and the consent record names the person, so the school can follow up.

### Risk 2: A field that identifies a person survives stripping
**Severity:** Medium. **Mitigation:** the strip list is a constant with its own test; any new person field on these schemas needs adding there, which the test's docblock says.

### Risk 3: Stacked on course-content-metadata
**Severity:** Low. **Mitigation:** branch cut from `feat/course-content-metadata`; it lands after that PR.

## Rollback Strategy
Revert the commit. The share path disappears; the regular export is untouched. Existing `CourseShareConsent` rows stay as records.

## Open Questions
None.
