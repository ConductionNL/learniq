# Course Management: open learning material delta

**Spec refs**: `course-management`, `open-learning-material`. Boards: `LqLesMaken` (media block),
`LqLesmateriaal` (Lesmateriaal tab).

## MODIFIED Requirements

### Requirement: Material carries source, attribution and access rights

`Material` MUST carry `source` (`own`, `edurep`, `edusources`), `sourceId`, `sourceUrl`, `authors`,
`accessRights` and `nonCommercial` next to the existing `license`, and `fileRef` MUST be optional so a
linked material has no file. The register `info.version` MUST be bumped (0.21.0 to 0.22.0) with the Material
schema at 0.2.0. A media block MUST be able to reference a linked Material the same way it references a
file-backed one: by UUID only. The media block in the lesson editor MUST offer Zoek open lesmateriaal next
to Nieuw bestand uploaden, opening the search panel of `open-learning-material`.

#### Scenario: A media block references a linked open material
- **GIVEN** a `Material` with `kind: link`, `source: edurep` and no `fileRef`
- **WHEN** the instructional designer picks it in a media block
- **THEN** the block persists only the Material's UUID
- **AND** the learner view renders the link with the attribution sentence and opens the source

#### Scenario: The media block opens the search panel
- **GIVEN** a teacher editing a lesson with a media block
- **WHEN** she clicks Zoek open lesmateriaal
- **THEN** the search panel opens with the lesson preselected as the target
- **AND** adding a result selects the new Material in the block without leaving the editor
