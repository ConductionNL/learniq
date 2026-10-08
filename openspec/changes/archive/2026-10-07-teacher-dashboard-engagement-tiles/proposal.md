# Proposal: the engagement tiles of a group teacher count only their own groups

## Why

`teacher-dashboard-own-groups` scoped the four lists of the teacher dashboard to a group teacher's own groups and left the two tiles at the top out of scope. "Avg. engagement score" and "Open engagement flags" still aggregate every engagement row in the school, so the teacher of Groep 7 sees a number that is mostly about other groups.

## What changes

- For a group teacher (primary role `instructor`) both tiles aggregate only the rows whose `learnerId` is a pupil of one of their cohorts: the `learnerIds` of the cohorts whose `teacherIds` lists them. Engagement rows carry no cohort, so the pupils are the join. The filter goes to OpenRegister's aggregation as `filter[learnerId][in][]=...`.
- The scope is the one the lists already load; the tiles add no request.
- While the scope loads, and for a teacher without pupils, a tile has no source: it asks the server nothing and shows no number. An empty `in` list would be dropped from the query string and count the whole school.
- Coordinators, directors, team leads and admins keep the school-wide tiles.

## Out of scope

- What a teacher may read: `EngagementScore` and `EngagementRiskFlag` still grant `instructors` school-wide. This change scopes the dashboard, not the register.
