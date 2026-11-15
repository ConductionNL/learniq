<?php

/**
 * Tests that an employer only writes for a company of her portal's organisation.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/employer-portal-audience/specs/portal-contribution/spec.md#requirement-an-employer-books-places-and-names-her-participants
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalEmployerController;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The company named by the claim must lie in the organisation the assertion was signed for.
 *
 * @covers \OCA\Learniq\Controller\PortalEmployerController
 * @uses   \OCA\Learniq\Service\Portal\PortalOutcome
 */
class PortalEmployerControllerTenancyTest extends TestCase {

	/**
	 * Every tenancy question: company and portal organisation.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $asked = [];

	/**
	 * Every write that reached the bookings: method and company.
	 *
	 * @var list<array{0: string, 1: string}>
	 */
	private array $writes = [];

	/**
	 * The audience the next assertion is signed for.
	 *
	 * @var string
	 */
	private string $audience = 'employer';

	/**
	 * An assertion for school-a naming school-b's company is refused before any write; school-a's own company writes.
	 *
	 * @return void
	 */
	public function testEveryWriteChecksTheCompanyAgainstThePortalsOrganisation(): void {
		foreach (['book', 'addParticipant', 'supplyBirthDate'] as $method) {
			$foreign = $this->controller(company: ' co-b ')->{$method}();
			self::assertSame(expected: [403, ['error' => 'forbidden']], actual: [$foreign->getStatus(), $foreign->getData()], message: $method);
			$own = $this->controller(company: 'co-a')->{$method}();
			self::assertSame(expected: 201, actual: $own->getStatus(), message: $method);
		}

		self::assertSame(expected: [['book', 'co-a'], ['addParticipant', 'co-a'], ['supplyBirthDate', 'co-a']], actual: $this->writes);
		self::assertSame(
			expected: ['school-a'],
			actual: array_values(array_unique(array_column($this->asked, 1))),
			message: 'the organisation comes from the signed assertion'
		);
		self::assertSame(expected: ['co-b', 'co-a'], actual: array_slice(array_column($this->asked, 0), 0, 2), message: 'the company is checked trimmed');
	}//end testEveryWriteChecksTheCompanyAgainstThePortalsOrganisation()

	/**
	 * An assertion that names no organisation is asked about as '' and refused.
	 *
	 * @return void
	 */
	public function testAnAssertionWithoutAnOrganisationIsRefused(): void {
		$response = $this->controller(company: 'co-a', organisation: null)->book();

		self::assertSame(expected: 403, actual: $response->getStatus());
		self::assertSame(expected: [['co-a', '']], actual: $this->asked);
		self::assertSame(expected: [], actual: $this->writes);
	}//end testAnAssertionWithoutAnOrganisationIsRefused()

	/**
	 * The body must name the company the assertion itself was signed for; a body naming another, or an assertion without one, is refused.
	 *
	 * @return void
	 */
	public function testTheBodyMustNameTheAssertionsOwnCompany(): void {
		foreach (['book', 'addParticipant', 'supplyBirthDate'] as $method) {
			$other = $this->controller(company: 'co-a2', claim: 'co-a')->{$method}();
			self::assertSame(expected: [403, ['error' => 'forbidden']], actual: [$other->getStatus(), $other->getData()], message: $method);
			$unsigned = $this->controller(company: 'co-a', claim: null)->{$method}();
			self::assertSame(expected: 403, actual: $unsigned->getStatus(), message: $method);
			$blank = $this->controller(company: 'co-a', claim: ' ')->{$method}();
			self::assertSame(expected: 403, actual: $blank->getStatus(), message: $method);
			$padded = $this->controller(company: 'co-a', claim: ' co-a ')->{$method}();
			self::assertSame(expected: 201, actual: $padded->getStatus(), message: $method);
		}

		self::assertSame(expected: [['book', 'co-a'], ['addParticipant', 'co-a'], ['supplyBirthDate', 'co-a']], actual: $this->writes);
		self::assertSame(expected: ['co-a', 'co-a', 'co-a'], actual: array_column($this->asked, 0), message: 'a refused binding asks no tenancy question');
	}//end testTheBodyMustNameTheAssertionsOwnCompany()

	/**
	 * A blank company on both sides, and an assertion for another audience, are refused before any tenancy question.
	 *
	 * @return void
	 */
	public function testABlankCompanyAndAnotherAudienceAreRefused(): void {
		$blank = $this->controller(company: ' ', claim: ' ')->book();
		self::assertSame(expected: 403, actual: $blank->getStatus());

		$this->audience = 'student';
		$student = $this->controller(company: 'co-a')->book();
		self::assertSame(expected: 403, actual: $student->getStatus());

		self::assertSame(expected: [], actual: $this->asked);
		self::assertSame(expected: [], actual: $this->writes);
	}//end testABlankCompanyAndAnotherAudienceAreRefused()

	/**
	 * A claim in the assertion that is not a string is refused.
	 *
	 * @return void
	 */
	public function testANonStringCompanyClaimIsRefused(): void {
		$response = $this->controller(company: 'co-a', claim: ['co-a'])->book();

		self::assertSame(expected: 403, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->writes);
	}//end testANonStringCompanyClaimIsRefused()

	/**
	 * The controller for an employer assertion signed for school-a, naming this company.
	 *
	 * @param string      $company      The organisationRef portaliq stamped into the body.
	 * @param string|null $organisation The assertion's organisation claim; null leaves it out.
	 * @param mixed       $claim        The assertion's own organisationRef claim; '*' signs the body's company, null leaves it out.
	 *
	 * @return PortalEmployerController
	 */
	private function controller(string $company, ?string $organisation='school-a', mixed $claim='*'): PortalEmployerController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('signed');
		$request->method('getParam')->willReturnCallback(static fn (string $name): mixed => (['organisationRef' => $company][$name] ?? null));

		$claims = ['sub' => 'linda', 'audience' => $this->audience];
		if ($organisation !== null) {
			$claims['organisation'] = $organisation;
		}

		if ($claim === '*') {
			$claim = $company;
		}

		if ($claim !== null) {
			$claims['organisationRef'] = $claim;
		}

		$verifier = $this->createMock(originalClassName: PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($claims);

		$tenancy = $this->createMock(originalClassName: EmployerCompanyTenancy::class);
		$tenancy->method('holds')->willReturnCallback(
			function (string $organisationRef, string $organisation): bool {
				$this->asked[] = [$organisationRef, $organisation];
				return trim($organisationRef) === 'co-a' && $organisation === 'school-a';
			}
		);

		$bookings = $this->createMock(originalClassName: PortalEmployerBookings::class);
		foreach (['book', 'addParticipant', 'supplyBirthDate'] as $method) {
			$bookings->method($method)->willReturnCallback(
				function (string $organisationRef, array $body) use ($method): PortalOutcome {
					$this->writes[] = [$method, $organisationRef];
					return new PortalOutcome(status: 201, body: ['ok' => true], reason: 'stored');
				}
			);
		}

		return new PortalEmployerController(request: $request, verifier: $verifier, bookings: $bookings, tenancy: $tenancy);
	}//end controller()
}//end class
