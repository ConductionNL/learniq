# Contract: data-exchange-to-integriq

The interface with integriq is integriq's `openspec/changes/learniq-exchange-jobs-native/contract.md`
(PR ConductionNL/integriq#2220). Learniq consumes it as follows and serves two routes of its own.

## Consumers

- `integriq`: dispatches `ExchangeGateRequestedEvent` (learniq answers) and
  `ExchangeJobConcludedEvent` (learniq listens); receives `ExchangeJobRequestedEvent` and
  `ExchangeMappingRequestedEvent` from learniq.
- learniq's own export request screen: `POST /api/exchange/requests`.
- People and support tooling: `GET /api/exchange-gates/{jobId}`.

## Endpoints

### `GET /apps/learniq/api/exchange-gates/{jobId}`
**Auth**: Nextcloud session; admin, administration managers, compliance officers or coordinators.

**Response (200):**
```json
{"jobId": "00000000-0000-0000-0000-000000000000", "decision": "refuse", "code": "teldatum-unconfirmed", "reason": "The teldatum count for 2026-10-01 is not confirmed yet.", "checkedAt": "2026-10-01T09:00:00+02:00"}
```

**Errors:**
| Code | Condition |
|------|-----------|
| 401 | No session |
| 403 | Not one of the staff groups |
| 404 | No integriq job with that id owned by learniq, or integriq absent |

### `POST /apps/learniq/api/exchange/requests`
**Auth**: Nextcloud session; admin or administration managers (the export request screen's audience).

**Request:**
```json
{"target": "uwlr", "direction": "export", "schema": "learner-profile", "cohortId": null, "period": null, "mappingSlug": "learniq-uwlr-export-pupil"}
```

**Response (201):** `{"jobId": "00000000-0000-0000-0000-000000000000"}`

**Errors:**
| Code | Condition |
|------|-----------|
| 400 | `target` or `direction` missing |
| 401 | No session |
| 403 | Not admin or administration manager |
| 409 | Integriq refused the request (`{code, reason}` in the body) |
| 503 | Integriq is not installed or too old |

## Error Codes

The gate's refusal codes: `flag-not-in-handling`, `parent-review-pending`,
`parent-review-rejected`, `partner-approval-missing`, `teldatum-unconfirmed`,
`disclosure-undefined`, `statutory-incomplete`, `records-unavailable` (the records could not be
read) and `gate-error` (the gate itself failed; the listener answers a refusal instead of throwing
into integriq's runner). Integriq adds its own fail-closed codes when learniq does not answer.

## Versioning

The gate codes and the two routes are additive; renaming a code is a breaking change for anyone
reading the HTTP answer.

## Breaking Change Policy

As integriq's contract: a new name next to the old one for one release.

## SLA

The gate answers in the listener's time, one OpenRegister read per condition plus the record
read, bounded to 500 records.
