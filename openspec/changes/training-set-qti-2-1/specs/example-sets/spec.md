# Example Sets Specification

## ADDED Requirements

### Requirement: The training set writes its items as QTI 2.1
Every item in the training example set MUST carry QTI 2.1 markup in the `imsqti_v2p1` namespace, in the form the app's item editor writes: an `assessmentItem` with a `responseDeclaration`, and a `choiceInteraction` of `simpleChoice` options. No item MUST carry QTI 3.0 markup. The app's own choice reader MUST find every option of every item, with the correct answer among them.

#### Scenario: Reading a training item
- **GIVEN** the training set's item "BHV kennistoets, vraag 1"
- **WHEN** the app's choice reader reads its `qtiBody`
- **THEN** it finds three options, one of which is the item's correct response
