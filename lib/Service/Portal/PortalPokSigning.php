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
use OCA\Learniq\Service\Portal\Support\TrainerPortalWrite;
use Throwable;

/**
 * Checks, stamps and stores one portal POK signature.
 *
 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
 */
class PortalPokSigning extends TrainerPortalWrite implements PortalPokSigningInterface {

	use AssessmentRowValues;

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
		$assurance = $this->assuranceOf(trust: $trust);
		$refusal = $this->belowFloor(assurance: $assurance, floor: $this->floor());
		if ($refusal !== null) {
			return $refusal;
		}

		$answers = new PortalAnswers();
		$agreementId = $answers->text(field: 'subjectId', value: ($body['subjectId'] ?? null));
		if ($trainerRef === '' || $agreementId === '') {
			return $answers->refused(error: 'incomplete', reason: 'incomplete');
		}

		try {
			$agreement = $this->row(schema: self::AGREEMENT_SCHEMA, id: $agreementId);
			if ($agreement === null) {
				return $this->notYours();
			}

			$refusal = $this->agreementRefusal(trainerRef: $trainerRef, agreement: $agreement);
			if ($refusal !== null) {
				return $refusal;
			}

			if ($this->alreadySigned(trainerRef: $trainerRef, agreement: $agreement) === true) {
				return new PortalOutcome(status: 409, body: ['error' => 'already_signed'], reason: 'already-signed');
			}

			$signature = $this->signature(trainerRef: $trainerRef, agreement: $agreement);
			$signature['assuranceLevel'] = $assurance;
			$row = $this->store(schema: self::SIGNATURE_SCHEMA, object: $signature);
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PortalPokSigning] A portal POK signature could not be stored: {msg}',
				['msg' => $exception->getMessage(), 'exception' => $exception]
			);
			return new PortalOutcome(status: 502, body: ['error' => 'downstream_error'], reason: 'downstream');
		}//end try

		return new PortalOutcome(
			status: 201,
			body: [
				'signatureId' => (string)($row['id'] ?? ($row['uuid'] ?? '')),
				'assuranceLevel' => $assurance,
				'signedAt' => $signature['signedAt'],
			]
		);
	}//end sign()

	/**
	 * The signature as it is stored, all of it the server's: the agreement's
	 * current version, the trainer as signer in her own role, the moment,
	 * the method and the agreement's tenant. The caller adds the assurance.
	 *
	 * @param string               $trainerRef The trainer's uuid.
	 * @param array<string, mixed> $agreement  The Praktijkovereenkomst row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function signature(string $trainerRef, array $agreement): array {
		return [
			'subjectId' => $this->text(value: ($agreement['id'] ?? null)),
			'subjectVersion' => (int)($agreement['version'] ?? 1),
			'signerId' => $trainerRef,
			'signerRole' => self::SIGNER_ROLE,
			'signedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
			'method' => self::METHOD,
			'tenant_id' => $this->text(value: ($agreement['tenant_id'] ?? null)),
		];
	}//end signature()

	/**
	 * Why this agreement may not be signed by this trainer: she is unknown,
	 * it no longer takes signatures, or its placement is not hers.
	 *
	 * @param string               $trainerRef The trainer's uuid.
	 * @param array<string, mixed> $agreement  The Praktijkovereenkomst row.
	 *
	 * @return PortalOutcome|null Null when she may sign it.
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function agreementRefusal(string $trainerRef, array $agreement): ?PortalOutcome {
		if ($this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef) === null) {
			return $this->notYours();
		}

		$placementId = $this->text(value: ($agreement['bpvPlacementId'] ?? null));
		$placement = null;
		if ($placementId !== '') {
			$placement = $this->row(schema: self::PLACEMENT_SCHEMA, id: $placementId);
		}

		if ($placement === null || $this->text(value: ($placement['practicalTrainerId'] ?? null)) !== $trainerRef) {
			return $this->notYours();
		}

		$state = $this->text(value: ($agreement['lifecycle'] ?? 'draft'));
		if (in_array($state, self::SIGNABLE_STATES, true) === false) {
			return new PortalOutcome(status: 409, body: ['error' => 'not_open_for_signing', 'lifecycle' => $state], reason: 'not-open-for-signing');
		}

		return null;
	}//end agreementRefusal()

	/**
	 * The one refusal for an agreement that is unknown or not hers, so the
	 * answer does not tell her which agreements exist.
	 *
	 * @return PortalOutcome
	 */
	private function notYours(): PortalOutcome {
		return new PortalOutcome(status: 403, body: ['error' => 'not_your_placement'], reason: 'not-your-placement');
	}//end notYours()

	/**
	 * Whether she already signed this version of the agreement.
	 *
	 * @param string               $trainerRef The trainer's uuid.
	 * @param array<string, mixed> $agreement  The Praktijkovereenkomst row.
	 *
	 * @return bool
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 */
	private function alreadySigned(string $trainerRef, array $agreement): bool {
		$agreementId = $this->text(value: ($agreement['id'] ?? null));
		$version = (int)($agreement['version'] ?? 1);
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
			$row = $this->objectRow->asArray(object: $object);
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
}//end class
