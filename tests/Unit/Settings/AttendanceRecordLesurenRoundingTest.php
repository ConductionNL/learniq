<?php

/**
 * Learniq AttendanceRecord lesuren rounding tests.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
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
 * @spec openspec/specs/attendance/spec.md#requirement-persist-attendance-domain-objects-in-openregister
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use OCA\Learniq\Tests\Support\RegisterSchemaPayloads;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The materialised lesuren of an attendance record fits a stored number.
 *
 * A stored number keeps at most six decimals; OpenRegister refuses a save whose
 * value needs more. `minutesAttended / 60` gives 0.41666... for 25 minutes, so
 * without rounding every record of an odd minute count was refused (199 of the
 * po example set's attendance records on a PostgreSQL instance).
 */
class AttendanceRecordLesurenRoundingTest extends TestCase {
	use RegisterSchemaPayloads;

	/**
	 * The shipped lesuren calculation.
	 *
	 * @return array<string, mixed>
	 */
	private static function calculation(): array {
		$schema = self::shippedSchema(slug: 'attendance-record');

		return $schema['x-openregister-calculations']['lesuren'];
	}//end calculation()

	/**
	 * Evaluates the operators the lesuren expression uses, as OpenRegister does.
	 *
	 * @param mixed                $node   An expression node.
	 * @param array<string, mixed> $object The record, with `@ref.session.durationMinutes` flattened.
	 *
	 * @return mixed
	 */
	private static function evaluate(mixed $node, array $object): mixed {
		if (is_array($node) === false || array_is_list($node) === true) {
			return $node;
		}

		$operator = (string)array_key_first($node);
		$args     = $node[$operator];

		return match ($operator) {
			'prop'  => ($object[$args] ?? null),
			'eq'    => self::evaluate(node: $args[0], object: $object) === self::evaluate(node: $args[1], object: $object),
			'or'    => in_array(true, array_map(fn ($arg) => self::evaluate(node: $arg, object: $object), $args), true),
			'if'    => (self::evaluate(node: $args[0], object: $object) === true) ? self::evaluate(node: $args[1], object: $object) : self::evaluate(node: $args[2], object: $object),
			'/'     => self::evaluate(node: $args[0], object: $object) / self::evaluate(node: $args[1], object: $object),
			'round' => round((float)self::evaluate(node: $args[0], object: $object), (int)($args[1] ?? 0)),
			default => throw new RuntimeException('Operator ' . $operator . ' is not expected in lesuren.'),
		};
	}//end evaluate()

	/**
	 * The expression rounds its outcome to four decimals.
	 *
	 * @return void
	 */
	public function testTheLesurenAreRoundedToFourDecimals(): void {
		$calculation = self::calculation();

		self::assertTrue($calculation['materialise']);
		self::assertSame(['round'], array_keys($calculation['expression']));
		self::assertSame(4, $calculation['expression']['round'][1]);
	}//end testTheLesurenAreRoundedToFourDecimals()

	/**
	 * Every minute count from 1 to 300, attended or absent, gives at most six decimals.
	 *
	 * @return void
	 */
	public function testEveryMinuteCountFitsAStoredNumber(): void {
		$expression = self::calculation()['expression'];

		foreach (['present', 'late', 'left-early', 'absent-unexcused', 'absent-excused'] as $status) {
			for ($minutes = 1; $minutes <= 300; $minutes++) {
				$value    = self::evaluate(
					node: $expression,
					object: ['status' => $status, 'minutesAttended' => $minutes, '@ref.session.durationMinutes' => $minutes]
				);
				$decimals = strlen((string)substr(strrchr(rtrim(sprintf('%.12F', $value), '0'), '.'), 1));
				self::assertLessThanOrEqual(6, $decimals, $status . ' ' . $minutes . ' minutes gave ' . $value);
			}
		}
	}//end testEveryMinuteCountFitsAStoredNumber()

	/**
	 * Rounding keeps the hours: 25 minutes is 0.4167, a full 50-minute lesson absent is 0.8333.
	 *
	 * @return void
	 */
	public function testRoundingKeepsTheHours(): void {
		$expression = self::calculation()['expression'];

		self::assertSame(0.4167, self::evaluate(node: $expression, object: ['status' => 'late', 'minutesAttended' => 25]));
		self::assertSame(1.0, self::evaluate(node: $expression, object: ['status' => 'present', 'minutesAttended' => 60]));
		self::assertSame(
			0.8333,
			self::evaluate(node: $expression, object: ['status' => 'absent-unexcused', '@ref.session.durationMinutes' => 50])
		);
	}//end testRoundingKeepsTheHours()
}//end class
