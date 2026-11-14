# Open learning material (new spec)

**Spec refs**: `open-learning-material` (new, canonical text in `openspec/specs/open-learning-material/spec.md`), `course-management` (delta beside this one), integriq `edurep-source`.

# Open learning material

Boards (canon, decision 130): `LqLesmateriaal`, `LqOpenLesmateriaal`, `LqOpenLesmateriaalDetail`,
`LqOpenLesmateriaalStaten` on identity.conduction.nl/screens, plus the media block on `LqLesMaken` and
the two source rows on `LqKoppelingen`. Decisions 376 and 377.

## Purpose
A teacher searches open learning material from inside learniq and adds a result to a lesson or course as
`Material`, with the licence and the makers always shown. The source is Edurep (Kennisnet): a free, keyless
SRU search over about 495,000 Dutch records covering Wikiwijs (Maken, Delen, Toetsen), Schooltv,
SURFsharekit and hbo/wo collections. Edusources (SURF) has no public API (tested 5 Nov 2026), so there is
no Edusources source; the source interface in integriq stays open for one. Searching is read-only; publishing
back is out of scope (decision 376: lessons are shared through the OpenRegister store).

## What
- The search panel, opened from the media block in the lesson editor and from the Lesmateriaal tab of a
  course, calls the integriq source `edurep-source` (spec in ConductionNL/integriq) through the exchange
  client: `search(query, filters, page)` and `find(id)`.
- Filters: level (NL-LOM educational context), licence, collection (`meta.repository.id`), file type,
  language. The school profile sets the default level. KlasCement (Flemish) is off by default.
- Adding creates a `Material` with `source`, `sourceId`, `sourceUrl`, `authors`, `license` and
  `accessRights`. Licence rules are server-side.
- Admin: Edurep and Edusources as connections on the connections page, on or off per source, collections on
  or off, with the source's terms link.

## ADDED Requirements

### Requirement: Material carries its source and attribution
`Material` MUST gain the properties `source` (string, enum `own`, `edurep`, `edusources`, default `own`),
`sourceId` (string, nullable: the Edurep record identifier), `sourceUrl` (string, uri, nullable: the
record's `location`), `authors` (array of strings, the LOM vCard `FN` values) and `accessRights` (string,
enum `OpenAccess`, `RestrictedAccess`, `ClosedAccess`, nullable). The existing `license` property holds the
normalised licence code (`CC0-1.0`, `PDM`, `CC-BY-4.0`, `CC-BY-SA-4.0`, `CC-BY-NC-4.0`, `CC-BY-ND-4.0`,
`CC-BY-NC-ND-4.0`, their 3.0 forms, or `other`). Adding these properties is a schema change: the register
`info.version` in `lib/Settings/learniq_register.json` MUST be bumped (0.21.0 to 0.22.0) and the Material
schema `version` from 0.1.0 to 0.2.0, and the change MUST say so in its PR body. `fileRef` MUST become
optional, because a linked material has no file.

#### Scenario: A linked Wikiwijs arrangement stores its attribution
- **GIVEN** the Edurep record `urn:wikiwijsmaken:194413` (CC BY 4.0, maker Kirstin Beekman)
- **WHEN** a teacher adds it to lesson 7
- **THEN** a `Material` exists with `kind: link`, `source: edurep`, `sourceId: urn:wikiwijsmaken:194413`,
  `sourceUrl` the record's location, `authors: ["Kirstin Beekman"]`, `license: CC-BY-4.0`, no `fileRef`
- **AND** the Material card on `LqLesmateriaal` reads "Naamsvermelding: Kirstin Beekman, CC BY 4.0, via
  Wikiwijs Maken."

### Requirement: Licence rules are enforced on the server
A `MaterialImportService` (or the service the build names) MUST decide link versus copy and allowed versus
refused before any object is written, from the normalised record alone. The rules, in this order:
1. `accessRights` `RestrictedAccess` or `ClosedAccess`: the file MUST NOT be copied and no `Material` MUST be
   created from the file; the record MAY be added as a link to `sourceUrl` only when its licence is open.
2. A licence with ND (no derivatives): link only, never copied, never editable in learniq.
3. An open licence (CC0, PDM, CC BY, CC BY-SA, CC BY-NC, CC BY-NC-SA) on an `OpenAccess` file: a copy into
   the course folder in Nextcloud is allowed; a web page or arrangement is always a link.
4. A licence with NC: allowed, and the `Material` MUST be flagged `nonCommercial: true`; a course that is
   paid or corporate MUST show the material as not allowed and MUST NOT serve it to learners there.
5. A record without an open licence (Edurep rights `yes` with a rights URL, or `no`): shown in results with
   a link to the source; it MAY be added as a link only when the collection is one the administrator marked
   as link-allowed (Schooltv is the only default); it MUST NOT be copied.
6. Attribution (`authors`, `license`, `source`, `sourceUrl`) MUST always be stored and MUST be rendered on
   every Material card and in the learner view; editing the title MUST NOT clear it.
The service MUST have golden tests: one per licence and access-rights combination above, proving ND never
copies, restricted files never land, attribution is always stored, and NC is flagged.

#### Scenario: A no-derivatives record can only be linked
- **GIVEN** a record with `license: CC-BY-NC-ND-3.0` and an `OpenAccess` pdf
- **WHEN** the teacher adds it
- **THEN** the `Material` is `kind: link` with `sourceUrl` set and no `fileRef`
- **AND** a request to copy or edit it is refused with a message naming the ND licence

#### Scenario: A restricted file never lands
- **GIVEN** a record whose file has `accessRights: RestrictedAccess`
- **WHEN** the teacher adds it
- **THEN** no file is fetched and no `fileRef` is written
- **AND** the result card had already shown "Alleen bij de bron" instead of Toevoegen als kopie

#### Scenario: Non-commercial material is flagged in a paid course
- **GIVEN** a `Material` with `license: CC-BY-NC-4.0` attached to a free course
- **WHEN** the course is set to paid
- **THEN** the material shows as not allowed on the course's Lesmateriaal tab and is not served to learners
- **AND** the audit trail records who changed the course and when (ADR-008)

### Requirement: Schooltv is link only
Schooltv records (collection `Schooltv`, rights `no`) MUST be added as links to schooltv.nl and MUST NOT be
embedded or played inside learniq. Reason: Schooltv's FAQ (https://schooltv.nl/veelgestelde-vragen, read
5 Nov 2026) allows embedding its player only for own, non-commercial use by private end users; no formal
NTR or NPO terms were reachable. The result card and the Material card say "speelt af op schooltv.nl".

#### Scenario: A Schooltv video opens at the source
- **GIVEN** the record `/NTR/POMS/WO_NTR_20306689`
- **WHEN** a learner opens it from a lesson
- **THEN** schooltv.nl opens in a new tab and learniq renders no player

### Requirement: The search panel shows results with facets and three states
The panel (`LqOpenLesmateriaal`) MUST show the query, the result count, result cards with title,
description, licence badge, makers, collection, level, file type and year, the buttons Toevoegen (as link or
as copy, as the rules decide), Details en licentie and Bekijk bij de bron, and pagination. Filters MUST show
counts from the source's facets. The panel MUST render the three states on `LqOpenLesmateriaalStaten`: not
searched yet (suggestions from the course), nothing found (fewer words, filters off, include KlasCement),
and source unreachable (retry; own files keep working; the administrator sees the status under
Koppelingen). The result detail (`LqOpenLesmateriaalDetail`) MUST explain the licence in plain Dutch and
state whether adding makes a link or a copy before the teacher confirms.

@e2e tests/e2e/spec-coverage/open-learning-material.spec.ts

#### Scenario: A teacher searches from the lesson editor
- **GIVEN** a teacher editing a lesson with a media block
- **WHEN** she clicks Zoek open lesmateriaal and searches "betoog"
- **THEN** the panel lists results from Edurep with the school's level preselected and KlasCement off
- **AND** choosing Toevoegen als koppeling on a CC BY 4.0 record puts the new Material in the media block

#### Scenario: Edurep does not answer
- **GIVEN** the integriq source returns a timeout
- **WHEN** the teacher searches
- **THEN** the panel shows "Edurep antwoordt niet" with Opnieuw proberen and Zoek in eigen bestanden
- **AND** materials already added remain usable

### Requirement: Sources are connections an administrator switches
The connections page (`LqKoppelingen`) MUST list Open lesmateriaal via Edurep with status from integriq,
the collections on or off, and the terms link, and Open lesmateriaal via Edusources as Niet beschikbaar with
the reason, until a supported SURF API exists. Switching Edurep off hides the search button everywhere.

#### Scenario: Edurep is switched off
- **GIVEN** the administrator switches the Edurep connection off
- **WHEN** a teacher opens the lesson editor or the Lesmateriaal tab
- **THEN** Zoek open lesmateriaal is not shown
- **AND** existing linked materials still open at the source

## Out of scope
Publishing to Wikiwijs or Edusources, bulk harvest or mirroring of a source (both need agreements), an
Edusources adapter (no public API).
