# Tasks: parent-portal-teacher-names

- [x] **T1**: teacher columns declare `render: user`, their fields are projected, and no user id is projected without one
  - PHPUnit `ParentTeacherNamesTest::testTeacherColumnsRenderAsNames`, `::testNoUserIdLeavesWithoutANameColumn`
- [x] **T2**: the parent section is called "School"
  - PHPUnit `ParentTeacherNamesTest::testTheParentSectionIsCalledSchool`
