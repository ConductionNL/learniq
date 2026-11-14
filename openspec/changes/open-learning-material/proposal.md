---
kind: code
depends_on: []
---

# Proposal: open-learning-material

## Why

Ruben, 5 Nov 2026 (decision 376): learniq gets search and import of open learning material. A teacher
should find a Wikiwijs arrangement, a Schooltv video or a SURFsharekit file without leaving the lesson
editor, and the licence and the makers must never get lost on the way in.

Tested the same day (decision 377): Edurep's SRU endpoint at Kennisnet is free, keyless, covers every
sector (about 495,000 Dutch records, including Wikiwijs, Schooltv and SURFsharekit) and supports the
filters we need (`lom.educational.context`, `lom.rights.copyrightandotherrestrictions`,
`lom.technical.format`, `meta.repository.id`), facets (`x-term-drilldown`) and id lookup. Edusources
(SURF) has no reachable public API, so we build on Edurep only and keep the source interface open.

## What

- `Material` gains `source`, `sourceId`, `sourceUrl`, `authors` and `accessRights`; `fileRef` becomes
  optional. Register `info.version` 0.21.0 to 0.22.0, Material schema 0.1.0 to 0.2.0.
- A server-side licence service decides link versus copy and allowed versus refused: ND is link only,
  restricted and closed files never land, attribution is always stored, NC is flagged and blocked in paid
  or corporate courses, Schooltv is link only.
- The search panel on nextcloud-vue components, opened from the media block (`LqLesMaken`) and the
  Lesmateriaal tab (`LqLesmateriaal`): results with facet filters, licence badges, detail with the licence
  in plain Dutch, and the three states (`LqOpenLesmateriaal`, `LqOpenLesmateriaalDetail`,
  `LqOpenLesmateriaalStaten`).
- Edurep and Edusources as connections on `LqKoppelingen`.
- The source itself is the integriq change `edurep-source` (ConductionNL/integriq); learniq calls it
  through the exchange client and never talks to Kennisnet directly.

Specs: `openspec/specs/open-learning-material/spec.md` (new, mirrored in this change's delta) and the
`course-management` delta for the Material schema and the media block.

## Affected projects

- [x] learniq: `lib/Settings/learniq_register.json` (Material schema, info.version), a licence service with
  golden tests under `lib/Service/` and `tests/unit/`, the search panel under `src/`, the connections entries,
  `l10n/nl.json`, `tests/e2e/spec-coverage/open-learning-material.spec.ts`.
- [x] integriq: change `edurep-source` (its own PR).
- [ ] nextcloud-vue: only if the panel needs a widget that does not exist yet (decision 153 to 156: build
  it there, do not park).

## Decisions taken in this proposal

- Records without an open licence stay visible in results with a link to the source, so a teacher can still
  find them; they are never copied, and only Schooltv is link-addable by default. Reason: Ruben named
  Schooltv as a source (decision 376), and Schooltv's records carry rights `no`.
- Schooltv is link only. Its FAQ (https://schooltv.nl/veelgestelde-vragen) allows embedding the player only
  for own, non-commercial use by private end users; no formal terms were reachable. A school app is neither.
- No Edusources adapter now. SURFsharekit, Edusources' main source, is already in Edurep.

## Out of scope

Publishing back, bulk harvest, an Edusources adapter.
