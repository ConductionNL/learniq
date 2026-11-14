<?php

/**
 * Learniq FrozenAttestationLines unit test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Service;

use JsonSerializable;
use OCA\Learniq\Service\FrozenAttestationLines;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * The audit pack lists every signed or revoked attestation as frozen.
 *
 * @covers \OCA\Learniq\Service\FrozenAttestationLines
 */
class FrozenAttestationLinesTest extends TestCase {

	/**
	 * The queries the object service received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $queries = [];

	/**
	 * The lines for a set of stored attestations.
	 *
	 * @param array<int,mixed> $rows What the object service returns.
	 *
	 * @return array<int,string>
	 */
	private function linesFor(array $rows): array {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $query) use ($rows): array {
				$this->queries[] = $query;
				return $rows;
			}
		);

		return (new FrozenAttestationLines(objectService: $objects))->lines(regulationSlug: 'NIS2', tenantId: 'tenant-1');
	}//end linesFor()

	/**
	 * Scenario: an auditor opens the pack with two signed attestations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-attestation-locked-after-signing/specs/compliance-audit/spec.md#scenario-an-auditor-opens-the-pack
	 */
	public function testEachSignedAttestationShowsItsSignedAtAndTheFreeze(): void {
		$lines = $this->linesFor(
			rows: [
				['id' => 'at-2', 'learnerId' => 'l-2', 'lifecycle' => 'signed', 'signedAt' => '2026-10-05T09:00:00+00:00'],
				['id' => 'at-1', 'learnerId' => 'l-1', 'lifecycle' => 'signed', 'signedAt' => '2026-10-02T09:00:00+00:00'],
			]
		);

		self::assertSame(
			expected: [
				'Attestation at-1 | learner l-1 | state signed | signedAt 2026-10-02T09:00:00+00:00 | frozen since signing',
				'Attestation at-2 | learner l-2 | state signed | signedAt 2026-10-05T09:00:00+00:00 | frozen since signing',
			],
			actual: $lines
		);
	}//end testEachSignedAttestationShowsItsSignedAtAndTheFreeze()

	/**
	 * Only the regulation's attestations of the caller's tenant are asked for.
	 *
	 * @return void
	 */
	public function testTheQueryIsScopedToRegulationAndTenant(): void {
		$this->linesFor(rows: []);

		self::assertSame(
			expected: [
				[
					'filters' => [
						'register' => 'learniq',
						'schema' => 'attestation',
						'regulationSlug' => 'NIS2',
						'tenant_id' => 'tenant-1',
					],
				],
			],
			actual: $this->queries
		);
	}//end testTheQueryIsScopedToRegulationAndTenant()

	/**
	 * A revoked attestation is listed too; a drafted one is not evidence yet.
	 *
	 * @return void
	 */
	public function testRevokedIsListedAndDraftedIsLeftOut(): void {
		$lines = $this->linesFor(
			rows: [
				['id' => 'at-d', 'learnerId' => 'l-3', 'lifecycle' => 'drafted'],
				['id' => 'at-r', 'learnerId' => 'l-4', 'lifecycle' => 'revoked', 'signedAt' => '2026-10-01T09:00:00+00:00'],
				['id' => 'at-x', 'learnerId' => 'l-5'],
			]
		);

		self::assertSame(
			expected: ['Attestation at-r | learner l-4 | state revoked | signedAt 2026-10-01T09:00:00+00:00 | frozen since signing'],
			actual: $lines
		);
	}//end testRevokedIsListedAndDraftedIsLeftOut()

	/**
	 * An entity row is read through jsonSerialize, its id from @self, and a
	 * missing signedAt is named rather than left blank.
	 *
	 * @return void
	 */
	public function testAnEntityRowWithoutSignedAtIsNamed(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * The serialised attestation.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'at-e'], 'learnerId' => 'l-6', 'lifecycle' => 'signed'];
			}
		};

		self::assertSame(
			expected: ['Attestation at-e | learner l-6 | state signed | signedAt not recorded | frozen since signing'],
			actual: $this->linesFor(rows: [$entity, 'not-an-object'])
		);
	}//end testAnEntityRowWithoutSignedAtIsNamed()
}//end class
