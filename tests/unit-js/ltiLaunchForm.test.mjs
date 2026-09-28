// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// content-lti-launch-through-integriq: the lesson player submits integriq's
// login initiation form with every field, the given method, and the target
// that matches the placement's launch mode.
//
// Run via `node --test tests/unit-js/` (package.json `test:js-unit`).

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildLtiLaunchForm, isLaunchForm } from '../../src/utils/ltiLaunchForm.js'

/**
 * A minimal document: elements are plain objects that record their children.
 *
 * @return {object} The fake document.
 */
function fakeDocument() {
	return {
		createElement(tag) {
			return {
				tag,
				style: {},
				children: [],
				appendChild(child) {
					this.children.push(child)
				},
			}
		},
	}
}

const launch = {
	formActionUrl: 'https://tool.example/oidc/login',
	method: 'post',
	fields: {
		iss: 'https://school.example',
		login_hint: 'abc',
		lti_message_hint: 'xyz',
		target_link_uri: 'https://tool.example/launch',
	},
	launchMode: 'resource-link',
}

test('every field becomes a hidden input', () => {
	const form = buildLtiLaunchForm(fakeDocument(), launch, 'lesson-frame')
	assert.equal(form.action, 'https://tool.example/oidc/login')
	assert.equal(form.method, 'POST')
	assert.deepEqual(
		form.children.map((input) => [input.type, input.name, input.value]),
		Object.entries(launch.fields).map(([name, value]) => [
			'hidden',
			name,
			value,
		]),
	)
})

test('a resource link opens in a new tab, deep linking in the lesson frame', () => {
	assert.equal(
		buildLtiLaunchForm(fakeDocument(), launch, 'lesson-frame').target,
		'_blank',
	)
	assert.equal(
		buildLtiLaunchForm(
			fakeDocument(),
			{ ...launch, launchMode: 'deep-linking' },
			'lesson-frame',
		).target,
		'lesson-frame',
	)
})

test('GET is honoured and anything else posts', () => {
	assert.equal(
		buildLtiLaunchForm(fakeDocument(), { ...launch, method: 'get' }, 'f').method,
		'GET',
	)
	assert.equal(
		buildLtiLaunchForm(fakeDocument(), { ...launch, method: undefined }, 'f')
			.method,
		'POST',
	)
})

test('only a response with a form action and a fields object is a launch form', () => {
	assert.equal(isLaunchForm(launch), true)
	assert.equal(
		isLaunchForm({
			formActionUrl: 'https://tool.example',
			idToken: 'old-shape',
		}),
		false,
	)
	assert.equal(isLaunchForm({ fields: {} }), false)
	assert.equal(isLaunchForm(null), false)
})
