<?php
/**
 * Learniq LoadedExampleSets.
 *
 * Remembers which example sets the setup wizard loaded, so the wizard can
 * list each one with its own remove button (D34).
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\Learniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The list of loaded example sets, kept in app config.
 *
 * 🔴 APP CONFIG ONLY, NO OPENREGISTER. PageController reads this on the app's
 * default route to build the wizard's removal steps; a dependency on
 * OpenRegister here would make the start screen fail on an instance without
 * it (ADR-083 rule 3). The labels are stored with the ids for the same
 * reason: reading them back from the descriptors would parse megabytes of
 * JSON on every page load.
 *
 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
 */
class LoadedExampleSets {
	/**
	 * App-config key holding the loaded sets as a JSON list of `{id, label}`.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'example_sets_loaded';

	/**
	 * The action id prefix of a per-set removal step: `remove-example-set-<id>`.
	 *
	 * @var string
	 */
	public const REMOVE_ACTION_PREFIX = 'remove-example-set-';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Stores the list.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The loaded sets, in the order they were loaded.
	 *
	 * A malformed value reads as an empty list: a broken config entry must not
	 * take the setup wizard down with it.
	 *
	 * @return array<int, array{id: string, label: string}> The sets.
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function all(): array {
		$raw     = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$sets = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === false || is_string($entry['id'] ?? null) === false || $entry['id'] === '') {
				continue;
			}

			$setId = $entry['id'];

			$sets[] = ['id' => $setId, 'label' => (string)($entry['label'] ?? $setId)];
		}

		return $sets;
	}//end all()

	/**
	 * Record that a set was loaded. Loading it again keeps one entry.
	 *
	 * @param string $setId The set id.
	 * @param string $label The set's label, as the wizard shows it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function record(string $setId, string $label): void {
		$sets = $this->without(setId: $setId);
		$sets[] = ['id' => $setId, 'label' => $label];
		$this->store(sets: $sets);
	}//end record()

	/**
	 * Forget a set after it was removed.
	 *
	 * @param string $setId The set id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function forget(string $setId): void {
		$this->store(sets: $this->without(setId: $setId));
	}//end forget()

	/**
	 * Record a set, taking its label from the choice that carries its id.
	 *
	 * @param string                           $setId   The set id.
	 * @param array<int, array<string, mixed>> $choices Choices with `id` and `label`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function recordFromChoices(string $setId, array $choices): void {
		$label = $setId;
		foreach ($choices as $choice) {
			if (($choice['id'] ?? null) === $setId && is_string($choice['label'] ?? null) === true) {
				$label = $choice['label'];
			}
		}

		$this->record(setId: $setId, label: $label);
	}//end recordFromChoices()

	/**
	 * Forget a set when OpenRegister removed a recorded import of it without
	 * errors; otherwise its remove button stays.
	 *
	 * @param string               $setId  The set id.
	 * @param array<string, mixed> $answer SeedProfileService::remove()'s answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function forgetIfRemoved(string $setId, array $answer): void {
		if (($answer['errors'] ?? 1) === 0 && ($answer['jobs'] ?? []) !== []) {
			$this->forget(setId: $setId);
		}
	}//end forgetIfRemoved()

	/**
	 * A removal step state for every set the wizard offers, each done.
	 *
	 * 🔴 EVERY SET, NOT ONLY THE LOADED ONES, AND ALWAYS DONE. The browser adds
	 * one step per loaded set at page load (src/utils/exampleSetSteps.js); a
	 * step the status left out would be outstanding, and the shared wizard
	 * starts an outstanding run-action step by itself and reopens over every
	 * page while one is open. A set removed mid-session keeps its step until
	 * the next page load, so the status cannot follow the loaded list.
	 *
	 * @param array<int, array<string, mixed>> $choices The wizard's example-set choices.
	 *
	 * @return array<string, array{done: bool}> Step id to state.
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function removalSteps(array $choices): array {
		$steps = [];
		foreach ($choices as $choice) {
			$setId = (string)($choice['id'] ?? '');
			if ($setId !== '' && $setId !== SeedProfileService::NONE_PROFILE) {
				$steps[self::REMOVE_ACTION_PREFIX . $setId] = ['done' => true];
			}
		}

		return $steps;
	}//end removalSteps()

	/**
	 * The set id a per-set removal action names, or null for any other action.
	 *
	 * @param string $actionId The posted action id.
	 *
	 * @return string|null The set id.
	 *
	 * @spec openspec/changes/segment-tidy/specs/example-sets/spec.md#requirement-the-wizard-lists-every-loaded-example-set-with-its-own-remove-button
	 */
	public function setIdFromAction(string $actionId): ?string {
		if (str_starts_with($actionId, self::REMOVE_ACTION_PREFIX) === false) {
			return null;
		}

		$setId = substr($actionId, strlen(self::REMOVE_ACTION_PREFIX));
		if ($setId === '') {
			return null;
		}

		return $setId;
	}//end setIdFromAction()

	/**
	 * The list without one set.
	 *
	 * @param string $setId The set id.
	 *
	 * @return array<int, array{id: string, label: string}> The rest.
	 */
	private function without(string $setId): array {
		return array_values(
			array_filter(
				$this->all(),
				static fn (array $set): bool => $set['id'] !== $setId
			)
		);
	}//end without()

	/**
	 * Write the list.
	 *
	 * @param array<int, array{id: string, label: string}> $sets The sets.
	 *
	 * @return void
	 */
	private function store(array $sets): void {
		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, (string)json_encode($sets));
	}//end store()
}//end class
