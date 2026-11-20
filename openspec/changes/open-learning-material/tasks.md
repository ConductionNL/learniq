# Tasks: open-learning-material

Boards are canon (decision 130): `LqLesmateriaal`, `LqOpenLesmateriaal`, `LqOpenLesmateriaalDetail`,
`LqOpenLesmateriaalStaten`, the media block on `LqLesMaken`, the source rows on `LqKoppelingen`.
Code is generic (decision 182): no Edurep-specific class names outside the integriq source.

## 1. Material schema

- [x] 1.1 In `lib/Settings/learniq_register.json` add to `Material`: `source` (enum `own`, `edurep`,
      `edusources`, default `own`), `sourceId`, `sourceUrl` (uri), `authors` (array of string),
      `accessRights` (enum `OpenAccess`, `RestrictedAccess`, `ClosedAccess`), `nonCommercial` (boolean,
      default false). Drop `fileRef` from `required`. Schema `version` 0.1.0 to 0.2.0.
- [x] 1.2 Bump register `info.version` 0.21.0 to 0.22.0 and say so in the PR body. Mirror in
      `learniq_mock_register.json`.
  - Built in #2111 with the versions current then: Material schema 0.1.1 to 0.2.0, register `info.version` 0.47.4 to 0.48.0 (the 0.21.0/0.22.0 above were stale when it was built).
- [x] 1.3 Unit test: a Material without `fileRef` but with `sourceUrl` validates; one with neither is
      rejected.
  - `tests/Unit/Settings/OpenLearningMaterialRegisterTest.php` (`testALinkedMaterialNeedsNoFile`, `testMaterialCarriesSourceAndAttribution`).

## 2. Licence rules, server-side

- [x] 2.1 Add a service under `lib/Service/` that takes a normalised source record (title, description, url,
      files[], embedUrl, authors[], license, accessRights, levels[], disciplines[], materialType, language,
      sourceId) and returns a decision: `link`, `copy` or `refused`, with `nonCommercial` and a reason.
      Rules and order: spec `open-learning-material`, requirement "Licence rules are enforced on the server".
- [x] 2.2 Golden tests in `tests/unit/`: one case per licence and access-rights combination. They prove ND
      never copies, RestrictedAccess and ClosedAccess files never land, attribution is always stored, NC is
      flagged, Schooltv is link only, `yes`/`no` rights are never copied.
  - `MaterialLicenceRulesTest` (golden table per licence and access right), `OpenMaterialImportServiceTest` (ND never copies, restricted never lands, NC refused in a paid course, Schooltv link only, no open licence refused).
- [ ] 2.3 The add endpoint calls the service before any object or file write; refusal returns 422 with the
      reason. Copy fetches the file through integriq, never from the browser.
  - Partial: `OpenMaterialController::add` runs `MaterialLicenceRules` before any write and answers 422 on refusal. Open: copy through integriq. Integriq offers no download yet, so a record the rules allow to copy is added as a link (`testACopyableRecordIsLinkedWhileIntegriqOffersNoDownload`).
- [x] 2.4 Paid or corporate course: a `nonCommercial` Material shows as not allowed on the Lesmateriaal
      tab and is not served to learners. Test with a course that changes from free to paid.
  - `CommercialCourseCheck` refuses adding NC material to a paid or corporate course, and the Lesmateriaal tab and `LessonPlayer.vue` show it as not allowed.
  - Server side: `NonCommercialWithholdingCascade` queues `NonCommercialWithholdingJob` whenever a course, a fee item or a material is created, updated or deleted; `NonCommercialWithholding` then sets `Material.withheldReason` to `non-commercial` on the course's NC material while the course is paid or corporate, and clears it once it is free. The Material read rule (schema 0.2.1, register 0.50.0) lets learners read a Material only while `withheldReason` is empty or absent; staff groups keep reading it.
  - `NonCommercialWithholdingTest::testACourseThatBecomesPaidStopsServingItsNcMaterial` takes a course from free to paid and back, reading through the shipped rule as a learner and as an instructor.

## 3. Search panel

- [x] 3.1 Panel on nextcloud-vue components (dialog, search field, facet checkboxes with counts, result
      cards, pagination) opened from the media block in the lesson editor and from the Lesmateriaal tab.
      Pixel target: `LqOpenLesmateriaal`. A missing widget goes into nextcloud-vue (decision 153 to 156).
- [x] 3.2 Result detail with the licence in plain Dutch and the link-or-copy sentence, target
      `LqOpenLesmateriaalDetail`. The texts per licence live in `l10n`, keyed by licence code.
- [x] 3.3 The three states, target `LqOpenLesmateriaalStaten`: not searched yet (suggestions from the
      course subject), nothing found (fewer words, filters off, include KlasCement with its count), source
      unreachable (retry, own files keep working, status under Koppelingen).
- [x] 3.4 School profile (po/vo/mbo/he/corporate) sets the default level filter; KlasCement off by default.
- [x] 3.5 `tests/e2e/spec-coverage/open-learning-material.spec.ts` against recorded integriq responses:
      search from the editor, add as link, the source-down state.
  - `tests/e2e/spec-coverage/open-learning-material.spec.ts`: search and add as a link, filters, source down.

## 4. Material cards and attribution

- [x] 4.1 Material card shows source line ("Uit Edurep · Wikiwijs Maken" or "Nextcloud, map ...") and the
      attribution sentence from `authors`, `license`, collection; badge Koppeling, Kopie in Nextcloud or
      Eigen bestand. Target `LqLesmateriaal`.
- [x] 4.2 Learner view renders the same attribution under the material; Schooltv opens at schooltv.nl in a
      new tab, no player.
- [ ] 4.3 Editing the title keeps attribution: unit test.
  - Open: learniq has no screen that edits a Material's title (materials are created from a file or from the search panel), so there is nothing in-app to test yet. The rule belongs with the edit surface when one is added.

## 5. Connections

- [x] 5.1 Declare Open lesmateriaal via Edurep (on/off, collections on/off, terms link to Kennisnet) and
      Open lesmateriaal via Edusources (not available, reason) where learniq declares its connections, so
      `LqKoppelingen` shows them with the status integriq reports.
- [x] 5.2 Edurep off hides Zoek open lesmateriaal in the editor and on the tab; existing links keep working.
  - `LessonComposer.vue` and the Lesmateriaal tab show the search only when the panel reports `enabled`; existing linked materials keep their `sourceUrl`.

## 6. Verify

- [ ] 6.1 Diff check while building; one `composer check:strict` with openregister development beside it,
      `npm run lint`, vitest, build at the end; exit codes in the PR body.
- [ ] 6.2 `@spec openspec/specs/open-learning-material/spec.md` on every changed method.
