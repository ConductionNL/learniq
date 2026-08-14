<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!-- Dashboard manage-cohorts widget: top-N cohorts with learner count. -->
<template>
	<ManageListWidget
		schema="Cohort"
		:schemaLabel="t('scholiq', 'cohort')"
		:columns="['name', 'learnerCount']"
		indexRoute="/cohorts"
		:limit="6" />
</template>

<script>
import ManageListWidget from './ManageListWidget.vue'

export default {
	name: 'ManageCohortsWidget',
	components: { ManageListWidget },
}
</script>
