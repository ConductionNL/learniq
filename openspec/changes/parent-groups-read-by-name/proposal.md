# Proposal: the guardian reads the name of the child's group

## Why

Seen on the parent portal of the primary-school instance in the review of 2026-10-04: the group column of the guardian's `parentGroupMemberships` collection was empty. The column showed `cohortId`, and portaliq leaves a uuid out of a cell, so nothing was left to show.

## What changes

- The column shows `cohortName`, the readable copy of the group's name every enrolment already carries. ReadableCopyStamp writes it on every save, CohortNameCascade keeps it current when a group is renamed, and BackfillReadableCopies wrote it on stored enrolments (site-guardian-portal-design).
- The collection's fields gain `cohortName`. `cohortId` stays: portaliq's news audience (`guardianAudience.groups`) matches on it.

## Why a copy on the row and not a lookup

A portaliq `lookups` entry (portaliq#1084) would need a second collection the guardian can read, over `cohort`, scoped to the groups of their own children. The copy on the enrolment needs nothing new: the guardian already reads only their own children's enrolments, so they read the names of their own children's groups and nothing beyond. No new collection, no new read path.

## Not changed

- No register change: `enrolment.cohortName` exists. The register version stays.
- The manifest dump of the student, praktijkopleider and external-assessor audiences is identical before and after; the parent dump differs only in this collection's fields and column.
