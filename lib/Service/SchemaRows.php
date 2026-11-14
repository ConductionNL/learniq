<?php

/**
 * Learniq rows of one learniq schema, read up to a limit.
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
 * @spec exclude Read plumbing, not a behavioural requirement: one findAll()
 *       of a learniq schema, handed back as arrays.
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Reads the rows of one learniq schema matching the caller's filters, up to
 * the limit the owner sets, through the caller's session.
 *
 * @spec exclude Read plumbing, not a behavioural requirement.
 */
final class SchemaRows {

	private const REGISTER = 'learniq';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object access.
	 * @param int $limit The most rows one read returns.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly int $limit,
	) {
	}//end __construct()

	/**
	 * The rows as arrays; an entity is read as its serialised data.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude Read plumbing, not a behavioural requirement.
	 */
	public function serialised(string $schema, array $filters): array {
		$out = [];
		foreach ($this->found(schema: $schema, filters: $filters) as $row) {
			if (is_array($row) === false) {
				$row = $row->jsonSerialize();
			}

			$out[] = $row;
		}

		return $out;
	}//end serialised()

	/**
	 * The rows as arrays; an entity is read as its object data.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude Read plumbing, not a behavioural requirement.
	 */
	public function objectData(string $schema, array $filters): array {
		$arrays = [];
		foreach ($this->found(schema: $schema, filters: $filters) as $row) {
			if ($row instanceof ObjectEntity) {
				$arrays[] = ($row->getObject() ?? []);
				continue;
			}

			$arrays[] = (array)$row;
		}

		return $arrays;
	}//end objectData()

	/**
	 * What findAll() answers for the schema and filters.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, mixed>
	 */
	private function found(string $schema, array $filters): array {
		return $this->objectService->findAll(
			[
				'filters' => array_merge($filters, ['register' => self::REGISTER, 'schema' => $schema]),
				'limit' => $this->limit,
			]
		);
	}//end found()
}//end class
