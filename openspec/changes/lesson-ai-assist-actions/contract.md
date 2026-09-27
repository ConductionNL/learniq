# Contract: lesson-ai-assist-actions

This change is a **consumer**. The producer contract is hermiq's
`openspec/changes/lesson-authoring-ai-delegate/contract.md` (hermiq PR 962, branch
`feat/lesson-authoring-ai-delegate`). This file records which parts of it learniq reads, what learniq
sends, and how learniq treats every answer, so a change on either side can be checked against it.
Learniq exposes no endpoint of its own.

## Consumers
- `learniq` (`src/utils/lessonAssist.js`, called from `LessonAssistPanel.vue` in `LessonComposer`): all four endpoints below.

## Endpoints

All four: `POST generateUrl('/apps/hermiq/api/lesson-authoring/<action>')` with `@nextcloud/axios`, which
sends the Nextcloud session and the `requesttoken` header. JSON body, JSON answer.

**Auth**: Nextcloud session (`#[NoAdminRequired]` on hermiq's side). CSRF token required.

### `POST /apps/hermiq/api/lesson-authoring/outline`

**Request (what learniq sends, nothing else):**
```json
{
  "goalTitles": ["De leerling kan breuken vergelijken en ordenen"],
  "lessonText": "Optional: the lesson's current rich text, joined.",
  "language": "nl"
}
```
`goalTitles`: the titles of the goals the teacher picked, plus the goal typed in the free field; 1 to 100,
each cut to 300 characters. `lessonText` is left out when the lesson has no rich text yet.

**Response (200) read by learniq:** `available`, `draft`, `provider`, `draftText`.

### `POST /apps/hermiq/api/lesson-authoring/questions`

**Request:**
```json
{
  "lessonText": "The lesson's rich text, joined, at most 20,000 characters.",
  "goalTitles": ["De leerling kan breuken vergelijken en ordenen"],
  "questionCount": 5,
  "language": "nl"
}
```
`goalTitles` is left out when the teacher picked no goal.

**Response (200) read by learniq:** `available`, `draft`, `provider`, `questions`.

### `POST /apps/hermiq/api/lesson-authoring/simplify`

**Request:**
```json
{
  "lessonText": "The text of one rich text block.",
  "readingLevel": "A2"
}
```

**Response (200) read by learniq:** `available`, `draft`, `provider`, `draftText`.

### `POST /apps/hermiq/api/lesson-authoring/goal-suggestions`

**Request:**
```json
{
  "lessonText": "The lesson's rich text, joined.",
  "goalTitles": ["goal title 0", "goal title 1", "goal title 2"]
}
```
The goal titles are the course and lesson goals, in a fixed order that learniq keeps next to their ids.

**Response (200) read by learniq:** `available`, `draft`, `provider`, `suggestedGoals[].index`. Learniq maps
each `index` back to the goal id at that position and ignores an index outside the list. `title` is not
trusted for the mapping.

**Errors (all endpoints):**
| Code | Condition |
|------|-----------|
| 400  | A field broke its limits. Learniq builds bodies inside the limits, so this means a contract drift. |
| 401  | No session. |
| 404  | Hermiq is enabled but has no lesson-authoring route (an older hermiq). |

## Error Codes

How learniq classifies every answer (`classifyOutcome` in `src/utils/lessonAssist.js`):

| Answer | Outcome | What the teacher sees |
|---|---|---|
| 200, `available: true` with the action's field | `ok` | A draft block, or goal suggestions |
| 200, `available: false`, `reason: feature-not-enabled` | `off` | The panel collapses to a note that AI help is switched off |
| 404 | `off` | Same note: the delegate does not exist on this hermiq |
| 200, `available: false`, `reason: provider-error` | `retry` | "The AI model gave no usable answer. Try again later." |
| 200, `available: true` without the action's field | `retry` | Same message |
| 429 | `busy` | "Too many requests. Wait a minute and try again." |
| 400, 401, 412, 500, network error | `failed` | "The AI help could not run. Try again later." |

`off` hides the actions for the rest of the browser session. `retry`, `busy` and `failed` keep them.

## Versioning
Hermiq's endpoints are unversioned. Learniq reads only the fields named above and ignores every other
response field, so hermiq may add fields without a learniq change.

## Breaking Change Policy
A rename or removal of `draftText`, `questions`, `suggestedGoals[].index`, `available`, `reason` or
`provider` breaks learniq. Hermiq's contract requires such a change to say so in its PR title and to land with
a matching learniq change in the same release. Until then learniq classifies the unexpected answer as `retry`
and leaves the lesson untouched.

## SLA
None formal. A local model can take tens of seconds, so the panel shows a busy state per action and never
blocks the rest of the composer.
