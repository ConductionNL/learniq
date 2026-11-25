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
			$agreement = $this->signableAgreement(trainerRef: $trainerRef, agreementId: $agreementId);
			if ($agreement instanceof PortalOutcome) {
				return $agreement;
			}

			$signature = array_merge($this->signature(trainerRef: $trainerRef, agreement: $agreement), ['assuranceLevel' => $assurance]);
			$row = $this->store(schema: self::SIGNATURE_SCHEMA, object: $signature);
		} catch (Throwable $exception) {
			return $this->downstream(exception: $exception);
		}

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
	 * The agreement she may sign now, or why not: it is unknown or not of her
	 * own placement (one answer for both, so it does not tell her which
	 * agreements exist), it no longer takes signatures, or she already signed
	 * this version.
	 *
	 * @param string $trainerRef  The trainer's uuid.
	 * @param string $agreementId The agreement the form names.
	 *
	 * @return array<string, mixed>|PortalOutcome
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function signableAgreement(string $trainerRef, string $agreementId): array|PortalOutcome {
		$agreement = $this->row(schema: self::AGREEMENT_SCHEMA, id: $agreementId);
		if ($agreement === null || $this->isHers(trainerRef: $trainerRef, agreement: $agreement) === false) {
			return new PortalOutcome(status: 403, body: ['error' => 'not_your_placement'], reason: 'not-your-placement');
		}

		$state = $this->text(value: ($agreement['lifecycle'] ?? 'draft'));
		if (in_array($state, self::SIGNABLE_STATES, true) === false) {
			return new PortalOutcome(status: 409, body: ['error' => 'not_open_for_signing', 'lifecycle' => $state], reason: 'not-open-for-signing');
		}

		$mine = $this->rows(
			schema: self::SIGNATURE_SCHEMA,
			config: ['filters' => ['subjectId' => $agreementId, 'signerId' => $trainerRef]]
		);
		foreach ($mine as $signature) {
			if ($this->signs(signature: $signature, agreement: $agreement) === true) {
				return new PortalOutcome(status: 409, body: ['error' => 'already_signed'], reason: 'already-signed');
			}
		}

		return $agreement;
	}//end signableAgreement()

	/**
	 * Whether the agreement belongs to a placement this trainer supervises,
	 * and the trainer herself is known.
	 *
	 * @param string               $trainerRef The trainer's uuid.
	 * @param array<string, mixed> $agreement  The Praktijkovereenkomst row.
	 *
	 * @return bool
	 *
	 * @throws \Throwable When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function isHers(string $trainerRef, array $agreement): bool {
		$placementId = $this->text(value: ($agreement['bpvPlacementId'] ?? null));
		if ($placementId === '' || $this->row(schema: self::TRAINER_SCHEMA, id: $trainerRef) === null) {
			return false;
		}

		$placement = $this->row(schema: self::PLACEMENT_SCHEMA, id: $placementId);

		return $this->text(value: ($placement['practicalTrainerId'] ?? null)) === $trainerRef;
	}//end isHers()

	/**
	 * Whether a stored signature is her praktijkopleider signature on this
	 * version of the agreement.
	 *
	 * @param array<string, mixed> $signature A PokSignature row of hers.
	 * @param array<string, mixed> $agreement The Praktijkovereenkomst row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/an-invited-trainer-may-activate-a-placement/specs/bpv/spec.md#requirement-an-invited-trainer-may-sign-a-praktijkovereenkomst
	 */
	private function signs(array $signature, array $agreement): bool {
		return $this->text(value: ($signature['signerRole'] ?? null)) === self::SIGNER_ROLE
			&& (int)($signature['subjectVersion'] ?? 0) === (int)($agreement['version'] ?? 1);
	}//end signs()

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
}//end class
