# Assessment: QTI version label delta

## REMOVED Requirements

### Requirement: Items use QTI 3.0 as canonical form

**Reason**: It was never true. No conversion to QTI 3.0 exists: every writer (item editor, Moodle mapper, importer) stores QTI 2.1 markup, and every reader parses QTI 2.1 element names only. The QTI 3.0 namespace on that markup was a wrong label.

**Migration**: Replaced by "Items are stored as QTI 2.1 and labelled as QTI 2.1". Stored items keep their XML; the exporter relabels the namespace.

### Requirement: ItemBank exports its items as a QTI 3.0 package

**Reason**: The package declared QTI 3.0 for QTI 2.1 content, which no QTI 3.0 tool can read.

**Migration**: Replaced by "ItemBank exports its items as a QTI 2.1 package", which keeps the verbatim-wrap and export-fidelity rules.

## ADDED Requirements

### Requirement: Items are stored as QTI 2.1 and labelled as QTI 2.1

`Item.qtiBody` MUST hold QTI 2.1 item XML: the element names every reader in the app parses (`assessmentItem`, `responseDeclaration`, `choiceInteraction`, `simpleChoice`, `extendedTextInteraction`). Every writer MUST label that XML with the QTI 2.1 namespace `http://www.imsglobal.org/xsd/imsqti_v2p1`: the item editor, the Moodle quiz mapper, and the exporter. Descriptions in the register, the import dialog and the export package MUST name QTI 2.1 wherever they describe what is stored, and MUST NOT claim QTI 3.0. Importing QTI 2.x and Common Cartridge packages stays required; the importer stores the item XML as found.

<!-- Previous behaviour: the requirement said items are canonically QTI 3.0 and converted on import. No
     conversion existed: every writer produced QTI 2.1 markup under the QTI 3.0 namespace, and every reader
     parses 2.1 element names only. Reading real QTI 3.0 markup (qti-assessment-item, qti-simple-choice) is
     out of scope for grading-defects-from-example-sets. -->

#### Scenario: The editor writes and labels QTI 2.1

- **GIVEN** an item author saves a choice item in the item editor
- **WHEN** the Item is stored
- **THEN** its `qtiBody` root is `assessmentItem` in the namespace `http://www.imsglobal.org/xsd/imsqti_v2p1`
- **AND** its options are `simpleChoice` elements

#### Scenario: A Moodle quiz question becomes a QTI 2.1 item

- **GIVEN** a Moodle multiple-choice question in a course package
- **WHEN** the question is mapped to an Item
- **THEN** its `qtiBody` carries the QTI 2.1 namespace and `simpleChoice` options

### Requirement: ItemBank exports its items as a QTI 2.1 package

The system MUST support exporting an `ItemBank` and its `Item`s as a QTI 2.1 package: a ZIP containing an `imsmanifest.xml` that declares QTI 2.1 (`imsqti_v2p1` namespace, resource type `imsqti_item_xmlv2p1`) and one `assessmentItem` XML per `Item`. The exporter MUST wrap the stored `qtiBody` rather than re-deriving it from `interactionType`/`correctResponse`, so export fidelity is not limited by the import-side interaction-type parsing gap. The one rewrite allowed is the label: an item stored with the old hybrid label (QTI 2.1 elements under the QTI 3.0 namespace `http://www.imsglobal.org/xsd/imsqtiasi_v3p0`) MUST be exported with the QTI 2.1 namespace, and nothing else in its XML may change. The export MUST be usable independently of course-package export and MUST be the same code path `course-management`'s course export calls for embedded assessment items.

#### Scenario: Exporting an ItemBank produces a QTI 2.1 package

- **GIVEN** an `ItemBank` containing `Item`s of mixed `interactionType`
- **WHEN** an authorised user exports the `ItemBank`
- **THEN** the ZIP's `imsmanifest.xml` declares QTI 2.1 and references one `assessmentItem` XML per `Item`
- **AND** each item XML is that item's stored `qtiBody`

#### Scenario: An item stored under the old label is exported with the QTI 2.1 namespace

- **GIVEN** an Item whose `qtiBody` is `assessmentItem` markup in the namespace `http://www.imsglobal.org/xsd/imsqtiasi_v3p0`
- **WHEN** its ItemBank is exported
- **THEN** the exported item XML carries `http://www.imsglobal.org/xsd/imsqti_v2p1`
- **AND** the rest of the XML equals the stored `qtiBody`

#### Scenario: Export fidelity is not limited by the import-side parsing gap

<!-- @e2e exclude Verifies a data-fidelity property (stored qtiBody is exported byte-for-byte regardless of
     interactionType) via PHPUnit comparing the exported XML to the stored qtiBody; no DOM surface for XML
     byte-equality. -->

- **GIVEN** an `Item` whose `interactionType` was imported with the "raw qtiBody preserved, correctResponse pending a future parser extension" degradation
- **WHEN** that `Item`'s `ItemBank` is exported
- **THEN** the exported `assessmentItem` XML matches the stored `qtiBody` exactly
