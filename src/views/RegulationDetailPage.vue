<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 RegulationDetailPage — custom page component for the RegulationDetail
 manifest page (type: custom).

 The route is business-slug-based (`/compliance/regulations/:slug`, e.g.
 `/compliance/regulations/AVG`) so compliance officers can share readable
 links. OpenRegister's generic single-object lookup (used by the library's
 `type:"detail"` dispatch) only matches an object's numeric id, UUID,
 `@self.slug` or `@self.uri` — NOT an arbitrary schema property like
 Regulation's own `slug` field ("AVG"). Routing straight through the
 library's generic detail-page resolver therefore left `objectData` null
 for a business-slug link (crash: `TypeError: Cannot read properties of
 null (reading 'id')` inside CnRelatedObjectsWidget's `resolvedId` /
 `useTabs`, which read `this.objectData.id` unguarded) and, separately,
 the resolver never even looked at the `:slug` route param at all (it only
 reads `:id` / `config.idParam`), so `@self.slug`-style links
 ("reg-avg") were never picked up either.

 This component resolves the object itself before rendering anything:
 1. Try OpenRegister's native id/uuid/`@self.slug`/`@self.uri` match
    (covers "reg-avg"-style links).
 2. Fall back to a collection lookup filtered by the schema's own `slug`
    property (covers "AVG"-style business-key links).
 3. Loading / not-found states are rendered explicitly — CnDetailPage
    (and its widget grid) is only ever mounted once a real object has
    been resolved, so the null-deref this page used to crash with can no
    longer happen.

 Once resolved, it mounts the SAME `CnDetailPage` component the generic
 `type:"detail"` dispatch would have used, forwarding the widgets/layout/
 sidebar/lifecycleActions declared on this page's manifest `config` (via
 props, exactly as `CnPageRenderer` would) and publishing the
 `cnDetailObjectContext` the widget grid needs for `@object.<field>`
 filter-token resolution (e.g. the KPI/related-collection widgets' `"@object.slug"`
 filters).

 @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
-->

<template>
	<div class="regulation-detail">
		<NcLoadingIcon
			v-if="loading"
			:size="44"
			class="regulation-detail__loading" />

		<NcEmptyContent
			v-else-if="notFound"
			:name="t('learniq', 'Regulation not found')"
			:description="
				t(
					'learniq',
					'No regulation matches this link. It may have been removed or the link is out of date.',
				)
			">
			<template #icon>
				<span class="icon-error" />
			</template>
		</NcEmptyContent>

		<CnDetailPage
			v-else
			:title="t('learniq', 'Regulation')"
			:widgets="widgets"
			:layout="layout"
			:sidebar="sidebar"
			:lifecycleActions="lifecycleActions"
			:objectType="schema"
			:objectId="objectId"
			:register="register"
			:schema="schema" />
	</div>
</template>

<script>
import { CnDetailPage, useObjectStore } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'

const REGISTER = 'learniq'
const SCHEMA = 'Regulation'
const OBJECT_TYPE = `${REGISTER}-${SCHEMA}`

/**
 * The schema lookup that runs alongside the object lookup; a store without
 * one, or a failed one, gives no schema.
 *
 * @param {object} store The object store.
 * @return {Promise<object|null>} The schema.
 * @spec openspec/specs/compliance/spec.md
 */
function schemaLookup(store) {
	return typeof store.fetchSchema === 'function'
		? store.fetchSchema(OBJECT_TYPE).catch(() => null)
		: Promise.resolve(null)
}

/**
 * The Regulation OpenRegister's native id/uuid/@self.slug/@self.uri matcher
 * finds for a slug — the links built from the OR-generated slug ("reg-avg").
 *
 * @param {object} store The object store.
 * @param {string} param The route slug.
 * @return {Promise<object|null>} The Regulation.
 * @spec openspec/specs/compliance/spec.md
 */
async function fetchByIdentity(store, param) {
	if (typeof store.fetchObject !== 'function') {
		return null
	}
	return store.fetchObject(OBJECT_TYPE, param).catch(() => null)
}

/**
 * The Regulation whose own `slug` business key is the slug. OpenRegister's
 * generic matcher does not know about arbitrary schema properties, so a
 * business-key link ("AVG") needs an explicit filtered lookup.
 *
 * @param {object} store The object store.
 * @param {string} param The route slug.
 * @return {Promise<object|null>} The Regulation.
 * @spec openspec/specs/compliance/spec.md
 */
async function fetchBySlug(store, param) {
	const results = await store
		.fetchCollection(OBJECT_TYPE, { slug: param, _limit: 1 })
		.catch(() => [])
	return Array.isArray(results) && results.length > 0 ? results[0] : null
}

/**
 * The Regulation a slug names: the native matcher first, the slug property
 * second.
 *
 * @param {object} store The object store.
 * @param {string} param The route slug.
 * @return {Promise<object|null>} The Regulation, or null.
 * @spec openspec/specs/compliance/spec.md
 */
async function findRegulation(store, param) {
	const object = await fetchByIdentity(store, param)
	if (object || typeof store.fetchCollection !== 'function') {
		return object
	}
	return fetchBySlug(store, param)
}

/**
 * An object's id as a string, '' when it has none.
 *
 * @param {object} object The object.
 * @return {string} The id.
 * @spec openspec/specs/compliance/spec.md
 */
function objectIdOf(object) {
	const realId = object.id ?? (object['@self'] && object['@self'].id)
	return realId !== undefined && realId !== null ? String(realId) : ''
}

/**
 * The schema the store already holds, or null.
 *
 * @param {object} store The object store.
 * @return {object|null} The schema.
 * @spec openspec/specs/compliance/spec.md
 */
function storedSchema(store) {
	return (
		(typeof store.getSchema === 'function' && store.getSchema(OBJECT_TYPE))
		|| null
	)
}

/**
 * The uuid an object's push events are keyed on (or-object-{uuid}): the
 * uuid over a numeric id when both are present.
 *
 * @param {object} object The object.
 * @param {string} fallback The id to use without a uuid.
 * @return {string} The uuid.
 * @spec openspec/specs/compliance/spec.md
 */
function pushUuidOf(object, fallback) {
	return (object['@self'] && object['@self'].uuid) ?? object.uuid ?? fallback
}

export default {
	name: 'RegulationDetailPage',

	components: {
		CnDetailPage,
		NcEmptyContent,
		NcLoadingIcon,
	},

	/**
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	provide() {
		return {
			cnDetailObjectContext: this.detailObjectContext,
		}
	},

	// CnPageRenderer forwards every `config.*` key (register, schema, title, …)
	// as an individual prop — this component only declares the ones it
	// actually consumes (widgets/layout/sidebar/lifecycleActions) and takes
	// register/schema as fixed constants, so the rest must not fall through
	// onto the root <div> as raw DOM attributes.
	inheritAttrs: false,

	props: {
		/** `config.widgets` forwarded by CnPageRenderer (grid widget defs). */
		widgets: {
			type: Array,
			default: () => [],
		},

		/** `config.layout` forwarded by CnPageRenderer (12-col grid layout). */
		layout: {
			type: Array,
			default: () => [],
		},

		/** `config.sidebar` forwarded by CnPageRenderer (tabs incl. audit trail). */
		sidebar: {
			type: [Boolean, Object],
			default: () => ({ enabled: false }),
		},

		/** `config.lifecycleActions` forwarded by CnPageRenderer. */
		lifecycleActions: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			notFound: false,
			/** Real OpenRegister object id once resolved (never the route slug). */
			objectId: '',
			register: REGISTER,
			schema: SCHEMA,
			/**
			 * Published to descendants as `cnDetailObjectContext` (same shape
			 * CnPageRenderer publishes for `type:"detail"` pages), so the
			 * widget grid's `@object.<field>` filter tokens resolve.
			 */
			detailObjectContext: { value: null },
			// Live-updates handle for the or-object-{uuid} subscription of
			// the currently resolved regulation (nc-vue beta.212,
			// liveUpdatesPlugin default-on). Managed by
			// syncLiveSubscription(); liveKey is `${type}::${uuid}` so a
			// re-resolve of the same object is a no-op. livePendingKey marks
			// an in-flight subscribe so a concurrent same-key call doesn't
			// double-subscribe; liveEpoch invalidates in-flight resolutions
			// after a release (slug change / destroy).
			liveHandle: null,
			liveKey: '',
			livePendingKey: '',
			liveEpoch: 0,
			liveUnwatch: null,
		}
	},

	watch: {
		'$route.params.slug': {
			immediate: true,
			/**
			 * @spec openspec/specs/realtime-updates/spec.md
			 */
			handler() {
				this.resolve()
			},
		},
	},

	/**
	 * Lifecycle hook: release the live object subscription on unmount.
	 *
	 * @return {void}
	 * @spec openspec/specs/realtime-updates/spec.md
	 */
	beforeUnmount() {
		this.releaseLiveSubscription()
	},

	methods: {
		/**
		 * Subscribe to live updates for the resolved regulation:
		 * or-object-{uuid} via notify_push with visibility-gated polling
		 * fallback. Events are refetch hints only — the liveUpdatesPlugin
		 * re-runs fetchObject(type, uuid), which lands in the library
		 * store's objects[type][uuid] cache; the watcher installed here
		 * bridges that fresh data into `detailObjectContext.value.objectData`
		 * so the provided context (and the widget grid reading it)
		 * re-renders. Idempotent per (type, uuid); releases the previous
		 * subscription when another regulation is resolved.
		 *
		 * @param {object} store The library object store instance.
		 * @param {object} object The resolved regulation object.
		 * @return {Promise<void>}
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		async syncLiveSubscription(store, object) {
			if (typeof store.subscribe !== 'function') {
				return
			}
			const uuid = pushUuidOf(object, this.objectId)
			if (!uuid) {
				this.releaseLiveSubscription()
				return
			}
			const key = `${OBJECT_TYPE}::${uuid}`
			if (this.isLiveOn(key)) {
				return
			}
			this.releaseLiveSubscription()
			await this.subscribeLive(store, { uuid, key })
		},

		/**
		 * Whether this object is already subscribed, or its subscribe is in
		 * flight — re-subscribing then would leak the first handle + watcher.
		 *
		 * @param {string} key The subscription key.
		 * @return {boolean} True when nothing needs doing.
		 * @spec openspec/specs/compliance/spec.md
		 */
		isLiveOn(key) {
			return Boolean(this.liveHandle && this.liveKey === key) || this.livePendingKey === key
		},

		/**
		 * Clear the in-flight mark when it is still this subscription's.
		 *
		 * @param {string} key The subscription key.
		 * @return {void}
		 * @spec openspec/specs/compliance/spec.md
		 */
		clearPendingKey(key) {
			if (this.livePendingKey === key) {
				this.livePendingKey = ''
			}
		},

		/**
		 * Subscribe to the object's push events and bridge them to the page.
		 *
		 * @param {object} store The object store.
		 * @param {{uuid: string, key: string}} target The object and its subscription key.
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance/spec.md
		 */
		async subscribeLive(store, target) {
			try {
				const epoch = this.liveEpoch
				this.livePendingKey = target.key
				this.liveKey = target.key
				const handle = await store.subscribe(OBJECT_TYPE, target.uuid)
				this.clearPendingKey(target.key)
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (another slug resolved, or the
					// component was destroyed) — drop the stale subscription.
					store.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
				this.liveUnwatch = this.watchLiveObject(store, target)
			} catch (e) {
				this.clearPendingKey(target.key)
				this.liveHandle = null
				this.liveKey = ''
				// eslint-disable-next-line no-console
				console.warn(
					'[RegulationDetailPage] live subscription failed:',
					e?.message ?? e,
				)
			}
		},

		/**
		 * Bridge: event → plugin refetch → objects[type][uuid] cache →
		 * detailObjectContext (which descendants render from).
		 *
		 * @param {object} store The object store.
		 * @param {{uuid: string, key: string}} target The object and its subscription key.
		 * @return {() => void} The unwatch.
		 * @spec openspec/specs/compliance/spec.md
		 */
		watchLiveObject(store, target) {
			return this.$watch(
				() =>
					typeof store.getObject === 'function'
						? store.getObject(OBJECT_TYPE, target.uuid)
						: null,
				(fresh) => this.applyLiveObject(fresh, target.key),
			)
		},

		/**
		 * Show a refetched object, if it is still the one on the page.
		 *
		 * @param {object|null} fresh The refetched object.
		 * @param {string} key The subscription key it came for.
		 * @return {void}
		 * @spec openspec/specs/compliance/spec.md
		 */
		applyLiveObject(fresh, key) {
			if (fresh && this.liveKey === key && this.detailObjectContext.value) {
				this.detailObjectContext.value = {
					...this.detailObjectContext.value,
					objectData: fresh,
				}
			}
		},

		/**
		 * Release the current live object subscription and its cache
		 * watcher, and invalidate any in-flight subscribe (its resolution
		 * unsubscribes itself via the epoch check).
		 *
		 * @return {void}
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePendingKey = ''
			if (this.liveUnwatch) {
				this.liveUnwatch()
				this.liveUnwatch = null
			}
			if (this.liveHandle) {
				const store = useObjectStore()
				if (typeof store.unsubscribe === 'function') {
					store.unsubscribe(this.liveHandle)
				}
			}
			this.liveHandle = null
			this.liveKey = ''
		},

		/**
		 * Resolve the Regulation behind the current `:slug` route param and
		 * publish it as the detail-object context, or show the not-found
		 * state when nothing matches.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-24-annotate-scholiq/tasks.md#task-12
		 */
		async resolve() {
			const param = this.$route.params.slug

			this.loading = true
			this.notFound = false
			this.objectId = ''
			this.detailObjectContext.value = null

			if (!param) {
				this.showNotFound()
				return
			}

			const store = useObjectStore()
			if (typeof store.registerObjectType === 'function') {
				store.registerObjectType(OBJECT_TYPE, SCHEMA, REGISTER)
			}
			// Schema fetch runs alongside the object lookup — CnObjectDataWidget
			// (the "data" grid widget) needs it for field labels/order, same as
			// CnPageRenderer's loadDetailObject does for a `type:"detail"` page.
			const schemaPromise = schemaLookup(store)
			const object = await findRegulation(store, param)
			if (!object) {
				this.showNotFound()
				return
			}

			this.showRegulation(store, { object, schema: await schemaPromise })
			this.loading = false

			// Live updates for the resolved object (refetch hints only).
			this.syncLiveSubscription(store, object)
		},

		/**
		 * Show that the slug names no regulation, and stop listening.
		 *
		 * @return {void}
		 * @spec openspec/specs/compliance/spec.md
		 */
		showNotFound() {
			this.loading = false
			this.notFound = true
			this.releaseLiveSubscription()
		},

		/**
		 * Show the resolved regulation with its schema.
		 *
		 * @param {object} store The object store.
		 * @param {{object: object, schema: (object|null)}} found The regulation and its fetched schema.
		 * @return {void}
		 * @spec openspec/specs/compliance/spec.md
		 */
		showRegulation(store, found) {
			this.objectId = objectIdOf(found.object)
			this.detailObjectContext.value = {
				objectData: found.object,
				schema: found.schema || storedSchema(store),
				objectType: OBJECT_TYPE,
				objectId: this.objectId,
				register: REGISTER,
				store,
			}
		},
	},
}
</script>

<style scoped lang="scss">
.regulation-detail {
	display: flex;
	flex-direction: column;
	min-height: 200px;

	&__loading {
		margin: var(--default-grid-baseline, 8px) auto;
	}
}
</style>
