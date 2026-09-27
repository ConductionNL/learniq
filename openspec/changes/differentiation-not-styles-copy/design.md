# Design: differentiation-not-styles-copy

## Architecture Overview
Form labels and help texts come from the OpenRegister schema: `fieldsFromSchema()` runs each property `title` and `description` through the app's `t()`. So the copy lives in `lib/Settings/learniq_register.json` and its translations in `l10n/`. `x-notes` is not rendered and not counted by `check:schema-l10n`, which is where engineering rationale belongs.

## Nextcloud Integration
- Controllers, services, mappers, events: none.

## Decisions

### D1: Rewrite, do not delete, the rationale
The current descriptions carry real design decisions (why `learnerIds` holds no plan link, why `approvedBy` is stamped server-side). Moving each to `x-notes` keeps it for engineers while the form shows a sentence a teacher can act on.

### D2: Scan product surfaces, not working documents
The test scans what users read or what defines the product: code, manifests, templates, docs, canonical specs and the English and Dutch catalogues. `openspec/changes/` is left out because a proposal may need to discuss the research. Patterns: English `learn(ing)?` followed by `style(s)` with optional space, dash or underscore, camelCase `learningStyle`, and Dutch `leerstijl(en)`. Other catalogues translate English source strings, so a hit there always has an English twin the scan already catches.

### D3: One note, where the request would come from
A request to "detect" styles would arrive as an AI feature, so the note sits in the AI features section of Settings. It names the NRO Kennisrotonde publication, which is Dutch and written for teachers.

### Declarative-vs-imperative decision
| Behaviour | Path | Rationale |
|---|---|---|
| Form copy | Declarative, register titles and descriptions | Rendered from the schema. |
| Wording guard | Test | A build-time check, not runtime behaviour. |

## Security Considerations
No security impact: text only.

## NL Design System
The settings note is an `NcNoteCard` of type `info`; no colours.

## File Structure
```
lib/Settings/learniq_register.json          titles, descriptions, x-notes, versions
src/views/LearniqSettings.vue               one NcNoteCard
l10n/en.json, l10n/nl.json (+ .js)          new strings
tests/Unit/Settings/SupportNeedsVocabularyTest.php   new
```

## Seed Data
No new schema and no new seed rows: the change edits text on existing schemas only.

## Trade-offs
The new descriptions are new catalogue keys; the old keys stay in the catalogue until a cleanup, which is harmless.
