# ai-translated-catalogue Specification

## Purpose
A human translator can see which Dutch interface strings an AI wrote and has not been checked, and mark each one checked (decision D24).

## Requirements

### Requirement: The sidecar lists the unreviewed AI-written catalogue keys

`l10n/ai-translated.json` SHALL be a JSON object with `language` (a locale code matching a catalogue in `l10n/`) and `keys` (an array of strings). The keys SHALL be unique, sorted by code point, and each SHALL exist in the catalogue of that language. An optional `$comment` string MAY explain the file. No other top-level member SHALL appear. The file is a schema.org `DataFeed` of `DefinedTerm` identifiers.

#### Scenario: The seeded sidecar has the right shape

- GIVEN the repository
- WHEN `node --test tests/unit-js/aiTranslatedCatalogue.test.mjs` runs
- THEN it MUST pass: `language` is `nl`, `keys` is sorted, unique and non-empty, and every key is in `l10n/nl.json`

#### Scenario: A renamed catalogue key fails the test

- GIVEN a key listed in the sidecar that no longer exists in `l10n/nl.json`
- WHEN the test runs
- THEN it MUST fail and name the key

### Requirement: The seed is every key the round 1 and round 2 lanes wrote

The sidecar SHALL be seeded with every key that appears on an added line of `git log origin/development --since=2026-09-25 -p -- l10n/nl.json` and whose Dutch value is new or changed against the catalogue before 2026-09-25, plus every Dutch key this change adds.

#### Scenario: A round 2 string is listed

- GIVEN "Everyone has handed in." was added to `l10n/nl.json` by dcde983c on 2026-09-27
- WHEN the sidecar is read
- THEN it MUST list "Everyone has handed in."
- AND it MUST NOT list "Status message", whose Dutch value predates 2026-09-25

### Requirement: The admin settings list the keys with source and Dutch value

`GET /api/l10n/ai-translated` SHALL return, for an administrator, `language`, `total` and `items`, each item holding `key`, `source` (the English catalogue value, or the key when the English catalogue has none) and `value` (the Dutch value; plural values as arrays). A non-administrator SHALL be refused by Nextcloud's admin middleware. The learniq admin settings SHALL show the list with a text filter and 50 rows per page.

#### Scenario: An administrator sees a row

- GIVEN the sidecar lists "Publish marks"
- WHEN an administrator opens the learniq admin settings
- THEN the row MUST show the English source and the Dutch value

#### Scenario: A plural value shows both forms

- GIVEN a listed plural key
- WHEN the list is read
- THEN `value` MUST be an array of the Dutch forms

### Requirement: Marking a key reviewed removes it from the sidecar

`POST /api/l10n/ai-translated/reviewed` with `key` SHALL remove that key from the sidecar and rewrite it atomically, keeping every other member. A key that is not listed SHALL answer 404 and change nothing. When the file cannot be written, the answer SHALL be 409 with a reason naming the read-only app directory, and nothing SHALL change.

#### Scenario: A reviewed key leaves the list

- GIVEN "Publish marks" is listed
- WHEN an administrator marks it reviewed
- THEN the sidecar MUST no longer list it
- AND every other key MUST still be listed, in order

#### Scenario: An unknown key is refused

- WHEN an administrator marks a key that is not listed
- THEN the answer MUST be 404 and the file MUST be unchanged

#### Scenario: A read-only app directory is refused, not worked around

- GIVEN the sidecar cannot be written
- WHEN an administrator marks a key reviewed
- THEN the answer MUST be 409 with the reason `read-only`

### Requirement: The l10n build ignores the sidecar

`scripts/build-l10n-js.js` SHALL generate from and check only files whose name is a locale code (`xx.json`, `xxx.json` or `xx_YY.json`), so `npm run check:l10n-js` passes with the sidecar present.

#### Scenario: check:l10n-js passes with the sidecar

- GIVEN `l10n/ai-translated.json` exists
- WHEN `npm run check:l10n-js` runs
- THEN it MUST exit 0 and MUST NOT write `l10n/ai-translated.js`
