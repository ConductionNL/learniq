---
sidebar_position: 1
title: Open Learniq for the first time
description: Open Learniq, find your way around the navigation, and confirm the OpenRegister back end is connected.
---

# Open Learniq for the first time

A first look at Learniq, where the app lives, what the navigation gives you, and how to tell it is wired up to OpenRegister.

## Goal

By the end you will have opened the Learniq app, recognised the dashboard tiles and the left-hand navigation, and confirmed that the OpenRegister-backed lists (Courses, Learners, Enrolments, …) load.

## Prerequisites

- A Nextcloud account on an instance where the **Learniq** app is installed and enabled.
- The **OpenRegister** app installed and enabled, Learniq stores every course, enrolment, grade, credential and attendance record in OpenRegister, so it is a hard dependency.
- The Learniq register and its schemas imported. An admin runs this once from **Settings → Registers → Re-import configuration** (see [Manage Learniq settings](../admin/03-admin-settings.md)).

## Steps

1. Open the Nextcloud app menu in the top bar and pick **Learniq**. You land on the dashboard.

   ![Learniq dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. Read the dashboard tiles, *Courses*, *Cohorts*, *Learners*, *Active enrolments*, *Open attendance flags*. On a fresh install they read `No items found`; they fill in as work moves through the app.

   ![Dashboard stat tiles](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. The entries map one-to-one onto the things Learniq tracks: **Courses**, **Enrolments**, **Learners**, **Credentials**, **Curriculum**, **Grades**, **Assignments**, **Assessments**, **Learning plans**, **Attendance**, **Data exchange**. Below sit **Documentation**, **Assistant**, **xAPI statements**, **AI features**, **Settings** and **Features & roadmap**.

   ![Learniq navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Courses**. The list view opens with a *Cards / Table* toggle, an **Add Item** button, and the OpenRegister side filters. An empty install shows *No items found*, expected until someone creates the first course.

   ![Courses list, empty state](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the Learniq dashboard renders without an error banner, the left navigation lists the entries above, and clicking through to **Courses** (or any other list) shows either rows or a clean *No items found* state, not a load error.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload Learniq. |
| Lists load but **Add Item** opens a modal with no form fields | The Learniq register import is incomplete, an admin re-runs **Settings → Registers → Re-import configuration**. |
| Learniq is missing from the app menu | The app is not enabled for your account, ask an administrator to enable it (and check it is not restricted to a group you are not in). |

## Reference

- [Manage Learniq settings](../admin/03-admin-settings.md), register import, OpenRegister wiring, signing keys.
- [School structure & cohorts](../admin/01-school-structure.md), the admin set-up the user tutorials assume is in place.
