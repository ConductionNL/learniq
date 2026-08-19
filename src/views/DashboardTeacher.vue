<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- Teaching dashboard route wrapper — renders the shared LearniqDashboards
     in its teacher view. Nav visibility is gated on the `scholiq-teacher`
     group (runtime `user.canTeachDashboard`); admins see it too.
     @visual exclude Thin pass-through wrapper (renders LearniqDashboards role="teacher"); the visual surface is LearniqDashboards, which carries the baseline. -->
<template>
	<LearniqDashboards role="teacher" />
</template>

<script>
import LearniqDashboards from './LearniqDashboards.vue'

export default {
	name: 'DashboardTeacher',
	components: { LearniqDashboards },
}
</script>
