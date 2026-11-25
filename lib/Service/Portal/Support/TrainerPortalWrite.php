<?php

/**
 * Learniq TrainerPortalWrite
 *
 * What the services behind a workplace trainer's portal forms share: the
 * eIDAS ladder a portal session's trust maps onto, the refusal below a
 * school's floor, one learniq row by uuid, and the system write of the
 * record the server stamped.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal\Support
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
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal\Support;

use OCA\Learniq\Service\Portal\PortalOutcome;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The shared reads, writes and assurance checks of a trainer's portal write.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
 */
abstract class TrainerPortalWrite {

	protected const REGISTER = 'learniq';

	/**
	 * The eIDAS ladder, as `PokSignature.assuranceLevel` spells it.
	 *
	 * @var array<string, int>
	 */
	protected const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

	/**
	 * What a portal session's trust means on that ladder. A portal trust
	 * cannot be `none`: the session exists.
	 *
	 * @var array<string, string>
	 */
	protected const TRUST_TO_ASSURANCE = ['low' => 'basic', 'substantial' => 'substantial', 'high' => 'high'];

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	protected readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the rows and writes the record.
	 * @param IAppConfig      $appConfig     Holds the school's assurance floor.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		protected readonly ObjectService $objectService,
		protected readonly IAppConfig $appConfig,
		protected readonly LoggerInterface $logger,
	) {
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * The eIDAS level a portal session's trust reached.
	 *
	 * @param string $trust The session's trust (`low`, `substantial`, `high`).
	 *
	 * @return string `basic` for anything unknown.
	 */
	protected function assuranceOf(string $trust): string {
		return (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
	}//end assuranceOf()

	/**
	 * The refusal of a session below the school's floor, naming the level
	 * the school requires.
	 *
	 * @param string $assurance The session's eIDAS level.
	 * @param string $floor     The level the school demands.
	 *
	 * @return PortalOutcome|null Null when the session reaches the floor.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
	 */
	protected function belowFloor(string $assurance, string $floor): ?PortalOutcome {
		if ((self::ASSURANCE_ORDER[$assurance] ?? 0) >= (self::ASSURANCE_ORDER[$floor] ?? 1)) {
			return null;
		}

		return new PortalOutcome(
			status: 403,
			body: ['error' => 'assurance_too_low', 'required' => $floor],
			reason: 'assurance-too-low'
		);
	}//end belowFloor()

	/**
	 * Store one record the server stamped, as the system, and return it as
	 * a plain row.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $object The record.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Throwable When OpenRegister refuses the write.
	 */
	protected function store(string $schema, array $object): array {
		$saved = $this->objectService->saveObject(
			object: $object,
			options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: $schema)),
			_rbac: false,
			_multitenancy: false
		);

		return $this->objectRow->asArray(object: $saved);
	}//end store()

	/**
	 * One learniq row by uuid, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	protected function row(string $schema, string $id): ?array {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => ['register' => self::REGISTER, 'schema' => $schema],
				'ids' => [$id],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->objectRow->asArray(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end row()
}//end class
