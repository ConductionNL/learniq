import { getRequestToken } from '@nextcloud/auth'
// SPDX-License-Identifier: EUPL-1.2
import { defineStore } from 'pinia'

/**
 * The URL of one list read: register, schema, then the query params.
 *
 * @param {string} baseUrl The OpenRegister objects URL.
 * @param {{register: string, schema: string, params: object}} query What to read.
 * @return {string} The URL.
 */
function objectListUrl(baseUrl, query) {
	const url = new URL(baseUrl, window.location.origin)
	url.searchParams.set('register', query.register)
	url.searchParams.set('schema', query.schema)
	Object.entries(query.params).forEach(([k, v]) =>
		url.searchParams.set(k, v),
	)
	return url.toString()
}

/**
 * Read one registered type and keep the rows in the store; a refused read
 * keeps what the store had and returns none.
 *
 * @param {object} store The object store.
 * @param {{type: string, params: object}} read The type and query params.
 * @return {Promise<Array<object>>} The rows.
 */
async function readObjectList(store, read) {
	const query = { ...store.objectTypes[read.type], params: read.params }
	const response = await fetch(objectListUrl(store.baseUrl, query), {
		headers: { requesttoken: getRequestToken() },
	})
	if (!response.ok) {
		return []
	}
	const data = await response.json()
	store.objects[read.type] = data.results || data
	return store.objects[read.type]
}

/**
 * Generic OpenRegister object store.
 * Configure it with baseUrl and schemaBaseUrl, then register object types.
 */
export const useObjectStore = defineStore('object', {
	state: () => ({
		baseUrl: '',
		schemaBaseUrl: '',
		objectTypes: {},
		objects: {},
		loading: {},
	}),

	actions: {
		/**
		 * Configure the OpenRegister object + schema base URLs.
		 *
		 * @param {object} opts Config { baseUrl, schemaBaseUrl }
		 * @param opts.baseUrl
		 * @param opts.schemaBaseUrl
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
		 */
		configure({ baseUrl, schemaBaseUrl }) {
			this.baseUrl = baseUrl
			this.schemaBaseUrl = schemaBaseUrl
		},

		/**
		 * Register a named object type with its schema + register slugs.
		 *
		 * @param {string} type     Logical type name
		 * @param {string} schema   OR schema slug
		 * @param {string} register OR register slug
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
		 */
		registerObjectType(type, schema, register) {
			this.objectTypes[type] = { schema, register }
			if (!this.objects[type]) {
				this.objects[type] = []
			}
		},

		/**
		 * Fetch objects of a registered type from OpenRegister.
		 *
		 * @param {string} type   Registered object type
		 * @param {object} params Optional query params
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/retrofit-2026-05-25-app-shell-settings/tasks.md#task-4
		 */
		async fetchObjects(type, params = {}) {
			if (!this.objectTypes[type]) {
				console.warn(`Object type "${type}" is not registered`)
				return []
			}

			this.loading[type] = true
			try {
				return await readObjectList(this, { type, params })
			} catch (error) {
				console.error(`Failed to fetch ${type} objects:`, error)
				return []
			} finally {
				this.loading[type] = false
			}
		},
	},
})
