<?php

/**
 * Learniq PortalWerkprocesAssessment
 *
 * Writes the werkproces assessment a workplace trainer submits from the portal,
 * and records who assessed and how sure the school is of that.
 *
 * Ruben decided on 4 October 2026 that an invited trainer may assess too, so
 * the portal no longer demands an eHerkenning sign-in. The evidence has to
 * carry its own weight instead: every assessment records the assessor's name,
 * their leerbedrijf and its KvK number, copied from the `Praktijkopleider`
 * record the portal subject resolves to, plus the eIDAS level that session
 * reached. A school that wants more can set a higher floor
 * (`bpv_assessment_min_assurance`), and a submit below it is refused.
 *
 * The write runs through learniq's own endpoint rather than a portal create,
 * because only a forwarded request carries the signed assertion, and the
 * assertion is the one place the sign-in level can be read.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Portal
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
 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks, stamps and stores one portal werkproces assessment.
 *
 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
 */
class PortalWerkprocesAssessment {

	private const REGISTER = 'learniq';

	private const ASSESSMENT_SCHEMA = 'werkproces-assessment';

	private const TRAINER_SCHEMA = 'praktijkopleider';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * The app id whose config holds the school's floor.
	 */
	public const APP_ID = 'learniq';

	/**
	 * The config key a school raises to demand an eHerkenning sign-in before
	 * an assessment is accepted. Default `basic`, so an invited trainer may
	 * assess (Ruben, 4 October 2026).
	 */
	public const MIN_ASSURANCE_KEY = 'bpv_assessment_min_assurance';

	/**
	 * The eIDAS ladder, as `PokSignature.assuranceLevel` spells it.
	 *
	 * @var array<string, int>
	 */
	private const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

	/**
	 * What a portal session's trust means on that ladder. A portal trust
	 * cannot be `none`: the session exists.
	 *
	 * @var array<string, string>
	 */
	private const TRUST_TO_ASSURANCE = ['low' => 'basic', 'substantial' => 'substantial', 'high' => 'high'];

	/**
	 * The fields the trainer's form may send. Everything else on the record is
	 * the server's, including who assessed and how sure we are of that.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE = [
		'bpvPlacementId',
		'curriculumPlanId',
		'componentId',
		'kwalificatiedossierCode',
		'coreTaskCode',
		'werkprocesCode',
		'werkprocesLabel',
		'competencyId',
		'assessment',
		'notes',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the trainer and writes the assessment.
	 * @param IAppConfig      $appConfig     Holds the school's assurance floor.
	 * @param LoggerInterface $logger        PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Store one assessment for the trainer the assertion names.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-invited-trainer-may-submit-a-werkproces-assessment
	 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	public function submit(string $trainerRef, string $trust, array $body): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$floor = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(
				status: 403,
				body: ['error' => 'assurance_too_low', 'required' => $floor],
				reason: 'assurance-too-low'
			);
		}

		$placementId = $this->text(value: ($body['bpvPlacementId'] ?? null));
		if ($trainerRef === '' || $placementId === '') {
			return new PortalOutcome(status: 422, body: ['error' => 'incomplete'], reason: 'incomplete');
		}

		try {
			$trainer = $this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef);
			if ($trainer === null) {
				return new PortalOutcome(status: 403, body: ['error' => 'unknown_assessor'], reason: 'unknown-assessor');
			}

			if ($this->ownsPlacement(trainerRef: $trainerRef, placementId: $placementId) === false) {
				return new PortalOutcome(status: 403, body: ['error' => 'not_your_placement'], reason: 'not-your-placement');
			}

			$saved = $this->objectService->saveObject(
				object: $this->stamped(trainerRef: $trainerRef, trainer: $trainer, assurance: $assurance, body: $body),
				register: self::REGISTER,
				schema: self::ASSESSMENT_SCHEMA,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalWerkprocesAssessment] A portal assessment could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		$row = $this->toRow(object: $saved);

		return new PortalOutcome(
			status: 201,
			body: [
				'assessmentId' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
				'assuranceLevel' => $assurance,
			]
		);
	}//end submit()

	/**
	 * The assessment as it is stored: the trainer's own fields, then who
	 * assessed and how sure the school is. A client value for any of the
	 * server's fields is dropped, never merged.
	 *
	 * @param string               $trainerRef The trainer's own uuid.
	 * @param array<string, mixed> $trainer    The Praktijkopleider record.
	 * @param string               $assurance  The eIDAS level of the session.
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/an-invited-trainer-may-assess/specs/bpv/spec.md#requirement-an-assessment-records-who-assessed-and-how-sure-the-school-is
	 */
	private function stamped(string $trainerRef, array $trainer, string $assurance, array $body): array {
		$object = [];
		foreach (self::WRITABLE as $field) {
			if (array_key_exists($field, $body) === true) {
				$object[$field] = $body[$field];
			}
		}

		$name = trim($this->text(value: ($trainer['givenName'] ?? null)) . ' ' . $this->text(value: ($trainer['familyName'] ?? null)));

		return array_merge(
			$object,
			[
				'assessorId' => $trainerRef,
				'assessorName' => $this->orNull(value: $name),
				'assessorCompany' => $this->orNull(value: $this->text(value: ($trainer['trainingCompanyName'] ?? null))),
				'assessorCompanyKvkNumber' => $this->orNull(value: $this->text(value: ($trainer['trainingCompanyKvkNumber'] ?? null))),
				'assuranceLevel' => $assurance,
				'assessedAt' => (new DateTimeImmutable())->format('Y-m-d'),
				'lifecycle' => 'submitted',
				'tenant_id' => $this->text(value: ($trainer['tenant_id'] ?? null)),
			]
		);
	}//end stamped()

	/**
	 * The assurance a school demands before it accepts an assessment.
	 *
	 * @return string One of the eIDAS levels; `basic` when unset or unknown.
	 */
	private function floor(): string {
		$declared = $this->appConfig->getValueString(self::APP_ID, self::MIN_ASSURANCE_KEY, 'basic');
		if (isset(self::ASSURANCE_ORDER[$declared]) === false) {
			return 'basic';
		}

		return $declared;
	}//end floor()

	/**
	 * Whether this placement is one of the trainer's own.
	 *
	 * @param string $trainerRef  The trainer's uuid.
	 * @param string $placementId The placement the form names.
	 *
	 * @return bool
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function ownsPlacement(string $trainerRef, string $placementId): bool {
		$placement = $this->row(schema: self::PLACEMENT_SCHEMA, id: $placementId);

		return $placement !== null && $this->text(value: ($placement['practicalTrainerId'] ?? null)) === $trainerRef;
	}//end ownsPlacement()

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
	private function row(string $schema, string $id): ?array {
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
			$row = $this->toRow(object: $object);
			if (($row['id'] ?? ($row['uuid'] ?? null)) === $id) {
				return $row;
			}
		}

		return null;
	}//end row()

	/**
	 * An OpenRegister result as an array.
	 *
	 * @param mixed $object An array or a serialisable entity.
	 *
	 * @return array<string, mixed>
	 */
	private function toRow(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			return (array)$object->jsonSerialize();
		}

		return [];
	}//end toRow()

	/**
	 * A string value, or '' for anything else.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()

	/**
	 * The text, or null when it is empty.
	 *
	 * @param string $value The text.
	 *
	 * @return string|null
	 */
	private function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()
}//end class
