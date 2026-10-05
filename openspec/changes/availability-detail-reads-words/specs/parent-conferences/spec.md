## ADDED Requirements

### Requirement: The teacher availability page reads its times as words
The teacher availability detail page MUST show the free time blocks as days and times in the reader's language and time zone, through the same `timeBlocks` formatter as the list. It MUST NOT show the stored JSON or ISO timestamps of the blocks.

#### Scenario: A coordinator opens a teacher's availability
@e2e exclude Manifest content; the rendering is nextcloud-vue's data widget, pinned by its CnObjectDataWidgetFormatter spec. Pinned here by tests/unit-js/availabilityDetailReadsWords.test.mjs; the live check is in the PR.
- **GIVEN** Meester Daan is free on Thursday 22 October from 18:00 to 20:00
- **WHEN** a coordinator opens his availability in Dutch
- **THEN** the Times field reads "do 22 okt, 18:00–20:00"
- **AND** no timestamp or JSON
