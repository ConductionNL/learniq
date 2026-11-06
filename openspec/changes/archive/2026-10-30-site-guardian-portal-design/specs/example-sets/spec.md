## ADDED Requirements

### Requirement: The primary-school set has a guardian with two children

The po example set MUST give guardian Fatima Hulstkamp a second child, Sami Hulstkamp, enrolled in a groep 3 cohort of De Wilgenboom, beside Vera. Sami MUST share Vera's `guardianRefs`. He MUST have attendance, a summary for the current school year and at least one published grade, so the overview of `site-guardian-portal-design` has something to show for him.

#### Scenario: The child switcher has two children
- GIVEN the po example set loaded
- WHEN guardian Fatima Hulstkamp signs in on the Wilgenboom site
- THEN her overview offers Vera and Sami
- @e2e exclude planned: written with the build in tests/e2e/po-parent-flows.spec.ts (specs-only change)

#### Scenario: The set still loads and removes cleanly
- GIVEN the po set with Sami added
- WHEN the set is checked and loaded
- THEN `python3 scripts/example-sets/po.py --check` passes and every row passes its schema
- @e2e exclude descriptor contract; covered by ExampleSetDescriptorContractTest
