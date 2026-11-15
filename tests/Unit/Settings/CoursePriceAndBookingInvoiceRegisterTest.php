<?php

/**
 * A course carries its price and a booking the reference and status of its
 * shillinq invoice (academy-invoices-on-the-portal).
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/academy-invoices-on-the-portal/specs/portal-contribution/spec.md#requirement-a-course-carries-its-price
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates course and booking payloads against the shipped fragments.
 */
class CoursePriceAndBookingInvoiceRegisterTest extends TestCase {

	/**
	 * Whether a payload passes a shipped schema.
	 *
	 * @param string               $schema  The schema's key.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool
	 */
	private function passes(string $schema, array $payload): bool {
		$schemas = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true)['components']['schemas'];
		$fragment = ['type' => 'object', 'properties' => array_map(fn (array $property): array => $this->validatable(schema: $property), $schemas[$schema]['properties'])];

		return (new Validator())->validate(json_decode((string)json_encode($payload)), (string)json_encode($fragment))->isValid();
	}//end passes()

	/**
	 * A price excluding VAT with a rate and a note passes; a negative amount,
	 * a price without an amount and a rate over 100 do not.
	 *
	 * @return void
	 */
	public function testACourseCarriesItsPrice(): void {
		$course = ['price' => ['amount' => 495, 'currency' => 'EUR'], 'vatRate' => 21, 'priceNote' => 'met examen en lunch'];
		self::assertTrue($this->passes('Course', $course));
		self::assertTrue($this->passes('Course', ['name' => 'F-gassen']), 'a course without a price');
		self::assertFalse($this->passes('Course', array_merge($course, ['price' => ['amount' => -1]])), 'control: no negative price');
		self::assertFalse($this->passes('Course', array_merge($course, ['price' => ['currency' => 'EUR']])), 'control: a price needs its amount');
		self::assertFalse($this->passes('Course', array_merge($course, ['price' => ['amount' => 495, 'currency' => 'euro']])), 'control: an ISO currency code');
		self::assertFalse($this->passes('Course', array_merge($course, ['vatRate' => 121])), 'control: a rate is a percentage');
	}//end testACourseCarriesItsPrice()

	/**
	 * A booking holds only the invoice's reference and one of four statuses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/academy-invoices-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-reads-the-invoices-of-her-bookings
	 */
	public function testABookingHoldsItsInvoiceReferenceAndStatus(): void {
		foreach (['open', 'paid', 'overdue', 'credited'] as $status) {
			self::assertTrue($this->passes('CourseBooking', ['invoiceRef' => 'F-2026-1184', 'invoiceStatus' => $status]), $status);
		}

		self::assertFalse($this->passes('CourseBooking', ['invoiceStatus' => 'betaald']), 'control: a status outside the four');
	}//end testABookingHoldsItsInvoiceReferenceAndStatus()

	/**
	 * A property without OpenRegister's own keys, which a JSON Schema validator cannot resolve.
	 *
	 * @param array<string, mixed> $schema A property schema.
	 *
	 * @return array<string, mixed>
	 */
	private function validatable(array $schema): array {
		$clean = [];
		foreach ($schema as $key => $value) {
			if ($key === '$ref' || $key === 'nullable' || str_starts_with((string)$key, 'x-') === true) {
				continue;
			}

			$clean[$key] = $value;
			if (is_array($value) === true && $key !== 'required' && $key !== 'enum') {
				$clean[$key] = $this->validatable(schema: $value);
			}
		}

		return $clean;
	}//end validatable()
}//end class
