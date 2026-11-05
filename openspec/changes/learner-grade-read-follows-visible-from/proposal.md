---
kind: code
---

# A learner reads a grade once it is released

## Why

A grade is released to the learner when it is published (or revised) and its `visibleFrom` has passed. The portal already applies that rule (`PortalResultReader`). The learner's read on `GradeEntry` in the register is the same rule, so the objects API, the list query and every page built on them (such as /my-grades) show the learner exactly what the portal shows.

## What changes

- `GradeEntry.authorization.read`: the learner's own entry is readable when its lifecycle is `published` or `revised` and its `visibleFrom` is at or before now, or not set. Staff read access is unchanged.
- Register version 0.47.1.
