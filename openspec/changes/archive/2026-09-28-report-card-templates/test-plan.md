# Test Plan: report-card-templates

| Spec scenario | Test case | File |
|---|---|---|
| A template with a scale from every library value validates | `test_template_persists_all_seven_section_kinds_with_scales` | `tests/Unit/Settings/ReportCardTemplateRegisterTest.php` (new) |
| A template with no sections fails schema validation on save | `test_sections_minItems_one_rejects_empty_sections` | `tests/Unit/Settings/ReportCardTemplateRegisterTest.php` (new) |
| A test-kind mapping is declared without a corresponding data source present | `test_testKindSectionMap_persists_without_lvs_data` | `tests/Unit/Settings/ReportCardTemplateRegisterTest.php` (new) |
| A cohort's assigned template determines its report cards' sections | `test_compose_stamps_templateId_and_limits_sections_from_cohort_assignment` | `tests/Unit/Listener/ReportCardComposerTest.php` (modified) |
| Composing a period creates one ReportCard per cohort learner (regression) | `test_compose_creates_one_report_card_per_learner` | `tests/Unit/Listener/ReportCardComposerTest.php` (existing, must stay green) |
| A subject with no matching period component contributes no row (regression) | `test_compose_skips_subject_with_no_matching_period_component` | `tests/Unit/Listener/ReportCardComposerTest.php` (existing, must stay green) |
| An untemplated cohort composes exactly as before this change | `test_compose_without_template_falls_back_to_fixed_shape` | `tests/Unit/Listener/ReportCardComposerTest.php` (modified) |
| A templated cohort composes only the sections its template declares | `test_compose_with_template_limits_populated_sections` | `tests/Unit/Listener/ReportCardComposerTest.php` (modified) |
| A PDF render failure does not block publication (regression) | `test_render_failure_is_fail_soft` | `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php` (existing, must stay green) |
| A successful render records the docudesk document reference (regression) | `test_successful_render_records_document_ref` | `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php` (existing, must stay green) |
| A report card with an assigned template sends that template's slug to docudesk | `test_render_sends_assigned_template_slug` | `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php` (modified) |
| A report card with no assigned template keeps sending the default slug | `test_render_sends_default_slug_without_template` | `tests/Unit/Service/ReportCardPdfDelegationServiceTest.php` (modified) |

## Non-spec regression coverage
- `php -l` on every touched PHP file.
- `python3 -m json.tool lib/Settings/learniq_register.json` and
  `lib/Settings/learniq_mock_register.json` (well-formed JSON after edits).
- `npm run check:manifest` (manifest schema validity after adding
  `ReportCardTemplateIndex`/`ReportCardTemplateDetail`).
