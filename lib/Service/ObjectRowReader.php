<?php

/**
 * Scholiq Object Row Reader
 *
 * The one-line read helper the competency roll-up collaborators share: fetch a
 * single Scholiq-register object by its own id and hand it back as a plain
 * array, normalising the ObjectEntity-or-array shape `ObjectService::findAll()`
 * can return. Extracted so `CompetencyAttainmentRollupHandler`,
 * `CompetencyAttainmentWriter` and `CompetencyLevelResolver` share one
 * implementation instead of each carrying a private copy.
 *
 * A blank id, or a filter that matches nothing, is `null` — never an
 * exception — because every caller treats "not found" and "not referenced" the
 * same way: skip this evidence, do not block the transition that produced it.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads a single Scholiq-register object by id, normalised to a plain array.
 */
class ObjectRowReader {

	private const SCHOLIQ_REGISTER = 'scholiq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object access service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Load a single OpenRegister object by id.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id Object UUID.
	 *
	 * @return array<string,mixed>|null The object data, or null when not found.
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	public function load(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$results = $this->objectService->findAll(
			[
				'register' => self::SCHOLIQ_REGISTER,
				'schema' => $schema,
				'filters' => ['id' => $id],
				'limit' => 1,
			]
		);

		if (empty($results) === true) {
			return null;
		}

		return $this->toArray(object: $results[0]);
	}//end load()

	/**
	 * Normalise an OR object result (array or ObjectEntity-like) to a plain array.
	 *
	 * @param mixed $object The raw findAll()/saveObject() result element.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/competency-framework/specs/competency/spec.md#requirement-competencyattainment-is-a-declared-event-driven-per-learner-roll-up-never-a-timedjob
	 */
	public function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		return $object->jsonSerialize();
	}//end toArray()
}//end class
