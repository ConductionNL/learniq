<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 LearniqLearnerHome — "My learning" dashboard for learners.
 Shows the current user's mandatory training obligations and their progress
 through each programme (mandatory courses counted, optional ones apart).

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-18
 @spec openspec/changes/enrolment-programme-mandatory-per-person/specs/programme-mandatory-parts/spec.md#requirement-progress-counts-mandatory-parts
-->
<template>
	<CnDashboardPage
		:title="t('learniq', 'My learning')"
		:widgets="widgets"
		:layout="layout">
		<template #widget-my-mandatory-training>
			<MyMandatoryTrainingWidget />
		</template>
		<template #widget-my-programmes>
			<MyProgrammeProgressWidget />
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import MyMandatoryTrainingWidget from './widgets/MyMandatoryTrainingWidget.vue'
import MyProgrammeProgressWidget from './widgets/MyProgrammeProgressWidget.vue'

export default {
	name: 'LearniqLearnerHome',

	components: {
		CnDashboardPage,
		MyMandatoryTrainingWidget,
		MyProgrammeProgressWidget,
	},

	data() {
		return {
			widgets: [
				{
					id: 'my-mandatory-training',
					title: 'My mandatory training',
					type: 'custom',
				},
				{
					id: 'my-programmes',
					title: this.t('learniq', 'My programmes'),
					type: 'custom',
				},
			],

			layout: [
				{
					id: 1,
					widgetId: 'my-mandatory-training',
					gridX: 0,
					gridY: 0,
					gridWidth: 6,
					gridHeight: 5,
				},
				{
					id: 2,
					widgetId: 'my-programmes',
					gridX: 6,
					gridY: 0,
					gridWidth: 6,
					gridHeight: 5,
				},
			],
		}
	},
}
</script>
