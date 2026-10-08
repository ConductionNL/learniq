<?php

/**
 * A certificate names its holder, its course, its employer, its end and its renewal.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/changes/portal-certificates/specs/portal-contribution/spec.md#requirement-a-certificate-names-its-holder-its-course-and-its-renewal
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\CertificateCopies;
use PHPUnit\Framework\TestCase;

/**
 * CertificateCopies, and the expiry line OpenRegister computes.
 */
class CertificateCopiesTest extends TestCase {

	/**
	 * The training set's objects.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function set(): array {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/profiles/training.json'), true);

		return $set['x-openregister']['seedData']['objects'];
	}//end set()

	/**
	 * CertificateCopies over the training set's rows.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $objects The rows.
	 *
	 * @return CertificateCopies
	 */
	private static function copies(array $objects): CertificateCopies {
		$index = [];
		foreach ($objects as $schema => $rows) {
			$index[$schema] = array_column($rows, null, 'uuid');
		}

		return new CertificateCopies(
			rows: static fn (string $schema, mixed $id): ?array => ($index[$schema][(string)$id] ?? null),
			sessions: static fn (string $cohortId): array => array_values(array_filter($objects['session'], static fn (array $s): bool => $s['cohortId'] === $cohortId))
		);
	}//end copies()

	/**
	 * Tom's F-gassen certificate reads as the board draws it.
	 *
	 * @return void
	 */
	public function testTomsCertificateReadsAsTheBoard(): void {
		$objects = self::set();
		$tom = array_values(array_filter($objects['credential'], static fn (array $c): bool => ($c['learnerName'] ?? '') === 'Tom Verbeek'))[0];

		self::assertSame(
			[
				'learnerName' => 'Tom Verbeek',
				'courseName' => 'F-gassen categorie 1',
				'organisationRef' => 'ee06001f-0000-4000-8000-000000000001',
				'validUntilLabel' => 'Geldig tot 30 november 2026',
				'renewalLine' => 'Herhaling op 8 oktober',
			],
			self::copies(objects: $objects)->derive(credential: $tom)
		);
	}//end testTomsCertificateReadsAsTheBoard()

	/**
	 * Every seeded certificate of the training set carries exactly the copies the server writes.
	 *
	 * @return void
	 */
	public function testTheSeededCertificatesAgreeWithTheServer(): void {
		$objects = self::set();
		$copies = self::copies(objects: $objects);
		foreach ($objects['credential'] as $credential) {
			foreach ($copies->derive(credential: $credential) as $field => $value) {
				self::assertSame($value, ($credential[$field] ?? null), $credential['slug'] . ' ' . $field);
			}
		}
	}//end testTheSeededCertificatesAgreeWithTheServer()

	/**
	 * A renewal that is withdrawn, or no holder at all, leaves its line empty.
	 *
	 * @return void
	 */
	public function testAWithdrawnRenewalAndAnUnknownHolderLeaveTheirLinesEmpty(): void {
		$copies = new CertificateCopies(
			rows: static fn (string $schema, mixed $id): ?array => ['enrolment' => ['e-1' => ['lifecycle' => 'withdrawn', 'cohortId' => 'c-1']]][$schema][(string)$id] ?? null,
			sessions: static fn (string $cohortId): array => [['startsAt' => '2026-10-08T08:30:00+02:00']]
		);

		$out = $copies->derive(credential: ['learnerId' => 'nobody', 'renewalEnrolmentId' => 'e-1', 'expiresAt' => null]);
		self::assertSame(['learnerName' => null, 'courseName' => null, 'organisationRef' => null, 'validUntilLabel' => null, 'renewalLine' => null], $out);
	}//end testAWithdrawnRenewalAndAnUnknownHolderLeaveTheirLinesEmpty()

	/**
	 * The expiry line the register asks OpenRegister to compute, evaluated
	 * with the operators it uses. Checked once against OpenRegister's own
	 * CalculationEvaluator too (portal-certificates tasks).
	 *
	 * @return void
	 */
	public function testTheExpiryLineReadsInWeeks(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$expression = $register['components']['schemas']['Credential']['x-openregister-calculations']['expiryLabel']['expression'];
		$cases = [
			['expiring', 8, 'Verloopt over 8 weken'],
			['expiring-soon', 1, 'Verloopt over 1 week'],
			['expiring-soon', 0, 'Verloopt deze week'],
			['valid', 74, 'Geldig'],
			['none', null, 'Geldig'],
			['expired', -4, 'Verlopen'],
		];
		foreach ($cases as [$status, $weeks, $expected]) {
			self::assertSame($expected, self::evaluate(node: $expression, row: ['expiryStatus' => $status, 'weeksUntilExpiry' => $weeks]), $status . ' ' . $weeks);
		}
	}//end testTheExpiryLineReadsInWeeks()

	/**
	 * The operators the expiry line uses: if, eq, or, lte, concat, prop, literals.
	 *
	 * @param mixed                $node The expression.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return mixed
	 */
	private static function evaluate(mixed $node, array $row): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		$operator = array_key_first($node);
		$args = $node[$operator];
		$value = static fn (mixed $arg): mixed => self::evaluate(node: $arg, row: $row);

		return match ($operator) {
			'prop' => ($row[$args] ?? null),
			'if' => $value($args[0]) === true ? $value($args[1]) : $value(($args[2] ?? null)),
			'eq' => $value($args[0]) === $value($args[1]),
			'or' => in_array(true, array_map($value, $args), true),
			'lte' => $value($args[0]) !== null && $value($args[0]) <= $value($args[1]),
			'concat' => implode('', array_map(static fn (mixed $arg): string => (string)$value($arg), $args)),
		};
	}//end evaluate()
}//end class
