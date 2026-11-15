<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The `learnerName` list cell widget (lists-read-pupil-names): a pupil by the
 name on their learner profile. Resolves the row's learnerRef when it has one
 and the user id otherwise; the lookup lives in utils/learnerName.js. Declared
 on a manifest column as
   { "key": "learnerId", "label": "Learner", "widget": "learnerName" }
 with `widgetProps.refField` naming another reference field ("learnerRefs"
 on a hand-in). Registered on CnAppRoot through `cellWidgets`.
-->
<template>
	<span class="learniq-learner-name">{{ display }}</span>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	createLearnerNameResolver,
	learnerLookups,
} from '../../utils/learnerName.js'

// One resolver for the page load: every cell of every list shares its cache,
// so a pupil on twenty rows costs one request.
const resolver = createLearnerNameResolver({
	getJson: async (url) => (await axios.get(url)).data,
	urlFor: generateUrl,
})

export default {
	name: 'LearnerNameCell',

	// CnCellRenderer also hands every cell `property` and `formatted`; they
	// are not needed here and must not land on the span as attributes.
	inheritAttrs: false,

	props: {
		/** The cell value: a user id, or an array of them. */
		value: {
			type: [String, Array],
			default: null,
		},

		/** The row the cell belongs to. */
		row: {
			type: Object,
			default: null,
		},

		/** The row field holding the learner profile uuid(s). */
		refField: {
			type: String,
			default: 'learnerRef',
		},
	},

	data() {
		return {
			/** Resolved names, in lookup order; '' while one is resolving. */
			names: [],
		}
	},

	computed: {
		/**
		 * The lookups this cell makes.
		 *
		 * @return {Array<{ref: string, userId: string}>}
		 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
		 */
		lookups() {
			return learnerLookups(this.value, this.row, this.refField)
		},

		/**
		 * The names, comma-joined. Empty while resolving, so the cell never
		 * flashes the user id before the name.
		 *
		 * @return {string}
		 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
		 */
		display() {
			return this.names.filter((n) => n !== '').join(', ')
		},
	},

	watch: {
		lookups: {
			immediate: true,
			handler() {
				this.resolve()
			},
		},
	},

	methods: {
		/**
		 * Resolve every lookup to a name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lists-read-pupil-names/specs/school-structure/spec.md#requirement-every-staff-list-reads-a-pupil-by-name
		 */
		async resolve() {
			const lookups = this.lookups
			const names = await Promise.all(
				lookups.map((lookup) => resolver.nameOf(lookup)),
			)
			if (lookups === this.lookups) {
				this.names = names
			}
		},
	},
}
</script>
