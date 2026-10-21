<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 DisplayScreenAddressDialog (timetabling-display-screens): the display screen
 page's Address action. A hall screen's secret address is made, shown once,
 renewed or revoked through POST /api/display-screens/{id}/token and
 .../revoke; the server keeps only its hash.

 @spec openspec/specs/personal-timetable/spec.md#requirement-an-administrator-sets-up-a-display-screen
-->
<template>
	<CnSecretAddressCard
		dialog
		:title="t('learniq', 'Screen address')"
		:objectId="screenId"
		createUrl="/apps/learniq/api/display-screens/{objectId}/token"
		revokeUrl="/apps/learniq/api/display-screens/{objectId}/revoke"
		revokeMethod="POST"
		addressPath="address"
		:texts="texts"
		@close="$emit('close')" />
</template>

<script>
import { CnSecretAddressCard } from '@conduction/nextcloud-vue'

export default {
	name: 'DisplayScreenAddressDialog',

	components: { CnSecretAddressCard },

	props: {
		/** The display screen's uuid. */
		screenId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			texts: {
				intro: "Open this address in the browser of the screen. It needs no account and shows today's lessons and changes without names of learners.",
				shownOnce: "This address is shown once. Copy it into the screen's browser now.",
				revoked: 'The address was revoked. The screen shows nothing until you create a new address.',
				createLabel: 'Create address',
				resetLabel: 'Create address',
				revokeLabel: 'Revoke address',
			},
		}
	},
}
</script>
