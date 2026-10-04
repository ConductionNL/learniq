<?php

/**
 * Learniq BackfillLearnerProfileNames
 *
 * Gives every existing learner profile its readable name. The learner
 * profile schema names its objects from `{{ givenName }} {{ familyName }}`
 * (configuration.objectNameField), but OpenRegister computes `@self.name`
 * only when an object is saved, so a profile stored before the register
 * declared the template reads by its uuid until it is saved again. This step
 * saves each such profile unchanged, which makes OpenRegister compute the
 * name. Idempotent: a profile whose `@self.name` already equals its given and
 * family name is not saved, so a second run saves nothing, and a profile with
 * neither name is left alone. Runs without a session, so every read and write
 * passes `_rbac: false` and `_multitenancy: false`; that also reaches merged
 * and deleted profiles and every tenant. The save writes the stored profile
 * unchanged, so it skips validation (`_validation: false`).
 *
 * @category Repair
 * @package  OCA\Learniq\Repair
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
 * @spec openspec/changes/pupils-read-by-name/specs/school-structure/spec.md#requirement-a-pupil-reads-by-name
 */

declare(strict_types=1);

namespace OCA\Learniq\Repair;

use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Saves the learner profiles whose `@self.name` is not their name yet.
 *
 * @spec openspec/changes/pupils-read-by-name/specs/school-structure/spec.md#requirement-a-pupil-reads-by-name
 */
class BackfillLearnerProfileNames implements IRepairStep {

	private const REGISTER = 'learniq';

	private const SCHEMA = 'learner-profile';

	private const PAGE_SIZE = 200;

	/**
	 * Hard stop so a paging defect can never loop forever.
	 */
	private const MAX_PAGES = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object access.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/pupils-read-by-name/specs/school-structure/spec.md#requirement-a-pupil-reads-by-name
	 */
	public function getName(): string {
		return 'Give every existing learner profile its readable name';
	}//end getName()

	/**
	 * Page through every learner profile and save the ones not named yet.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pupils-read-by-name/specs/school-structure/spec.md#requirement-a-pupil-reads-by-name
	 */
	public function run(IOutput $output): void {
		$counts = ['scanned' => 0, 'named' => 0, 'failed' => 0];

		try {
			for ($page = 0; $page < self::MAX_PAGES; $page++) {
				$rows = $this->page(offset: ($page * self::PAGE_SIZE));
				foreach ($rows as $row) {
					$counts['scanned']++;
					$outcome = $this->nameRow(row: $row);
					if ($outcome !== null) {
						$counts[$outcome]++;
					}
				}

				if (count($rows) < self::PAGE_SIZE) {
					break;
				}
			}
		} catch (Throwable $exception) {
			// OpenRegister absent or the register not imported yet: the next
			// upgrade retries.
			$this->logger->warning(
				'[BackfillLearnerProfileNames] Stopped early: {msg}',
				['msg' => $exception->getMessage()]
			);
		}

		$output->info(
			'BackfillLearnerProfileNames: ' . $counts['named'] . ' named, ' . $counts['failed']
			. ' failed, of ' . $counts['scanned'] . ' scanned.'
		);
	}//end run()

	/**
	 * One page of profiles as arrays.
	 *
	 * Paging by offset is stable here: a save changes a profile's name, not
	 * its place in the list.
	 *
	 * @param int $offset Row offset.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function page(int $offset): array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema'   => self::SCHEMA,
				],
				'limit'   => self::PAGE_SIZE,
				'offset'  => $offset,
			],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach ($objects as $object) {
			if (is_array($object) === true) {
				$rows[] = $object;
				continue;
			}

			if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
				$rows[] = (array)$object->jsonSerialize();
			}
		}

		return $rows;
	}//end page()

	/**
	 * Save one profile when its stored name differs from its given and family name.
	 *
	 * @param array<string, mixed> $row The profile.
	 *
	 * @return string|null Counter to bump, or null when the profile needed nothing.
	 */
	private function nameRow(array $row): ?string {
		$uuid = ($row['id'] ?? ($row['uuid'] ?? null));
		if (is_string($uuid) === false || $uuid === '') {
			return null;
		}

		$name = self::nameOf(row: $row);
		$stored = ($row['@self']['name'] ?? null);
		if ($name === null || $stored === $name) {
			return null;
		}

		try {
			// The `@self` block would make OpenRegister check the acting
			// user's folder rights, which a session-less step never has.
			$object = $row;
			unset($object['@self']);

			// The payload is the stored profile, unchanged: validating it again
			// adds nothing, and it refuses a profile whose undecided image
			// consent holds nulls OpenRegister does not accept in a nested
			// object (live on the primary school set: 60 of 468 profiles).
			$this->objectService->saveObject(
				object: $object,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false,
				_validation: false
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[BackfillLearnerProfileNames] Could not save learner profile {id}: {msg}',
				['id' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}

		return 'named';
	}//end nameRow()

	/**
	 * The name OpenRegister computes from `{{ givenName }} {{ familyName }}`.
	 *
	 * Each part is trimmed and the whitespace between them collapses; null
	 * when neither part is filled.
	 *
	 * @param array<string, mixed> $row The profile.
	 *
	 * @return string|null
	 */
	private static function nameOf(array $row): ?string {
		$parts = [];
		foreach (['givenName', 'familyName'] as $field) {
			$value = ($row[$field] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				$parts[] = trim($value);
			}
		}

		if ($parts === []) {
			return null;
		}

		return (string)preg_replace('/\s+/', ' ', implode(' ', $parts));
	}//end nameOf()
}//end class
