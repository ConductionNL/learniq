<?php

/**
 * The employer's certificates download: the company comes from the signed
 * assertion and must lie in the portal's organisation before anything is bundled.
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
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalEmployerCertificatesController;
use OCA\Learniq\Kernel\Contracts\EmployerCertificatesArchiveInterface;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the employer's certificates download receiver.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class PortalEmployerCertificatesControllerTest extends TestCase {

	/**
	 * The companies the archive was asked to bundle.
	 *
	 * @var array<int, string>
	 */
	private array $made = [];

	/**
	 * Her own company is bundled, and only that one.
	 *
	 * @return void
	 */
	public function testHerOwnCompanyIsBundled(): void {
		$response = $this->controller(company: 'co-a')->bundle();

		self::assertSame(expected: [201, ['status' => 'created', 'count' => 4]], actual: [$response->getStatus(), $response->getData()]);
		self::assertSame(expected: ['co-a'], actual: $this->made);

		$padded = $this->controller(company: ' co-a ', claim: 'co-a ')->bundle();
		self::assertSame(expected: 201, actual: $padded->getStatus());
	}//end testHerOwnCompanyIsBundled()

	/**
	 * Another company, another audience, a body naming another company than
	 * the signed one, or no assertion: refused, and nothing is bundled.
	 *
	 * @return void
	 */
	public function testEveryOtherCallerIsRefusedBeforeBundling(): void {
		self::assertSame(expected: 403, actual: $this->controller(company: 'co-b')->bundle()->getStatus());
		self::assertSame(expected: 403, actual: $this->controller(company: 'co-a', audience: 'student')->bundle()->getStatus());
		self::assertSame(expected: 403, actual: $this->controller(company: 'co-a', claim: 'co-c')->bundle()->getStatus());
		self::assertSame(expected: 403, actual: $this->controller(company: 'co-a', claim: null)->bundle()->getStatus());
		self::assertSame(expected: 403, actual: $this->controller(company: ' ')->bundle()->getStatus());
		self::assertSame(expected: 401, actual: $this->controller(company: 'co-a', verified: false)->bundle()->getStatus());

		self::assertSame(expected: [], actual: $this->made);
	}//end testEveryOtherCallerIsRefusedBeforeBundling()

	/**
	 * The receiver over a stubbed assertion.
	 *
	 * @param string      $company  The body's organisationRef.
	 * @param string      $audience The signed audience.
	 * @param bool        $verified Whether the assertion verifies.
	 * @param string|null $claim    The signed organisationRef; '*' for the body's.
	 *
	 * @return PortalEmployerCertificatesController
	 */
	private function controller(
		string $company,
		string $audience='employer',
		bool $verified=true,
		?string $claim='*'
	): PortalEmployerCertificatesController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('signed');
		$request->method('getParam')->willReturnCallback(static fn (string $name): mixed => (['organisationRef' => $company][$name] ?? null));

		$claims = ['sub' => 'linda', 'audience' => $audience, 'organisation' => 'school-a'];
		if ($claim === '*') {
			$claim = $company;
		}

		if ($claim !== null) {
			$claims['organisationRef'] = $claim;
		}

		$verifier = $this->createMock(originalClassName: PortalAssertionVerifier::class);
		if ($verified === false) {
			$claims = null;
		}

		$verifier->method('verify')->willReturn($claims);

		$tenancy = $this->createMock(originalClassName: EmployerCompanyTenancy::class);
		$tenancy->method('holds')->willReturnCallback(
			static fn (string $organisationRef, string $organisation): bool => $organisationRef === 'co-a' && $organisation === 'school-a'
		);

		$archives = $this->createMock(originalClassName: EmployerCertificatesArchiveInterface::class);
		$archives->method('make')->willReturnCallback(
			function (string $organisationRef): PortalOutcome {
				$this->made[] = $organisationRef;
				return new PortalOutcome(status: 201, body: ['status' => 'created', 'count' => 4]);
			}
		);

		return new PortalEmployerCertificatesController(request: $request, verifier: $verifier, tenancy: $tenancy, archives: $archives);
	}//end controller()
}//end class
