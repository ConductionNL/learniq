# Contract: report-card-templates

## Consumers
- `filinq` (`filinq-configurable-report-templates`, sibling repo change, not built by this
  change): receives `templateSlug` on the existing, already-proposed docudesk render payload and
  uses it to select the school's configured PDF layout.

## Endpoints

This change does not add a new HTTP endpoint. It changes one field's value on an existing,
already-proposed (not-yet-built, per `ReportCardPdfDelegationService`'s own docblock) outbound
call.

### `POST /apps/filinq/api/v1/documents/render` (proposed, not-yet-verified; unchanged path)
**Auth**: Bearer token, `learniq.docudesk_api_token` app-config value (unchanged).

**Request (changed field highlighted):**
```json
{
  "reportCardId": "00000000-0000-0000-0000-000000000000",
  "subjectGrades": [],
  "mentorComment": null,
  "attendanceSummary": null,
  "templateSlug": "report-card"
}
```
`templateSlug` today is always the literal `"report-card"` (`ReportCardPdfDelegationService::
TEMPLATE_SLUG`). After this change, `templateSlug` is the `ReportCardTemplate.slug` of the
`ReportCardTemplate` assigned to the report card's cohort for its period, falling back to the
literal `"report-card"` when no template is assigned (unchanged default).

**Response (200, unchanged):**
```json
{
  "documentRef": "opaque-docudesk-reference"
}
```

**Errors:** unchanged — this call already fails soft (see `report-card` spec's "docudesk PDF
rendering is fail-soft" requirement); no new error path is introduced.

## Error Codes
Unchanged from the existing `report-card` spec's docudesk delegation requirement.

## Versioning
No version bump — `templateSlug` was already part of the proposed payload shape; this change only
makes its value template-driven instead of constant.

## Breaking Change Policy
Not breaking: a report card with no assigned template keeps sending `"report-card"`, so any
docudesk-side implementation built against today's fixed value keeps working unchanged.

## SLA
Unchanged from the existing docudesk delegation call (60s client timeout, fail-soft — see
`ReportCardPdfDelegationService`).
