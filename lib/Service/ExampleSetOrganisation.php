<?php

/**
 * Learniq example set organisation
 *
 * Gives every row an example set writes the instance's default organisation,
 * as a normal write does. OpenRegister's seed import inserts a set's rows
 * without one, so the loader resolves the organisation before the import and
 * gives it to each row of the set afterwards. A row that already carries an
 * organisation keeps it.
 *
 * @category Service
 * @package  OCA\Learniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-example-set-rows-carry-the-instances-organisation
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The instance's default organisation, and giving it to a set's rows.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-example-set-rows-carry-the-instances-organisation
 */
class ExampleSetOrganisation {

	/**
	 * Constructor.
	 *
	 * @param OrganisationService $organisations OpenRegister's organisation service.
	 * @param ObjectService       $objects       OpenRegister's object service, to find a row.
	 * @param MagicMapper         $mapper        OpenRegister's object mapper, to write a row.
	 * @param LoggerInterface     $logger        Records rows that could not be given the organisation.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrganisationService $organisations,
		private readonly ObjectService $objects,
		private readonly MagicMapper $mapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uuid of the instance's default organisation.
	 *
	 * Resolved the way OpenRegister resolves it for a write without an active
	 * organisation, so a set loaded from occ lands where such a write lands.
	 *
	 * @return string The organisation uuid.
	 *
	 * @throws RuntimeException When no default organisation can be resolved.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-example-set-rows-carry-the-instances-organisation
	 */
	public function resolve(): string {
		try {
			$organisation = $this->organisations->ensureDefaultOrganisation();
			$uuid         = (string)$organisation->getUuid();
		} catch (Throwable $exception) {
			throw new RuntimeException(
				'Example data needs the instance\'s default organisation, which could not be resolved: ' . $exception->getMessage(),
				0,
				$exception
			);
		}

		if ($uuid === '') {
			throw new RuntimeException('Example data needs the instance\'s default organisation, and OpenRegister answered without one.');
		}

		return $uuid;
	}//end resolve()

	/**
	 * Give each listed row that exists and has no organisation the given one.
	 *
	 * Every row is tried before a failure is reported, so one bad row does not
	 * keep the rest without an organisation.
	 * @param array<int, array<string, mixed>> $objects      The set's descriptor objects (their uuid and `@self`).
	 * @param string                           $organisation The organisation uuid.
	 *
	 * @return array{given: int, kept: int, missing: int}
	 *
	 * @throws RuntimeException When a row could not be given the organisation.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-example-set-rows-carry-the-instances-organisation
	 */
	public function stamp(array $objects, string $organisation): array {
		$counts = ['given' => 0, 'kept' => 0, 'missing' => 0, 'failed' => 0];
		if ($objects === []) {
			return ['given' => 0, 'kept' => 0, 'missing' => 0];
		}

		foreach ($objects as $object) {
			$outcome = $this->stampOne(object: $object, organisation: $organisation);
			$counts[$outcome]++;
		}

		if ($counts['failed'] > 0) {
			throw new RuntimeException(
				$counts['failed'] . ' example row(s) could not be given organisation ' . $organisation . '; load the set again.'
			);
		}

		return ['given' => $counts['given'], 'kept' => $counts['kept'], 'missing' => $counts['missing']];
	}//end stamp()

	/**
	 * Give one row the organisation.
	 *
	 * @param array<string, mixed> $object       The descriptor object.
	 * @param string               $organisation The organisation uuid.
	 *
	 * @return string `given`, `kept`, `missing` or `failed`.
	 */
	private function stampOne(array $object, string $organisation): string {
		$uuid     = (string)($object['uuid'] ?? '');
		$register = (string)($object['@self']['register'] ?? '');
		$schema   = (string)($object['@self']['schema'] ?? '');
		if ($uuid === '' || $register === '' || $schema === '') {
			return 'missing';
		}

		// A row the import left out (a shared code another set holds) is not found.
		try {
			$entity = $this->objects->find(
				id: $uuid,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
		} catch (Throwable) {
			return 'missing';
		}

		if ($entity === null) {
			return 'missing';
		}

		if ((string)$entity->getOrganisation() !== '') {
			return 'kept';
		}

		try {
			$entity->setOrganisation($organisation);
			$this->mapper->update(entity: $entity);
			return 'given';
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[ExampleSetOrganisation] could not give {uuid} its organisation: {msg}',
				['uuid' => $uuid, 'msg' => $exception->getMessage()]
			);
			return 'failed';
		}
	}//end stampOne()
}//end class
