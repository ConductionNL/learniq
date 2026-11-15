---
kind: code
depends_on: [office-file-lesson-onboarding]
---

# Proposal: docx-through-documentextractor

## Summary

Learniq reads a confirmed Word file into a lesson draft with its own `DocxLessonReader`. OpenRegister now has a shared structured Word reader, `DocumentExtractor` (openregister #4111). Learniq uses it when it is present and falls back to its own reader otherwise, as it already does for PowerPoint.

## Motivation

TRACKER-R2 "ROUND 3 FINAL": "learniq DocxLessonReader to DocumentExtractor". `DocumentExtractor`'s own docblock names this consumer: "A consumer can turn one document into one lesson draft with a block per heading section (docx-structured-reader)".

## Affected Projects

- [ ] Project: `learniq`: new `DocumentLessonReader`, `DocumentBlockText`, `DocumentImageLoader`; `OfficeLessonExtractor`.

## Scope

### In Scope

- `DocumentLessonReader`, duck-typed on `OCA\OpenRegister\Service\TextExtraction\DocumentExtractor`: sections keep their heading; paragraph, list and table blocks become paragraph text as `DocxLessonReader` writes them; image blocks become pictures, their bytes read from the package with `DocxLessonReader`'s limits (no linked pictures, 10 MB each, 50 per document); a truncated read carries a note.
- `OfficeLessonExtractor` tries it first for docx; when it is missing, fails or reads nothing, `DocxLessonReader` runs as before, then the flat-text fallback.

### Out of Scope

- Removing `DocxLessonReader`: it stays the fallback for an OpenRegister without #4111.

## Approach

Mirror `PresentationLessonReader` (class check, container lookup, `available` flag).

## New Dependencies

None.

## Impact

Word files are read by the fleet's shared reader when it is there; the lesson draft keeps its shape.

## Cross-Project Dependencies

openregister #4111 (merged).

## Risks

### Risk 1: The two readers split a document differently

**Severity:** Low. **Mitigation:** Both start a section at each heading; the draft is reviewed by the teacher before it is used.

## Rollback Strategy

Revert the merge commit.
