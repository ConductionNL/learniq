// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The reads and writes the board dialogs share: one OpenRegister object, a
 * new object, a lifecycle transition, and the signal that tells the page
 * underneath to read again.
 *
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */

import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { normaliseCallerTenant } from '../utils/callerTenant.js'
import { objectsUrl, oneObject, transitionUrl } from '../utils/customPages.js'

/**
 * Read one object of a learniq schema.
 *
 * @param {string} schema The schema slug.
 * @param {string} id The object's uuid.
 * @return {Promise<object>} The object.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export async function readObject(schema, id) {
	const { data } = await axios.get(generateUrl(objectsUrl(schema, id)))
	return oneObject(data)
}

/**
 * Read the objects of a learniq schema that match `params`.
 *
 * @param {string} schema The schema slug.
 * @param {object} params Query parameters (filters, `_limit`).
 * @return {Promise<object[]>} The objects.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export async function listObjects(schema, params = {}) {
	const { data } = await axios.get(generateUrl(objectsUrl(schema)), { params })
	return Array.isArray(data?.results) ? data.results : Array.isArray(data) ? data : []
}

/**
 * Create one object of a learniq schema.
 *
 * @param {string} schema The schema slug.
 * @param {object} body The object's properties.
 * @return {Promise<object>} The created object.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export async function createObject(schema, body) {
	const { data } = await axios.post(generateUrl(objectsUrl(schema)), body)
	return oneObject(data)
}

/**
 * Save changed properties of one object of a learniq schema.
 *
 * @param {string} schema The schema slug.
 * @param {string} id The object's uuid.
 * @param {object} body The properties to save.
 * @return {Promise<object>} The saved object.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export async function updateObject(schema, id, body) {
	const { data } = await axios.patch(generateUrl(objectsUrl(schema, id)), body)
	return oneObject(data)
}

/**
 * Run one lifecycle transition on an object.
 *
 * @param {string} id The object's uuid.
 * @param {string} action The transition name.
 * @param {object} [data] The transition's inputs.
 * @return {Promise<object>} The server's answer.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export async function runTransition(id, action, data = {}) {
	const response = await axios.post(generateUrl(transitionUrl(id)), { action, data })
	return response.data
}

/**
 * The signed-in user's id, or ''.
 *
 * @return {string} The Nextcloud user id.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function currentUserId() {
	return getCurrentUser()?.uid ?? ''
}

/**
 * The caller's tenant, which every learniq row carries, or null.
 *
 * @return {string|null} The tenant id.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function callerTenant() {
	return normaliseCallerTenant(loadState('learniq', 'callerTenant', null))
}

/**
 * Tell the page underneath that a dialog saved, and say what it saved on
 * its own channel.
 *
 * @param {string} event The dialog's own event-bus channel.
 * @param {object} payload What it saved.
 * @spec openspec/specs/nextcloud-app/spec.md#requirement-every-custom-page-renders-a-registered-component
 */
export function announceSaved(event, payload) {
	emit('cn:page:refresh', {})
	emit(event, payload)
}
