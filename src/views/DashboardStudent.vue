<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- My learning dashboard route wrapper — renders the shared LearniqDashboards
     in its student view. Everyone with a Learniq role can see it (the resolved
     view set always includes 'student'), so a learner, teacher or admin all
     reach their own mandatory-training dashboard.
     @visual exclude Thin pass-through wrapper (renders LearniqDashboards role="student"); the visual surface is LearniqDashboards, which carries the baseline. -->
<template>
	<LearniqDashboards role="student" />
</template>

<script>
import LearniqDashboards from './LearniqDashboards.vue'

export default {
	name: 'DashboardStudent',
	components: { LearniqDashboards },
}
</script>
