<?php

/**
 * Learniq PortalPokSigning
 *
 * Writes the signature a workplace trainer gives on a praktijkovereenkomst
 * from the portal, and records how sure the school is of who signed.
 *
 * Ruben decided on 25 November 2026 that an invited praktijkopleider may sign
 * a POK with a low-assurance login, the same bar learniq#1679 lowered for
 * assessing. The signature does not pretend the sign-in was stronger: the
 * server stamps the eIDAS level the session really reached, so a signature
 * made from an invitation says `basic` on its own row. A school that must have
 * eHerkenning for a POK raises `bpv_pok_min_assurance`, and a signature below
 * it is refused with the level it needs.
 *
 * The write runs through learniq's own endpoint rather than a portal create,
 * because only a forwarded request carries the signed assertion, and the
 * assertion is the one place the sign-in level can be read. Who signed, in
 * which role, when, how and how surely are the server's; a client value for
 * any of them is dropped.
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
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */

declare(strict_types=1);

namespace OCA\Learniq\Service\Portal;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Learniq\Kernel\Contracts\PortalPokSigningInterface;
use OCA\Learniq\Service\Portal\Support\AssessmentRowValues;
use OCA\Learniq\Support\ObjectRow;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Checks, stamps and stores one portal POK signature.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */
class PortalPokSigning implements PortalPokSigningInterface {

	use AssessmentRowValues;

	private const REGISTER = 'learniq';

	private const SIGNATURE_SCHEMA = 'pok-signature';

	private const AGREEMENT_SCHEMA = 'praktijkovereenkomst';

	private const TRAINER_SCHEMA = 'praktijkopleider';

	private const PLACEMENT_SCHEMA = 'bpv-placement';

	/**
	 * The role a trainer signs in, as `PokSignature.signerRole` spells it.
	 */
	private const SIGNER_ROLE = 'praktijkopleider';

	/**
	 * How a portal signature is given: she confirms in her own session.
	 */
	public const METHOD = 'portal-click-to-confirm';

	/**
	 * The states in which an agreement still takes signatures, as the
	 * staff-side SigningService allows them.
	 *
	 * @var array<int, string>
	 */
	private const SIGNABLE_STATES = ['draft', 'pending-signatures'];

	/**
	 * The app id whose config holds the school's floor.
	 */
	public const APP_ID = 'learniq';

	/**
	 * The config key a school raises to demand an eHerkenning sign-in before
	 * a POK signature is accepted. Default `basic`, so an invited trainer may
	 * sign (Ruben, 25 November 2026).
	 */
	public const MIN_ASSURANCE_KEY = 'bpv_pok_min_assurance';

	/**
	 * The eIDAS ladder, as `PokSignature.assuranceLevel` spells it.
	 *
	 * @var array<string, int>
	 */
	private const ASSURANCE_ORDER = ['none' => 0, 'basic' => 1, 'substantial' => 2, 'high' => 3];

	/**
	 * What a portal session's trust means on that ladder.
	 *
	 * @var array<string, string>
	 */
	private const TRUST_TO_ASSURANCE = ['low' => 'basic', 'substantial' => 'substantial', 'high' => 'high'];

	/**
	 * Turns one OpenRegister result into a plain array.
	 *
	 * @var ObjectRow
	 */
	private readonly ObjectRow $objectRow;

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads the agreement and writes the signature.
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
		$this->objectRow = new ObjectRow();
	}//end __construct()

	/**
	 * Store the signature of the trainer the assertion names.
	 *
	 * @param string               $trainerRef The `practicalTrainerId` claim.
	 * @param string               $trust      The session's trust (`low`, `substantial`, `high`).
	 * @param array<string, mixed> $body       What the form sent.
	 *
	 * @return PortalOutcome
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
	 */
	public function sign(string $trainerRef, string $trust, array $body): PortalOutcome {
		$assurance = (self::TRUST_TO_ASSURANCE[$trust] ?? 'basic');
		$answers = new PortalAnswers();
		$agreementId = $answers->text(field: 'subjectId', value: ($body['subjectId'] ?? null));
		$refusal = $this->requestRefusal(assurance: $assurance, trainerRef: $trainerRef, agreementId: $agreementId, answers: $answers);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$agreement = $this->row(schema: self::AGREEMENT_SCHEMA, id: $agreementId);
			$refusal = $this->agreementRefusal(trainerRef: $trainerRef, agreement: $agreement);
			if ($refusal !== null) {
				return $refusal;
			}

			$version = (int)($agreement['version'] ?? 1);
			if ($this->alreadySigned(trainerRef: $trainerRef, agreementId: $agreementId, version: $version) === true) {
				return new PortalOutcome(status: 409, body: ['error' => 'already_signed'], reason: 'already-signed');
			}

			$signedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
			$saved = $this->objectService->saveObject(
				object: [
					'subjectId' => $agreementId,
					'subjectVersion' => $version,
					'signerId' => $trainerRef,
					'signerRole' => self::SIGNER_ROLE,
					'signedAt' => $signedAt,
					'assuranceLevel' => $assurance,
					'method' => self::METHOD,
					'tenant_id' => $this->text(value: ($agreement['tenant_id'] ?? null)),
				],
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: self::REGISTER, schema: self::SIGNATURE_SCHEMA)),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPokSigning] A portal POK signature could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		$row = $this->toRow(object: $saved);

		return new PortalOutcome(
			status: 201,
			body: [
				'signatureId' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
				'assuranceLevel' => $assurance,
				'signedAt' => $signedAt,
			]
		);
	}//end sign()

	/**
	 * Why the request is refused before any read: assurance below the
	 * school's floor, or no agreement named.
	 *
	 * @param string        $assurance   The caller's assurance level.
	 * @param string        $trainerRef  The trainer's uuid from the claim.
	 * @param string        $agreementId The agreement the form names.
	 * @param PortalAnswers $answers     The form's answers, which name the missing fields.
	 *
	 * @return PortalOutcome|null Null when the request may proceed.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-a-school-may-still-demand-eherkenning-for-a-praktijkovereenkomst
	 */
	private function requestRefusal(string $assurance, string $trainerRef, string $agreementId, PortalAnswers $answers): ?PortalOutcome {
		$floor = $this->floor();
		if (self::ASSURANCE_ORDER[$assurance] < self::ASSURANCE_ORDER[$floor]) {
			return new PortalOutcome(
				status: 403,
				body: ['error' => 'assurance_too_low', 'required' => $floor],
				reason: 'assurance-too-low'
			);
		}

		if ($trainerRef === '' || $agreementId === '') {
			return $answers->refused(error: 'incomplete', reason: 'incomplete');
		}

		return null;
	}//end requestRefusal()

	/**
	 * Why this agreement may not be signed by this trainer: it does not
	 * exist, it no longer takes signatures, or its placement is not hers.
	 *
	 * @param string                    $trainerRef The trainer's uuid.
	 * @param array<string, mixed>|null $agreement  The Praktijkovereenkomst row.
	 *
	 * @return PortalOutcome|null Null when she may sign it.
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function agreementRefusal(string $trainerRef, ?array $agreement): ?PortalOutcome {
		// An unknown agreement and somebody else's answer the same, so the
		// refusal does not tell her which agreements exist.
		$notYours = new PortalOutcome(status: 403, body: ['error' => 'not_your_placement'], reason: 'not-your-placement');
		if ($agreement === null || $this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef) === null) {
			return $notYours;
		}

		$placementId = $this->text(value: ($agreement['bpvPlacementId'] ?? null));
		$placement = null;
		if ($placementId !== '') {
			$placement = $this->row(schema: self::PLACEMENT_SCHEMA, id: $placementId);
		}

		if ($placement === null || $this->text(value: ($placement['practicalTrainerId'] ?? null)) !== $trainerRef) {
			return $notYours;
		}

		$state = $this->text(value: ($agreement['lifecycle'] ?? 'draft'));
		if (in_array($state, self::SIGNABLE_STATES, true) === false) {
			return new PortalOutcome(status: 409, body: ['error' => 'not_open_for_signing', 'lifecycle' => $state], reason: 'not-open-for-signing');
		}

		return null;
	}//end agreementRefusal()

	/**
	 * Whether she already signed this version of the agreement.
	 *
	 * @param string $trainerRef  The trainer's uuid.
	 * @param string $agreementId The agreement's uuid.
	 * @param int    $version     The agreement's current version.
	 *
	 * @return bool
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function alreadySigned(string $trainerRef, string $agreementId, int $version): bool {
		$objects = $this->objectService->findAll(
			config: [
				'filters' => [
					'register' => self::REGISTER,
					'schema' => self::SIGNATURE_SCHEMA,
					'subjectId' => $agreementId,
					'signerId' => $trainerRef,
				],
			],
			_rbac: false,
			_multitenancy: false
		);

		foreach ($objects as $object) {
			$row = $this->toRow(object: $object);
			if ($this->text(value: ($row['signerRole'] ?? null)) === self::SIGNER_ROLE
				&& (int)($row['subjectVersion'] ?? 0) === $version
				&& $this->text(value: ($row['signerId'] ?? null)) === $trainerRef
				&& $this->text(value: ($row['subjectId'] ?? null)) === $agreementId
			) {
				return true;
			}
		}

		return false;
	}//end alreadySigned()

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
}//end class
