/**
 * LTI 1.3 login initiation form builder.
 *
 * Integriq answers an LTI launch with `{formActionUrl, method, fields}`: a form
 * aimed at the tool's OIDC login URL. The lesson player submits it in the
 * learner's browser, in a new tab for a resource link and in the lesson frame
 * for deep linking. Learniq reads none of the fields; it copies each one into
 * a hidden input.
 *
 * Plain ES module so it runs under `node --test` without an SFC compile step.
 *
 * @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Whether a launch response carries a login initiation form.
 *
 * @param {object} body The launch response.
 * @return {boolean} True when `formActionUrl` and a `fields` object are present.
 * @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
 */
export function isLaunchForm(body) {
	return Boolean(
		body
		&& typeof body.formActionUrl === 'string'
		&& body.formActionUrl !== ''
		&& body.fields
		&& typeof body.fields === 'object'
		&& !Array.isArray(body.fields),
	)
}

/**
 * Build the hidden form that starts the launch.
 *
 * @param {Document} doc The document to create elements in.
 * @param {{formActionUrl: string, method?: string, fields: object, launchMode?: string}} launch The launch response.
 * @param {string} frameName The name of the lesson frame, the target for deep linking.
 * @return {HTMLFormElement} The form, not yet attached or submitted.
 * @spec openspec/changes/content-lti-launch-through-integriq/specs/course-management/spec.md#requirement-lessonplayer-delegates-the-lti-launch-to-integriq-through-a-typed-event
 */
export function buildLtiLaunchForm(doc, launch, frameName) {
	const form = doc.createElement('form')
	form.method = (launch.method || 'POST').toUpperCase() === 'GET' ? 'GET' : 'POST'
	form.action = launch.formActionUrl
	form.target = launch.launchMode === 'deep-linking' ? frameName : '_blank'
	form.style.display = 'none'

	for (const [name, value] of Object.entries(launch.fields)) {
		const input = doc.createElement('input')
		input.type = 'hidden'
		input.name = name
		input.value = String(value ?? '')
		form.appendChild(input)
	}

	return form
}
