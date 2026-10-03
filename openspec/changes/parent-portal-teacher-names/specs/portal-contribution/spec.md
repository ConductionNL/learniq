## ADDED Requirements

### Requirement: A guardian reads a teacher by name

Every parent column that holds a staff Nextcloud user id MUST declare `render: user` and MUST name a projected field, so portaliq answers the teacher's display name and the user id never leaves the server. A parent collection MUST NOT project a staff user id field without such a column.

#### Scenario: Fatima reads her conversation time with the teacher's name
- GIVEN a conversation time for Vera with po-leerkracht-09
- WHEN Fatima opens her conversation times
- THEN the "Met" column reads the teacher's display name, not "po-leerkracht-09"
- @e2e exclude the swap is portaliq's (`ContributionControllerUserNamesTest`); learniq's declaration is pinned by `ParentTeacherNamesTest`, and the live check is in the PR

### Requirement: The parent section is called School

The parent contribution MUST carry the label "School" (Dutch "School"), not the app's name.

#### Scenario: The site heads the parent sections with School
- GIVEN the Wilgenboom site
- WHEN Fatima opens her overview
- THEN the group heading reads "School"
- @e2e exclude pinned by `ParentTeacherNamesTest::testTheParentSectionIsCalledSchool`
