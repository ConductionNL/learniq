<?php

/**
 * Learniq Admission Panel Reads
 *
 * The reads a review panel's rules need: one learniq object (an application
 * or its admissions round) read without the caller's RBAC, because the rule
 * itself decides who may act, and the advice given on one application.
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the application, its round and the advice given on it.
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
 */
class AdmissionPanelReads {

	private const REGISTER = 'learniq';

	private const ADVICE_SCHEMA = 'admission-advice';

	/**
	 * At most this many advice rows are read for one application.
	 */
	private const ADVICE_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister object reads.
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
	 * One learniq object, or null when it is not there or cannot be read.
	 *
	 * @param string $schema Schema slug.
	 * @param string $id     Object uuid.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function object(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$object = $this->objectService->find(id: $id, options: new ObjectFindOptions(register: self::REGISTER, schema: $schema), _rbac: false);
		} catch (Throwable $exception) {
			$this->logger->debug(
				'[AdmissionPanelReads] {schema} {id} not readable: {msg}',
				['schema' => $schema, 'id' => $id, 'msg' => $exception->getMessage()]
			);
			return null;
		}

		if ($object === null) {
			return null;
		}

		return (array)$object->jsonSerialize();
	}//end object()

	/**
	 * The advice given on one application.
	 *
	 * @param string $applicationId The application.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/enrolment/spec.md#requirement-a-review-panel-decides-an-application-together
	 */
	public function adviceOn(string $applicationId): array {
		if ($applicationId === '') {
			return [];
		}

		$rows = (new SchemaRows(objectService: $this->objectService, limit: self::ADVICE_LIMIT))->serialised(
			schema: self::ADVICE_SCHEMA,
			filters: ['applicationId' => $applicationId]
		);

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (string)($row['applicationId'] ?? '') === $applicationId
			)
		);
	}//end adviceOn()
}//end class
