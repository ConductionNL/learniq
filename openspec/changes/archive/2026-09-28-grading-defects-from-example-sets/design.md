# Design: grading-defects-from-example-sets

## Architecture Overview

Six independent defects, each fixed where the wrong value is produced. No new class, no new endpoint, no new schema. The grading fixes stay inside the existing ADR-031 calculation exception (`GradePassEvaluator`, `GradeFormulaEvaluator`); the other fixes stay inside the listener or writer that already owns the behaviour.

| Defect | Producer | Fix |
|---|---|---|
| Component minimums never applied | `GradePassEvaluator::everyComponentRuleWith()` | read `minValue`; a null `componentId` compares the final value |
| Exemption-only plan gives `passed: null` | `GradePassEvaluator::evaluatePassed()` | `true` when exemptions cover every declared component |
| First match across frameworks | `CompetencyAttainmentRollupHandler::findCompetencyByCode()` | resolve inside the dossier's framework, else only an unambiguous code |
| Undeclared `FinalGrade.cohortId` | `GradeRollupHandler::recomputeFinalGrade()` | stop writing it, drop it from a merged row |
| School-only flag kinds | register + `AttendanceFlagCreationHandler::saveFlag()` | add `attendance-requirement`, derive the kind from the threshold |
| QTI 3.0 label on QTI 2.1 markup | editor, Moodle mapper, exporter, register text | label QTI 2.1; exporter relabels old items |

## Decisions

### D1. `minValue` is the only key read for a pass rule minimum

The register declares `passRules[].minValue` as required, and every example set writes it. No writer in `lib/` or `src/` ever wrote `passThreshold` into a pass rule; that name belongs to `GradeScale`. So the evaluator reads `minValue` with no fallback. Alternative considered: read `minValue ?? passThreshold` for hand-written rows. Rejected: it keeps an undeclared key alive, and OpenRegister would not have stored it through a schema-validated save anyway.

### D2. A rule with `componentId: null` compares the final value

The register describes a null `componentId` as "applies to the final grade". The old code looked it up as component `''`, found nothing when entries carry component ids, and failed the learner. The HE set has `all-must-pass` plans whose only rule is that final-grade rule, and entries without a component, so it passed there only by coincidence. The rule now compares `$value`. When the final value is `null` (exemption-only), the exam board's decision stands in for it, the same way an exempted component satisfies its own rule.

Rules still apply only to `all-must-pass` plans. For other formulas the final-grade minimum is `GradeScale.passThreshold`, which the example sets set to the same 5.5. Widening rules to every formula would change verdicts on plans that never asked for it; that is a separate decision.

### D3. Exemption-only means covered, not merely "no numbers"

`evaluatePassed()` gains an optional `array $components = []` argument, filled by `GradeFormulaEvaluator` from the plan's component index it already builds. When `$value` is `null`, the evaluator returns `true` only if there is at least one entry, every entry is an exemption, and every declared component has one. With no declared components (the HE `all-must-pass` plans), the exemption entries alone decide. A declared component with nothing on it keeps `null`: a learner exempted from one of two units has not completed the course.

`BsaProgressEvaluator` is unchanged: it sums passed final grades, and an exempted course is now a passed final grade. Alternative considered: teach `BsaProgressEvaluator` to count `passed: null` rows whose breakdown is all exempt. Rejected: it would duplicate the grading rule in a second place and leave the FinalGrade itself saying "unknown" to every other reader (report card, portal, learning record).

### D4. The werkproces framework is the one whose `sourceRef` is the dossier code

`WerkprocesAssessment.kwalificatiedossierCode` names the SBB dossier; `CompetencyFramework.sourceRef` is described as the "external dossier code". Resolution:

1. Load the tenant's `sbb-kwalificatiedossier` frameworks (one read, as today).
2. If one or more of them have a `sourceRef` equal to the assessment's dossier code, search only those.
3. Otherwise search all of them.
4. Collect matches across the searched frameworks. One match resolves; zero or several leave `competencyId` null with an info log naming the code and the candidate count.

Several frameworks with the same `sourceRef` (two editions of one dossier) that both carry the code are ambiguous too, and stay null. Alternative considered: follow the placement to its programme and the programme's `requiredCompetencyIds` to a framework. Rejected as the primary path: three extra reads, and `requiredCompetencyIds` is optional and empty in most installs. The dossier code is on the assessment itself.

The MBO example set wrote `sourceRef: "crebo 90201 (voorbeeldcode)"`, a label rather than a code, so step 2 never matched there. Its generator now writes the bare code (`90201`); the framework name keeps "(voorbeeld)", so the set still marks itself as fictional.

### D5. `FinalGrade.cohortId` is dropped, decided from the reader side

No PHP, Vue or manifest reader uses `FinalGrade.cohortId`. The one final grade filter in the manifest (programme detail KPI) uses `programmeId`, and every example set ships `programmeId` without `cohortId`. So the handler stops writing it and unsets it from a merged existing row, instead of declaring a property nobody reads. That the roll-up never writes `programmeId` either is noted as a follow-up: the handler has no programme in hand, and adding a read belongs to its own change.

### D6. The flag kind follows the threshold kind

`AttendanceThreshold.kind` already separates the statutory profile from the rest. Mapping in `AttendanceFlagCreationHandler`:

| Threshold kind | Flag kind |
|---|---|
| `leerplicht-16uur` | `signal-verzuim` |
| `college-aanwezigheid`, `training-attendance`, `compliance-presence` | `attendance-requirement` |
| `generic`, missing | not set, so the schema default `signal-verzuim` applies as before |

`langdurig-relatief-verzuim` and `thuiszitter` are judgments a coordinator makes, not something a threshold crossing can decide, so no threshold kind maps to them. The default stays `signal-verzuim` so existing school installs and the po and vo sets read exactly as before.

### D7. QTI: the label moves to what is written, the markup stays

Every writer produces QTI 2.1 markup and every in-app reader parses QTI 2.1 element names, so the honest label is QTI 2.1:

- `ItemAuthorView.buildQtiBody()` and `MoodleQuizQuestionMapper` write `xmlns="http://www.imsglobal.org/xsd/imsqti_v2p1"`.
- `QtiExportService` declares `imsqti_v2p1`, resource type `imsqti_item_xmlv2p1`, metadata `QTIv2.1 Package`, and relabels an item whose root is `assessmentItem` in the QTI 3.0 namespace. The relabel is a namespace URI swap on the root; the rest of the XML is untouched. The controller names the download `_qti21.zip`.
- Register descriptions on `Item`, `ItemBank` and `Assessment.shuffleAnswerOptions` say QTI 2.1 (patch version bumps). Docblocks that claim a QTI 3.0 canonical form are corrected.
- The importer keeps accepting QTI 2.x packages and old exports labelled 3.0 (their items are 2.1 markup). Its manifest scanner keeps recognising the 3.0 namespace, because our own old packages carry it.

Alternative considered: make every reader accept both dialects (as `PortalItemPresenter` already does) and keep the 3.0 label. Rejected for this change: the brief asks to align the label, and dual reading touches the importer, editor, take view, choice-order resolver and item analysis. It is recorded as a follow-up with the training example set, whose items are real QTI 3.0 markup that only the portal can render.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Pass verdict with component and final-grade minimums | imperative, existing | already the accepted ADR-031 exception in `GradePassEvaluator` (branching over `passRules` against best-entry-per-component) |
| Exemption-only pass | imperative, existing | same class, same exception |
| Werkproces competency resolution | imperative, existing | lookup across two schemas inside an existing listener; no calculation DSL fits |
| Flag kind on creation | imperative, existing | set by the listener that already builds the flag |
| QTI namespace on export | imperative, existing | document generation, an ADR-031 exception |

No new declarative block is added and no behaviour moves between paths.

## Mixed-spec rationale

`kind: code`. The register edits are one enum value and description text (about fifteen lines), and they are meaningless without the listener that writes the new value. Splitting them off would ship an enum value nothing writes.

## Nextcloud Integration

- Controllers: `QtiExportController` (download filename only).
- Services: `GradePassEvaluator`, `GradeFormulaEvaluator`, `QtiExportService`, `MoodleQuizQuestionMapper`.
- Events/Hooks: `CompetencyAttainmentRollupHandler` (`ObjectCreatedEvent`), `GradeRollupHandler` and `AttendanceFlagCreationHandler` (`ObjectTransitionedEvent`). No new listener registration.

## Security Considerations

No new endpoint, no new input. The competency resolution still never accepts `competencyId` from the client; it now refuses to guess, which narrows what an assessment can be linked to. The export relabel runs on stored XML with a fixed string swap, no parsing of user input into new markup.

## File Structure

```
lib/
  Grading/GradePassEvaluator.php            minValue, final-grade rule, exemption-only pass
  Grading/GradeFormulaEvaluator.php         passes the component index
  Listener/CompetencyAttainmentRollupHandler.php
  Listener/GradeRollupHandler.php
  Lifecycle/AttendanceFlagCreationHandler.php
  Service/QtiExportService.php
  Service/MoodleQuizQuestionMapper.php
  Controller/QtiExportController.php
  Service/QtiImportService.php, QtiChoiceOrderResolver.php, ItemAnalysisService.php (docblocks)
  Settings/learniq_register.json
src/views/ItemAuthorView.vue
scripts/example-sets/mbo.py, he.py (+ regenerated lib/Settings/profiles/mbo.json, he.json)
tests/Unit/Grading/GradePassEvaluatorTest.php (new), GradeFormulaEvaluatorTest.php
tests/Unit/Listener/CompetencyAttainmentRollupHandlerTest.php, GradeRollupHandlerTest.php
tests/Unit/Lifecycle/AttendanceFlagCreationHandlerTest.php
tests/Unit/Service/QtiExportServiceTest.php, MoodleQuizQuestionMapperTest.php
```

## Seed Data

No schema is introduced, so no new seed rows. The register gains one enum value on `AttendanceFlag`; gate 101 validates the existing mock rows against it unchanged. Example set rows change where the code now writes a different value:

| Set | Schema | Rows | Change |
|---|---|---|---|
| mbo | `competency-framework` | 3 | `sourceRef` becomes the bare dossier code (`90201`, `90302`, `90403`) |
| mbo | `attendance-flag` | 1 (`mbo-attendance-flag-002`, an adult under 80 percent on a `college-aanwezigheid` threshold) | `flagKind: attendance-requirement` |
| he | `attendance-flag` | 13 (all on the `college-aanwezigheid` threshold) | `flagKind: attendance-requirement` |
| he | `item` | every item | `qtiBody` namespace `imsqti_v2p1` |

Both sets are regenerated from their scripts; the tests' generator check proves the JSON matches.

## Trade-offs

- Refusing an ambiguous werkproces code trades a wrong link for a missing one. A missing link is visible (null, logged) and recoverable by setting `sourceRef`; a wrong link credits the wrong competency silently.
- Labelling QTI 2.1 is less ambitious than reading QTI 3.0, but it makes every claim the app makes about its items true today.
