<?php

/**
 * Learniq Frozen Attestation Lines
 *
 * Lists the signed and revoked attestations of one regulation and tenant as
 * the lines the audit pack's signature-verification.txt shows, each with its
 * signedAt and the statement that its evidence fields are frozen since then.
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
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-the-audit-pack-states-the-freeze
 */

declare(strict_types=1);

namespace OCA\Learniq\Service;

use OCA\OpenRegister\Service\ObjectService;

/**
 * Renders the signed and revoked attestations of a regulation as report lines.
 *
 * A signed or revoked attestation cannot change (AttestationFreezeListener),
 * so the pack states that for every such line. A drafted one is not evidence
 * yet and is left out.
 *
 * @psalm-api
 *
 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#requirement-the-audit-pack-states-the-freeze
 */
class FrozenAttestationLines {

	/**
	 * The states whose evidence fields are frozen.
	 *
	 * @var array<int,string>
	 */
	private const FROZEN_STATES = ['signed', 'revoked'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OR object service for the attestation query.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {

	}//end __construct()

	/**
	 * The report lines of the frozen attestations, earliest signedAt first.
	 *
	 * @param string $regulationSlug Regulation slug the pack is for.
	 * @param string $tenantId       Tenant scope, so other tenants are never listed.
	 *
	 * @return array<int,string> One line per signed or revoked attestation.
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#scenario-an-auditor-opens-the-pack
	 */
	public function lines(string $regulationSlug, string $tenantId): array {
		$frozen = [];
		foreach ($this->attestations(regulationSlug: $regulationSlug, tenantId: $tenantId) as $row) {
			$attestation = $this->normalise(row: $row);
			$state       = (string)($attestation['lifecycle'] ?? '');
			if (in_array($state, self::FROZEN_STATES, true) === false) {
				continue;
			}

			$frozen[] = $attestation;
		}

		usort(
			$frozen,
			static fn (array $a, array $b): int => strcmp((string)($a['signedAt'] ?? ''), (string)($b['signedAt'] ?? ''))
		);

		return array_map(fn (array $attestation): string => $this->lineOf(attestation: $attestation), $frozen);
	}//end lines()

	/**
	 * The attestations of one regulation and tenant, any state.
	 *
	 * @param string $regulationSlug Regulation slug.
	 * @param string $tenantId       Tenant scope.
	 *
	 * @return array<int,mixed>
	 */
	private function attestations(string $regulationSlug, string $tenantId): array {
		return $this->objectService->findAll(
			[
				'filters' => [
					'register' => 'learniq',
					'schema' => 'attestation',
					'regulationSlug' => $regulationSlug,
					'tenant_id' => $tenantId,
				],
			]
		);
	}//end attestations()

	/**
	 * One attestation as a report line.
	 *
	 * @param array<string,mixed> $attestation The normalised attestation.
	 *
	 * @return string
	 */
	private function lineOf(array $attestation): string {
		$id       = (string)($attestation['@self']['id'] ?? ($attestation['id'] ?? ($attestation['uuid'] ?? '')));
		$signedAt = (string)($attestation['signedAt'] ?? '');
		if ($signedAt === '') {
			$signedAt = 'not recorded';
		}

		return sprintf(
			'Attestation %s | learner %s | state %s | signedAt %s | frozen since signing',
			$id,
			(string)($attestation['learnerId'] ?? ''),
			(string)($attestation['lifecycle'] ?? ''),
			$signedAt
		);
	}//end lineOf()

	/**
	 * One row as a plain array; the object service may return entities or arrays.
	 *
	 * @param mixed $row One row as returned by the OR object service.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end normalise()
}//end class
