# Tasks: open-learning-material

Boards are canon (decision 130): `LqLesmateriaal`, `LqOpenLesmateriaal`, `LqOpenLesmateriaalDetail`,
`LqOpenLesmateriaalStaten`, the media block on `LqLesMaken`, the source rows on `LqKoppelingen`.
Code is generic (decision 182): no Edurep-specific class names outside the integriq source.

## 1. Material schema

- [ ] 1.1 In `lib/Settings/learniq_register.json` add to `Material`: `source` (enum `own`, `edurep`,
      `edusources`, default `own`), `sourceId`, `sourceUrl` (uri), `authors` (array of string),
      `accessRights` (enum `OpenAccess`, `RestrictedAccess`, `ClosedAccess`), `nonCommercial` (boolean,
      default false). Drop `fileRef` from `required`. Schema `version` 0.1.0 to 0.2.0.
- [ ] 1.2 Bump register `info.version` 0.21.0 to 0.22.0 and say so in the PR body. Mirror in
      `learniq_mock_register.json`.
- [ ] 1.3 Unit test: a Material without `fileRef` but with `sourceUrl` validates; one with neither is
      rejected.

## 2. Licence rules, server-side

- [ ] 2.1 Add a service under `lib/Service/` that takes a normalised source record (title, description, url,
      files[], embedUrl, authors[], license, accessRights, levels[], disciplines[], materialType, language,
      sourceId) and returns a decision: `link`, `copy` or `refused`, with `nonCommercial` and a reason.
      Rules and order: spec `open-learning-material`, requirement "Licence rules are enforced on the server".
- [ ] 2.2 Golden tests in `tests/unit/`: one case per licence and access-rights combination. They prove ND
      never copies, RestrictedAccess and ClosedAccess files never land, attribution is always stored, NC is
      flagged, Schooltv is link only, `yes`/`no` rights are never copied.
- [ ] 2.3 The add endpoint calls the service before any object or file write; refusal returns 422 with the
      reason. Copy fetches the file through integriq, never from the browser.
- [ ] 2.4 Paid or corporate course: a `nonCommercial` Material shows as not allowed on the Lesmateriaal
      tab and is not served to learners. Test with a course that changes from free to paid.

## 3. Search panel

- [ ] 3.1 Panel on nextcloud-vue components (dialog, search field, facet checkboxes with counts, result
      cards, pagination) opened from the media block in the lesson editor and from the Lesmateriaal tab.
      Pixel target: `LqOpenLesmateriaal`. A missing widget goes into nextcloud-vue (decision 153 to 156).
- [ ] 3.2 Result detail with the licence in plain Dutch and the link-or-copy sentence, target
      `LqOpenLesmateriaalDetail`. The texts per licence live in `l10n`, keyed by licence code.
- [ ] 3.3 The three states, target `LqOpenLesmateriaalStaten`: not searched yet (suggestions from the
      course subject), nothing found (fewer words, filters off, include KlasCement with its count), source
      unreachable (retry, own files keep working, status under Koppelingen).
- [ ] 3.4 School profile (po/vo/mbo/he/corporate) sets the default level filter; KlasCement off by default.
- [ ] 3.5 `tests/e2e/spec-coverage/open-learning-material.spec.ts` against recorded integriq responses:
      search from the editor, add as link, the source-down state.

## 4. Material cards and attribution

- [ ] 4.1 Material card shows source line ("Uit Edurep · Wikiwijs Maken" or "Nextcloud, map ...") and the
      attribution sentence from `authors`, `license`, collection; badge Koppeling, Kopie in Nextcloud or
      Eigen bestand. Target `LqLesmateriaal`.
- [ ] 4.2 Learner view renders the same attribution under the material; Schooltv opens at schooltv.nl in a
      new tab, no player.
- [ ] 4.3 Editing the title keeps attribution: unit test.

## 5. Connections

- [ ] 5.1 Declare Open lesmateriaal via Edurep (on/off, collections on/off, terms link to Kennisnet) and
      Open lesmateriaal via Edusources (not available, reason) where learniq declares its connections, so
      `LqKoppelingen` shows them with the status integriq reports.
- [ ] 5.2 Edurep off hides Zoek open lesmateriaal in the editor and on the tab; existing links keep working.

## 6. Verify

- [ ] 6.1 Diff check while building; one `composer check:strict` with openregister development beside it,
      `npm run lint`, vitest, build at the end; exit codes in the PR body.
- [ ] 6.2 `@spec openspec/specs/open-learning-material/spec.md` on every changed method.
