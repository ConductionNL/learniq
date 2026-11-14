<?php

/**
 * Unit tests for the refusal exceptions: status, machine reason and user message kept apart.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Exception;

use OCA\Learniq\Exception\CourseTemplateRefusedException;
use OCA\Learniq\Exception\ExchangeRequestRefusedException;
use OCA\Learniq\Exception\IntegriqUnavailableException;
use OCA\Learniq\Exception\MaterialImportRefusedException;
use OCA\Learniq\Exception\SigningRefusedException;
use OCA\Learniq\Exception\XapiRequestException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Learniq\Exception\CourseTemplateRefusedException
 * @covers \OCA\Learniq\Exception\ExchangeRequestRefusedException
 * @covers \OCA\Learniq\Exception\IntegriqUnavailableException
 * @covers \OCA\Learniq\Exception\MaterialImportRefusedException
 * @covers \OCA\Learniq\Exception\SigningRefusedException
 * @covers \OCA\Learniq\Exception\XapiRequestException
 */
class RefusalExceptionsTest extends TestCase {

	/**
	 * A course-template refusal keeps the HTTP status, the machine reason and
	 * the human message apart; the HTTP status is not the exception code.
	 *
	 * @return void
	 */
	public function testACourseTemplateRefusalKeepsStatusReasonAndMessageApart(): void {
		$exception = new CourseTemplateRefusedException(status: 409, reason: 'template_in_use', message: 'The template is in use.');

		self::assertSame(409, $exception->getStatus());
		self::assertSame('template_in_use', $exception->getReason());
		self::assertSame('The template is in use.', $exception->getMessage());
		self::assertSame(0, $exception->getCode());
		self::assertNull($exception->getPrevious());
	}//end testACourseTemplateRefusalKeepsStatusReasonAndMessageApart()

	/**
	 * A signing refusal keeps the HTTP status, the machine reason and the human message apart.
	 *
	 * @return void
	 */
	public function testASigningRefusalKeepsStatusReasonAndMessageApart(): void {
		$exception = new SigningRefusedException(status: 403, reason: 'not_signer', message: 'You may not sign this.');

		self::assertSame(403, $exception->getStatus());
		self::assertSame('not_signer', $exception->getReason());
		self::assertSame('You may not sign this.', $exception->getMessage());
		self::assertSame(0, $exception->getCode());
	}//end testASigningRefusalKeepsStatusReasonAndMessageApart()

	/**
	 * An exchange refusal carries its code separately from the explanation.
	 *
	 * @return void
	 */
	public function testAnExchangeRefusalCarriesItsCodeAndExplanation(): void {
		$exception = new ExchangeRequestRefusedException(refusalCode: 'partner_unknown', reason: 'The partner school is not known.');

		self::assertSame('partner_unknown', $exception->getRefusalCode());
		self::assertSame('The partner school is not known.', $exception->getMessage());
	}//end testAnExchangeRefusalCarriesItsCodeAndExplanation()

	/**
	 * A material-import refusal carries its code separately from the explanation.
	 *
	 * @return void
	 */
	public function testAMaterialImportRefusalCarriesItsCodeAndExplanation(): void {
		$exception = new MaterialImportRefusedException(reasonCode: 'too_large', reason: 'The file is too large.');

		self::assertSame('too_large', $exception->getReasonCode());
		self::assertSame('The file is too large.', $exception->getMessage());
	}//end testAMaterialImportRefusalCarriesItsCodeAndExplanation()

	/**
	 * An xAPI failure carries the upstream status, not as the exception code.
	 *
	 * @return void
	 */
	public function testAnXapiFailureCarriesTheUpstreamStatus(): void {
		$exception = new XapiRequestException(status: 502, message: 'The learning record store did not answer.');

		self::assertSame(502, $exception->getStatus());
		self::assertSame('The learning record store did not answer.', $exception->getMessage());
		self::assertSame(0, $exception->getCode());
	}//end testAnXapiFailureCarriesTheUpstreamStatus()

	/**
	 * Every refusal is caught by a caller that catches RuntimeException.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsARuntimeException(): void {
		$refusals = [
			new CourseTemplateRefusedException(status: 400, reason: 'r', message: 'm'),
			new SigningRefusedException(status: 400, reason: 'r', message: 'm'),
			new ExchangeRequestRefusedException(refusalCode: 'c', reason: 'm'),
			new MaterialImportRefusedException(reasonCode: 'c', reason: 'm'),
			new XapiRequestException(status: 500, message: 'm'),
			new IntegriqUnavailableException('Integriq is not installed.'),
		];

		foreach ($refusals as $refusal) {
			self::assertInstanceOf(RuntimeException::class, $refusal);
		}

		self::assertSame('Integriq is not installed.', $refusals[5]->getMessage());
	}//end testEveryRefusalIsARuntimeException()
}//end class
