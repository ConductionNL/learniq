# Tasks: a group teacher's dashboard lists only their own groups

- [x] 1.1 `src/utils/teacherScope.js` works out a group teacher's cohorts and courses and each widget's filter. Verify: `node --test tests/unit-js/teacherScope.test.mjs` (red before: the module did not exist; green after).
- [x] 1.2 `ManageListWidget` sends a list filter as `key[]=`, asks nothing for an empty list, and waits while `pending`. Verify: `teacherScope.test.mjs` (a list filter is sent the way OpenRegister reads an IN filter; a teacher without a group gets lists that match nothing).
- [x] 1.3 `LearniqDashboards` loads the scope for a primary-role `instructor` and passes the filters; school-wide roles get none. Verify: `teacherScope.test.mjs` (only a group teacher is scoped).
- [x] 1.4 The teacher widgets show declared fields: cohort name, period and status; session and assignment `title`. Verify: `teacherScope.test.mjs` (every column of the teacher widgets is a field the schema has), red before on `Assignment.name`.
- [ ] 1.5 Live: as `po-leerkracht-09`, `po-ib-01` and `po-directeur-01`, open the teacher dashboard and check what each list shows.
