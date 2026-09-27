<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 GlobalSearchWidget — an index-level fast-finder over LearnerProfile, Cohort,
 and (client-classified) Staff, embedded as a full-width widget on
 PeopleDashboard (global-search, finding G-new-1).

 learniq's own usability.md scored "global search scope" 0/3 ("The only
 search box seen anywhere is Nextcloud's own header-level search ... No
 in-app search across cohorts/learners/report cards was found") against
 gibbon's header-level Fast Finder (journeys.md J4: 2 clicks, ~5s to relocate
 a pupil from anywhere). This widget does not replicate gibbon's
 "everywhere in the header" placement (out of scope per this change's
 proposal — see "Out of Scope"); it closes the more specific, still-real
 gap the brief and this change ask for: a search box scoped to the People
 dashboard.

 Query-building and result-classification are pure functions in
 `src/utils/globalSearch.js`, unit-tested directly (no SFC compile step
 needed) — this component only wires them to a debounced text input and
 renders the three result groups via `NcListItem`, whose `to` prop already
 renders a real router-link, so every result is keyboard-reachable by Tab +
 Enter with no bespoke ARIA/roving-tabindex code needed here.

 @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-widget-on-the-people-dashboard
-->
<template>
	<div class="learniq-global-search">
		<NcTextField
			v-model="query"
			:label="t('learniq', 'Search learners, staff and cohorts')"
			:placeholder="t('learniq', 'Search learners, staff and cohorts…')"
			trailingButtonIcon="close"
			:showTrailingButton="Boolean(query)"
			@trailingButtonClick="clear"
			@update:value="onInput" />

		<p v-if="loading" class="learniq-global-search__status">
			{{ t('learniq', 'Searching…') }}
		</p>

		<p
			v-else-if="hasSearched && !hasResults"
			class="learniq-global-search__status">
			{{ t('learniq', 'No matches found.') }}
		</p>

		<div v-else-if="hasResults" class="learniq-global-search__groups">
			<div v-if="results.learners.length" class="learniq-global-search__group">
				<h4>{{ t('learniq', 'Learners') }}</h4>
				<NcListItem
					v-for="row in results.learners"
					:key="'learner-' + row.id"
					:name="row.label"
					:to="row.route" />
			</div>
			<div v-if="results.staff.length" class="learniq-global-search__group">
				<h4>{{ t('learniq', 'Staff') }}</h4>
				<NcListItem
					v-for="row in results.staff"
					:key="'staff-' + row.id"
					:name="row.label"
					:to="row.route" />
			</div>
			<div v-if="results.cohorts.length" class="learniq-global-search__group">
				<h4>{{ t('learniq', 'Cohorts') }}</h4>
				<NcListItem
					v-for="row in results.cohorts"
					:key="'cohort-' + row.id"
					:name="row.label"
					:to="row.route" />
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcListItem, NcTextField } from '@nextcloud/vue'
import {
	buildGlobalSearchRequests,
	groupGlobalSearchResults,
} from '../../utils/globalSearch.js'

/** Debounce delay (ms) before a keystroke fires the search request. */
const DEBOUNCE_MS = 300

export default {
	name: 'GlobalSearchWidget',

	components: {
		NcListItem,
		NcTextField,
	},

	data() {
		return {
			query: '',
			loading: false,
			hasSearched: false,
			results: { learners: [], staff: [], cohorts: [] },
			debounceTimer: null,
			// Guards against an earlier, slower request overwriting a later,
			// faster one's results when responses arrive out of order.
			requestToken: 0,
		}
	},

	computed: {
		/**
		 * @return {boolean} True when the current results have at least one row
		 *  in any group.
		 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-widget-on-the-people-dashboard
		 */
		hasResults() {
			return (
				this.results.learners.length > 0
				|| this.results.staff.length > 0
				|| this.results.cohorts.length > 0
			)
		},
	},

	beforeUnmount() {
		clearTimeout(this.debounceTimer)
	},

	methods: {
		/**
		 * Debounced input handler.
		 *
		 * @return {void}
		 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-widget-on-the-people-dashboard
		 */
		onInput() {
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.runSearch(), DEBOUNCE_MS)
		},

		/**
		 * Clear the query and any shown results.
		 *
		 * @return {void}
		 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-widget-on-the-people-dashboard
		 */
		clear() {
			this.query = ''
			this.hasSearched = false
			this.results = { learners: [], staff: [], cohorts: [] }
		},

		/**
		 * Run the two per-kind OpenRegister searches and group the results.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
		 */
		async runSearch() {
			const requests = buildGlobalSearchRequests(this.query)
			if (!requests.length) {
				this.hasSearched = false
				this.results = { learners: [], staff: [], cohorts: [] }
				return
			}

			const token = ++this.requestToken
			this.loading = true
			try {
				const responses = await Promise.all(
					requests.map((req) => this.fetchOne(req)),
				)
				if (token !== this.requestToken) {
					// A newer keystroke's search has already superseded this one.
					return
				}
				// buildGlobalSearchRequests() always returns [people, cohorts] in
				// that fixed order (see its own tests), so responses line up
				// positionally with no need to search by `kind` here.
				const peopleIndex = requests.findIndex((r) => r.kind === 'people')
				const cohortsIndex = requests.findIndex((r) => r.kind === 'cohorts')
				const peopleResults = peopleIndex >= 0 ? responses[peopleIndex] : []
				const cohortResults =
					cohortsIndex >= 0 ? responses[cohortsIndex] : []
				this.results = groupGlobalSearchResults(peopleResults, cohortResults)
				this.hasSearched = true
			} finally {
				if (token === this.requestToken) {
					this.loading = false
				}
			}
		},

		/**
		 * Fetch one OpenRegister request descriptor's result rows.
		 *
		 * @param {{register: string, schema: string, params: object}} req One
		 *  descriptor from `buildGlobalSearchRequests`.
		 * @return {Promise<object[]>}
		 * @spec openspec/changes/global-search/specs/dashboard/spec.md#requirement-a-fast-finder-query-builder-splits-one-search-term-into-per-kind-openregister-requests
		 */
		async fetchOne(req) {
			try {
				const params = new URLSearchParams(
					Object.fromEntries(
						Object.entries(req.params).map(([k, v]) => [k, String(v)]),
					),
				)
				const url = generateUrl(
					`/apps/openregister/api/objects/${req.register}/${req.schema}?${params.toString()}`,
				)
				const response = await axios.get(url)
				const data = response.data ?? {}
				return data.results ?? (Array.isArray(data) ? data : [])
			} catch {
				return []
			}
		},
	},
}
</script>

<style scoped>
.learniq-global-search {
	padding: 4px 8px;
}

.learniq-global-search__status {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.learniq-global-search__groups {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-top: 8px;
}

.learniq-global-search__group {
	flex: 1 1 200px;
	min-width: 200px;
}

.learniq-global-search__group h4 {
	margin: 0 0 4px;
	font-weight: bold;
}
</style>
