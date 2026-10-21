<?php

/**
 * Learniq Signing Service
 *
 * Records a signature on a learning plan or a work placement agreement. The
 * server sets who signed, when, at which version and for which tenant, and
 * accepts only a role the signer holds on that record: learner, coordinator
 * and student come from the record itself, parent from the learner's
 * `LearnerProfile.parentIds`, teacher and school from group membership.
 * Signature and PokSignature are append-only and closed to direct creation,
 * so this is the only way a signature is written.
 *
 * @category Service
 * @package  OCA\Learniq\Service\Signing
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
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Signing;

use DateTimeZone;
use OCA\Learniq\Exception\SigningRefusedException;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;

/**
 * Which roles a user holds on a signable record, and the signature itself.
 *
 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
 */
class SigningService {

	private const REGISTER = 'learniq';

	/**
	 * The signable subjects, keyed by the slug in the URL.
	 *
	 * @var array<string, array{subjectSchema: string, signatureSchema: string, states: string[], subjectKind: ?string}>
	 */
	private const SUBJECTS = [
		'learning-plan' => [
			'subjectSchema' => 'learning-plan',
			'signatureSchema' => 'signature',
			'states' => ['draft'],
			'subjectKind' => 'learning-plan',
		],
		'placement-agreement' => [
			'subjectSchema' => 'praktijkovereenkomst',
			'signatureSchema' => 'pok-signature',
			'states' => ['draft', 'pending-signatures'],
			'subjectKind' => null,
		],
	];

	/**
	 * Group whose members sign a learning plan as teacher.
	 */
	private const TEACHER_GROUP = 'instructors';

	/**
	 * Group whose members sign a work placement agreement for the school,
	 * next to the placement's own school coach.
	 */
	private const SCHOOL_GROUP = 'coordinators';

	/**
	 * Longest typed signature accepted.
	 */
	private const MAX_TYPED_LENGTH = 200;

	/**
	 * Longest drawn signature accepted, as a data URL.
	 */
	private const MAX_DRAWN_LENGTH = 512000;

	private const DRAWN_PREFIX = 'data:image/png;base64,';

	/**
	 * Constructor.
	 *
	 * @param ObjectService          $objects      OpenRegister object access.
	 * @param IGroupManager          $groupManager Group membership.
	 * @param PokParentSignatureRule $parentRule   The parents of a placement's student.
	 * @param ITimeFactory           $timeFactory  The signing time.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly IGroupManager $groupManager,
		private readonly PokParentSignatureRule $parentRule,
		private readonly ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * The roles a user may sign a record in, the most specific first, and
	 * the version that would be signed.
	 *
	 * @param string $subject The subject slug: `learning-plan` or `placement-agreement`.
	 * @param string $id      The record uuid.
	 * @param string $userId  The signing user.
	 *
	 * @return array{roles: string[], version: int, signable: bool}
	 *
	 * @throws SigningRefusedException When the subject is unknown or the record does not exist.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	public function rolesFor(string $subject, string $id, string $userId): array {
		$config = $this->subjectConfig(subject: $subject);
		$record = $this->load(schema: $config['subjectSchema'], id: $id);

		return [
			'roles' => $this->heldRoles(subject: $subject, record: $record, userId: $userId),
			'version' => (int)($record['version'] ?? 1),
			'signable' => in_array((string)($record['lifecycle'] ?? 'draft'), $config['states'], true),
		];
	}//end rolesFor()

	/**
	 * Record a signature by the user on the record's current version.
	 *
	 * @param string              $subject The subject slug: `learning-plan` or `placement-agreement`.
	 * @param string              $id      The record uuid.
	 * @param string              $userId  The signing user.
	 * @param string              $role    The role asked for; empty takes the first role held.
	 * @param array<string,mixed> $capture `{mode: typed|drawn, value: string, affirmed: bool}`.
	 *
	 * @return array{id: string, signerRole: string, signedAt: string, subjectVersion: int}
	 *
	 * @throws SigningRefusedException When the signature is refused.
	 *
	 * @spec openspec/specs/learning-plan/spec.md#requirement-a-signature-is-recorded-by-the-server-in-a-role-the-signer-holds
	 */
	public function sign(string $subject, string $id, string $userId, string $role, array $capture): array {
		$config = $this->subjectConfig(subject: $subject);
		[$mode, $value] = $this->validCapture(capture: $capture);
		$record = $this->load(schema: $config['subjectSchema'], id: $id);

		if (in_array((string)($record['lifecycle'] ?? 'draft'), $config['states'], true) === false) {
			throw new SigningRefusedException(
				status: Http::STATUS_CONFLICT,
				reason: 'not-signable',
				message: 'This document can no longer be signed.'
			);
		}

		$held = $this->heldRoles(subject: $subject, record: $record, userId: $userId);
		if ($role === '' && $held !== []) {
			$role = $held[0];
		}

		if (in_array($role, $held, true) === false) {
			throw new SigningRefusedException(status: Http::STATUS_FORBIDDEN, reason: 'role-not-held', message: 'You cannot sign this document in that role.');
		}

		$subjectId = (string)($record['id'] ?? $id);
		$version = (int)($record['version'] ?? 1);
		if ($this->alreadySigned(schema: $config['signatureSchema'], subjectId: $subjectId, version: $version, userId: $userId, role: $role) === true) {
			throw new SigningRefusedException(
				status: Http::STATUS_CONFLICT,
				reason: 'already-signed',
				message: 'You have already signed this version in that role.'
			);
		}

		$signedAt = $this->timeFactory->getDateTime('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
		$row = [
			'subjectId' => $subjectId,
			'subjectVersion' => $version,
			'signerId' => $userId,
			'signerRole' => $role,
			'signedAt' => $signedAt,
			'assuranceLevel' => 'basic',
			'method' => $mode . '-signature',
			'evidenceRef' => $mode . ':' . $value,
			'tenant_id' => (string)($record['tenant_id'] ?? ''),
		];
		if ($config['subjectKind'] !== null) {
			$row['subjectKind'] = $config['subjectKind'];
		}

		$saved = $this->objects->saveObject(object: $row, register: self::REGISTER, schema: $config['signatureSchema'], _rbac: false);

		return [
			'id' => (string)($saved->jsonSerialize()['id'] ?? ''),
			'signerRole' => $role,
			'signedAt' => $signedAt,
			'subjectVersion' => $version,
		];
	}//end sign()

	/**
	 * The configuration of a subject slug.
	 *
	 * @param string $subject The subject slug.
	 *
	 * @return array{subjectSchema: string, signatureSchema: string, states: string[], subjectKind: ?string}
	 *
	 * @throws SigningRefusedException When the slug is not a signable subject.
	 */
	private function subjectConfig(string $subject): array {
		if (isset(self::SUBJECTS[$subject]) === false) {
			throw new SigningRefusedException(status: Http::STATUS_NOT_FOUND, reason: 'unknown-subject', message: 'This kind of document cannot be signed.');
		}

		return self::SUBJECTS[$subject];
	}//end subjectConfig()

	/**
	 * The mode and value of a complete, affirmed capture.
	 *
	 * @param array<string,mixed> $capture The capture from the signing dialog.
	 *
	 * @return array{0: string, 1: string}
	 *
	 * @throws SigningRefusedException When the capture is incomplete or malformed.
	 */
	private function validCapture(array $capture): array {
		$mode = $capture['mode'] ?? '';
		$value = $capture['value'] ?? '';
		if (($capture['affirmed'] ?? false) !== true || is_string($value) === false || trim($value) === '') {
			throw new SigningRefusedException(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				reason: 'incomplete',
				message: 'Sign and confirm that you agree before saving.'
			);
		}

		$valid = ($mode === 'typed' && mb_strlen($value) <= self::MAX_TYPED_LENGTH)
			|| ($mode === 'drawn'
			&& str_starts_with($value, self::DRAWN_PREFIX) === true
			&& strlen($value) <= self::MAX_DRAWN_LENGTH);
		if ($valid === false) {
			throw new SigningRefusedException(
				status: Http::STATUS_UNPROCESSABLE_ENTITY,
				reason: 'invalid-signature',
				message: 'This signature cannot be saved.'
			);
		}

		return [$mode, trim($value)];
	}//end validCapture()

	/**
	 * Load a record as an array.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The record uuid.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws SigningRefusedException When the record does not exist.
	 */
	private function load(string $schema, string $id): array {
		try {
			$record = $this->objects->find(id: $id, register: self::REGISTER, schema: $schema, _rbac: false, _render: false)?->jsonSerialize();
		} catch (DoesNotExistException) {
			$record = null;
		}

		if (is_array($record) === false) {
			throw new SigningRefusedException(status: Http::STATUS_NOT_FOUND, reason: 'not-found', message: 'This document could not be found.');
		}

		return $record;
	}//end load()

	/**
	 * The roles the user holds on the record, the most specific first.
	 *
	 * @param string              $subject The subject slug.
	 * @param array<string,mixed> $record  The record.
	 * @param string              $userId  The user.
	 *
	 * @return string[]
	 */
	private function heldRoles(string $subject, array $record, string $userId): array {
		if ($userId === '') {
			return [];
		}

		if ($subject === 'learning-plan') {
			return $this->learningPlanRoles(plan: $record, userId: $userId);
		}

		return $this->agreementRoles(agreement: $record, userId: $userId);
	}//end heldRoles()

	/**
	 * Roles on a learning plan: learner, coordinator, parent, teacher.
	 *
	 * @param array<string,mixed> $plan   The LearningPlan.
	 * @param string              $userId The user.
	 *
	 * @return string[]
	 */
	private function learningPlanRoles(array $plan, string $userId): array {
		$learnerId = (string)($plan['learnerId'] ?? '');
		$roles = [];
		if ($learnerId !== '' && $learnerId === $userId) {
			$roles[] = 'learner';
		}

		if ((string)($plan['coordinatorId'] ?? '') === $userId) {
			$roles[] = 'coordinator';
		}

		if (in_array($userId, $this->parentIdsOf(learnerId: $learnerId, tenantId: (string)($plan['tenant_id'] ?? '')), true) === true) {
			$roles[] = 'parent';
		}

		if ($this->groupManager->isInGroup($userId, self::TEACHER_GROUP) === true) {
			$roles[] = 'teacher';
		}

		return $roles;
	}//end learningPlanRoles()

	/**
	 * Roles on a work placement agreement: student, school, parent. The
	 * workplace trainer signs through the portal, not here.
	 *
	 * @param array<string,mixed> $agreement The Praktijkovereenkomst.
	 * @param string              $userId    The user.
	 *
	 * @return string[]
	 */
	private function agreementRoles(array $agreement, string $userId): array {
		$placement = $this->findPlacement(placementId: (string)($agreement['bpvPlacementId'] ?? ''));
		$isCoach = (string)($placement['schoolCoachId'] ?? '') === $userId;
		$roles = [];
		if ((string)($placement['learnerId'] ?? '') === $userId) {
			$roles[] = 'student';
		}

		if ($isCoach === true || $this->groupManager->isInGroup($userId, self::SCHOOL_GROUP) === true) {
			$roles[] = 'school';
		}

		if (in_array($userId, $this->parentRule->evaluate(pok: $agreement, studentSignedAt: null)['parentIds'], true) === true) {
			$roles[] = 'parent';
		}

		return $roles;
	}//end agreementRoles()

	/**
	 * The placement an agreement belongs to, or an empty array.
	 *
	 * @param string $placementId The BpvPlacement uuid.
	 *
	 * @return array<string,mixed>
	 */
	private function findPlacement(string $placementId): array {
		if ($placementId === '') {
			return [];
		}

		try {
			$placement = $this->objects->find(id: $placementId, register: self::REGISTER, schema: 'bpv-placement', _rbac: false, _render: false);
		} catch (DoesNotExistException) {
			return [];
		}

		if ($placement === null) {
			return [];
		}

		return $placement->jsonSerialize();
	}//end findPlacement()

	/**
	 * The parents and guardians on a learner's profile, found on ncUserId.
	 *
	 * @param string $learnerId The learner's user id.
	 * @param string $tenantId  The tenant to look in.
	 *
	 * @return string[]
	 */
	private function parentIdsOf(string $learnerId, string $tenantId): array {
		if ($learnerId === '') {
			return [];
		}

		$filters = ['register' => self::REGISTER, 'schema' => 'learner-profile', 'ncUserId' => $learnerId];
		if ($tenantId !== '') {
			$filters['tenant_id'] = $tenantId;
		}

		$profiles = $this->objects->findAll(config: ['filters' => $filters, 'limit' => 1], _rbac: false);
		if ($profiles === []) {
			return [];
		}

		$profile = $profiles[0];
		if (is_array($profile) === false) {
			$profile = $profile->jsonSerialize();
		}

		return array_values(array_map('strval', (array)($profile['parentIds'] ?? [])));
	}//end parentIdsOf()

	/**
	 * Whether the user already signed this version in this role.
	 *
	 * @param string $schema    The signature schema slug.
	 * @param string $subjectId The record uuid.
	 * @param int    $version   The record version.
	 * @param string $userId    The user.
	 * @param string $role      The role.
	 *
	 * @return bool
	 */
	private function alreadySigned(string $schema, string $subjectId, int $version, string $userId, string $role): bool {
		$rows = $this->objects->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => $schema,
					'subjectId' => $subjectId,
					'subjectVersion' => $version,
					'signerId' => $userId,
					'signerRole' => $role,
				],
				'limit' => 1,
			],
			_rbac: false
		);

		return $rows !== [];
	}//end alreadySigned()
}//end class
