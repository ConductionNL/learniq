<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 ManageListWidget — a parameterised list widget for manage/admin dashboards.
 Fetches the top-N objects from OR for a given schema and renders them through
 the universal <CnDataTable> (headerless, borderless) as a compact
 name + trailing-status list, with a "+ New" footer link to the index page and
 row-click navigation to the item's detail page.

 Props:
   schema     — OR schema slug (e.g. "Course", "Cohort", "Programme")
   schemaLabel — human-readable label for the "+ New" link
   columns    — array of field names to display per item (first becomes item title)
   indexRoute — router path for the index page ("+ New" link + row-click base)
   limit      — max items to show (default 5)
   filter     — optional extra filter params (e.g. { lifecycle: 'published' }).
                A list value is sent as key[]=a&key[]=b (an IN filter); an
                empty list matches nothing, so the widget shows an empty list
                without asking the server.
   pending    — true while the caller is still working out the filter; the
                widget shows its loading state and waits.
-->
<template>
	<CnDataTable
		:rows="rows"
		:columns="cnColumns"
		:loading="loading"
		hideHeader
		borderless
		fillHeight
		rowKey="id"
		:emptyText="t('learniq', 'No items found')"
		:rowClickRoute="rowClickRoute">
		<template #footer>
			<a
				class="cn-data-table__view-all"
				role="button"
				tabindex="0"
				@click.prevent="navigate"
				@keydown.enter.prevent="navigate"
				@keydown.space.prevent="navigate">
				<template v-if="footerLabel">
					{{ footerLabel }}
				</template>
				<template v-else>
					+ {{ t('learniq', 'New') }} {{ schemaLabel }}
				</template>
			</a>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { appendFilter, filterMatchesNothing } from '../../utils/teacherScope.js'

export default {
	name: 'ManageListWidget',

	components: {
		CnDataTable,
	},

	props: {
		/** OR schema slug */
		schema: {
			type: String,
			required: true,
		},

		/** Human-readable schema label for the "+ New" button */
		schemaLabel: {
			type: String,
			default: '',
		},

		/** Fields to display per item; first field is used as the item title */
		columns: {
			type: Array,
			default: () => ['name', 'lifecycle'],
		},

		/** Router path for the index/new page */
		indexRoute: {
			type: String,
			required: true,
		},

		/** Maximum number of items to show */
		limit: {
			type: Number,
			default: 5,
		},

		/** Additional OR filter params; a list value is an IN filter */
		filter: {
			type: Object,
			default: () => ({}),
		},

		/** True while the caller is still working out the filter */
		pending: {
			type: Boolean,
			default: false,
		},

		/**
		 * OR relation fields to resolve server-side (passed as `_extend`), e.g.
		 *  ['learnerId', 'courseId'] so a resolver can read the related object.
		 */
		extend: {
			type: Array,
			default: () => [],
		},

		/**
		 * Optional (item) => string used to compute the first column's display
		 *  label, for schemas without a plain `name` field (e.g. a learner-profile
		 *  shown as "givenName familyName", an enrolment as "learner → course").
		 *  Falls back to the raw field value when not provided.
		 */
		nameResolver: {
			type: Function,
			default: null,
		},

		/**
		 * Optional (row) => vue-router location for a row click; defaults to
		 *  `{indexRoute}/{id}`. The teacher dashboard's "Sessions to mark"
		 *  opens the roll-call of the lesson's group and day.
		 */
		rowRoute: {
			type: Function,
			default: null,
		},

		/** Optional footer link text, replacing "+ New {schemaLabel}". */
		footerLabel: {
			type: String,
			default: '',
		},

		/** Optional footer link target, replacing indexRoute. */
		footerRoute: {
			type: [String, Object],
			default: null,
		},
	},

	data() {
		return {
			items: [],
			loading: true,
		}
	},

	computed: {
		/**
		 * Rows for CnDataTable — the fetched OR objects, each guaranteed a stable
		 * `id` (the row key) resolved from the object's id/uuid variants.
		 *
		 * @return {object[]}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		rows() {
			const nameKey = (this.columns.length ? this.columns : ['name'])[0]
			return this.items.map((item) => {
				const row = {
					...item,
					id: item.id || item._id || item.uuid || item['@self']?.id,
				}
				// Resolve a human-readable label for schemas without a plain `name`
				// field, so the first column never falls back to the raw UUID.
				if (this.nameResolver) {
					row[nameKey] = this.nameResolver(item)
				}
				return row
			})
		},

		/**
		 * Column definitions for CnDataTable — headerless name + trailing status.
		 * The first column renders bold (the item title); the last renders muted
		 * and right-aligned; any columns in between render muted.
		 *
		 * @return {Array<{key: string, cellClass: string}>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		cnColumns() {
			const cols = this.columns.length ? this.columns : ['name']
			return cols.map((key, i) => ({
				key,
				// Name column stays regular weight (matches the reference design);
				// only the trailing status/value column is muted + right-aligned.
				cellClass:
					i === 0
						? ''
						: i === cols.length - 1
							? 'cn-cell--muted cn-cell--end'
							: 'cn-cell--muted',
			}))
		},
	},

	watch: {
		/**
		 * Fetch once the caller has worked out the filter.
		 *
		 * @return {void}
		 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
		 */
		pending() {
			this.fetchItems()
		},

		filter: {
			deep: true,
			/**
			 * Fetch again when the filter changes.
			 *
			 * @return {void}
			 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
			 */
			handler() {
				this.fetchItems()
			},
		},
	},

	created() {
		this.fetchItems()
	},

	methods: {
		/**
		 * Fetch the top-N objects of this schema from OpenRegister. Waits while
		 * the filter is pending, and asks nothing when it can match no row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 * @spec openspec/changes/teacher-dashboard-own-groups/specs/dashboard/spec.md#requirement-the-teacher-dashboard-of-a-group-teacher-lists-only-their-own-groups
		 */
		async fetchItems() {
			this.loading = true
			if (this.pending) {
				return
			}
			if (filterMatchesNothing(this.filter)) {
				this.items = []
				this.loading = false
				return
			}
			try {
				const params = appendFilter(
					new URLSearchParams({ _limit: String(this.limit) }),
					this.filter,
				)
				if (this.extend.length) {
					params.set('_extend', this.extend.join(','))
				}
				const url = generateUrl(
					'/apps/openregister/api/objects/learniq/'
						+ this.schema
						+ '?'
						+ params.toString(),
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				this.items = data.results ?? (Array.isArray(data) ? data : [])
			} catch {
				this.items = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Map a clicked row to its detail route (`{indexRoute}/{id}`).
		 *
		 * @param {object} row The clicked OR object row.
		 * @return {object} A vue-router location.
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		rowClickRoute(row) {
			if (this.rowRoute) {
				return this.rowRoute(row)
			}
			return { path: `${this.indexRoute}/${row.id}` }
		},

		/**
		 * Navigate to the configured index/new route.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-29
		 */
		navigate() {
			this.$router.push(this.footerRoute || this.indexRoute).catch(() => {})
		},
	},
}
</script>
