---
kind: code
depends_on: [data-exchange-to-integriq, import-landing-answer]
---

# Proposal: import-records-in-gate-answer

## Summary

Learniq's exchange gate answers every import job with `allow([])`, so integriq has nothing to map and hand back, and the import landing listener (import-landing-answer) only ever sees zero records. For `lvs-results`, `oso` and `migration-import` imports the gate now reads the file the job names and puts its rows in the answer, as integriq's contract says (`openspec/changes/exchange-import-landing/design.md` on integriq: "The raw records reach integriq the same way export records do: in the gate answer").

## Motivation

Follow-up from lane f-quality (learniq#1220, "Gap: gate hands allow([]) for imports, so no raw records yet") and TRACKER-R2 "ROUND 3 FINAL" (import landing for lvs-results, OSO import, migration-import).

## How a job names its input

Integriq's contract keeps `scope` to selectors and `ownerRef` opaque, and names no input field. Learniq decides: an import job names its input as `scope.fileId`, the Nextcloud file id of the uploaded file or received dossier. A file id is a selector, not personal data. The file is looked up in the files of the person who asked for the job (the integriq job's `requestedBy`), so a job can never read a file its requester cannot open.

## Scope

### In Scope

- `ExchangeImportInput` reads the file and returns its rows as `{recordId, sourceKind, data}`: CSV (header row, `;`, `,` or tab), JSON (a list, `{records: [...]}` or one object) and XML (one record per file, the whole document). `recordId` is `<fileId>:<n>`, so a second delivery of the same file hands the same ids.
- Bounded: at most 10 MB and 5,000 rows; a larger file refuses the job.
- Refusal codes: `import-input-missing` (no `fileId`), `import-input-unreadable` (no requester, or the requester cannot open the file), `import-input-too-large`, `import-input-unparseable`.
- Never logged: the gate logs the job id and the refusal code only, never a row, a file name or file content.
- Other import targets (`timetable-import`, `hr`) keep `allow([])`.

### Out of Scope

- A screen that uploads a file and asks for the import job.
- Catalogue labels for the new refusal codes.

## Risks

### Risk 1: A job reads a file it should not
**Severity:** Medium. **Mitigation:** the file is resolved in the requester's own folder only; a system job without a person is refused.

### Risk 2: A huge file stalls the job run
**Severity:** Low. **Mitigation:** the size is checked before the content is read; rows are capped.

## Rollback Strategy

Revert the merge commit; import jobs go back to `allow([])`.
