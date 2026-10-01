/**
 * One removal step per loaded example set in the setup wizard (D34).
 *
 * The shared CnSetupWizard posts a run-action step's `action` with no body,
 * so one step can remove one fixed thing. This app therefore replaces its
 * single `remove-example-set` step, at page load, with one step per set the
 * server says is loaded (`loadedExampleSets` initial state, from
 * LoadedExampleSets): step and action `remove-example-set-<id>`, each with
 * its own button. With nothing loaded the single step stays, so a set loaded
 * during this visit can still be removed at the end of the wizard.
 *
 * The server reports every `remove-example-set-<id>` step as done
 * (LoadedExampleSets::removalSteps()), so none of them runs by itself. Each
 * step is also `onDemand: true` (nextcloud-vue 2.58.0), so the wizard never
 * auto-runs it and its summary ticks it only when it ran in this session.
 *
 * Plain ES module (not a .vue SFC) so it is directly importable from a Node
 * test runner without a build step.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/** The step (and action) id the per-set steps replace. */
export const REMOVE_STEP_ID = 'remove-example-set'

/**
 * Whether a value is a usable loaded-set entry: an id the action route
 * accepts (`[a-z0-9-]+`) and a label.
 *
 * @param {object|null|undefined} set The candidate.
 * @return {boolean} True when usable.
 */
function isUsableSet(set) {
	return (
		!!set
		&& typeof set.id === 'string'
		&& /^[a-z0-9-]+$/.test(set.id)
		&& typeof set.label === 'string'
	)
}

/**
 * Replace the single removal step with one step per loaded set.
 *
 * @param {object} manifest The manifest; `setup.steps` is replaced, nothing else changes.
 * @param {Array<{id: string, label: string}>|null|undefined} loadedSets The loaded sets.
 * @param {function(string, object=): string} translate The app's translate.
 * @return {object} The manifest.
 * @spec openspec/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
 */
export function applyExampleSetRemovalSteps(manifest, loadedSets, translate) {
	const steps = manifest?.setup?.steps
	const sets = Array.isArray(loadedSets) ? loadedSets.filter(isUsableSet) : []
	if (!Array.isArray(steps) || sets.length === 0) {
		return manifest
	}
	const index = steps.findIndex((step) => step?.id === REMOVE_STEP_ID)
	if (index === -1) {
		return manifest
	}
	const perSet = sets.map((set) => ({
		id: `${REMOVE_STEP_ID}-${set.id}`,
		type: 'run-action',
		action: `${REMOVE_STEP_ID}-${set.id}`,
		required: false,
		onDemand: true,
		title: translate('Remove the example set "{set}"', {
			set: translate(set.label),
		}),
		body: translate(
			'This only runs when you click the button. It moves this example set to the trash and keeps everything you made yourself.',
		),
	}))
	manifest.setup.steps = [
		...steps.slice(0, index),
		...perSet,
		...steps.slice(index + 1),
	]
	return manifest
}
