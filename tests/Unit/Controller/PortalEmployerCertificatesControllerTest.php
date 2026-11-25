<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The employer's "download all valid certificates" forward: the company
 * comes from the signed assertion and must lie in the portal's organisation
 * before anything is bundled.
 *
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\PortalEmployerController;
use OCA\Learniq\Kernel\Contracts\EmployerCertificatesArchiveInterface;
use OCA\Learniq\Portal\EmployerCompanyTenancy;
use OCA\Learniq\Portal\PortalAssertionVerifier;
use OCA\Learniq\Service\Portal\PortalEmployerBookings;
use OCA\Learniq\Service\Portal\PortalOutcome;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/school-documents-on-the-portal/specs/portal-contribution/spec.md#requirement-an-employer-downloads-every-valid-certificate-at-once
 */
class PortalEmployerCertificatesControllerTest extends TestCase {

	private array $made = [];

	public function testHerOwnCompanyIsBundled(): void {
		$response = $this->controller(company: 'co-a')->bundleCertificates();

		self::assertSame(expected: [201, ['status' => 'created', 'count' => 4]], actual: [$response->getStatus(), $response->getData()]);
		self::assertSame(expected: ['co-a'], actual: $this->made);
	}//end testHerOwnCompanyIsBundled()

	public function testAnotherCompanyOrAudienceIsRefusedBeforeBundling(): void {
		$foreign = $this->controller(company: 'co-b')->bundleCertificates();
		self::assertSame(expected: 403, actual: $foreign->getStatus());

		$student = $this->controller(company: 'co-a', audience: 'student')->bundleCertificates();
		self::assertSame(expected: 403, actual: $student->getStatus());

		$unsigned = $this->controller(company: 'co-a', verified: false)->bundleCertificates();
		self::assertSame(expected: 401, actual: $unsigned->getStatus());

		self::assertSame(expected: [], actual: $this->made);
	}//end testAnotherCompanyOrAudienceIsRefusedBeforeBundling()

	public function testWithoutTheArchiveServiceTheAnswerIsUnavailable(): void {
		$response = $this->controller(company: 'co-a', archive: false)->bundleCertificates();

		self::assertSame(expected: 503, actual: $response->getStatus());
	}//end testWithoutTheArchiveServiceTheAnswerIsUnavailable()

	private function controller(string $company, string $audience='employer', bool $verified=true, bool $archive=true): PortalEmployerController {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('signed');
		$request->method('getParam')->willReturnCallback(static fn (string $name): mixed => ['organisationRef' => $company][$name] ?? null);

		$verifier = $this->createMock(originalClassName: PortalAssertionVerifier::class);
		$verifier->method('verify')->willReturn($verified ? ['sub' => 'linda', 'audience' => $audience, 'organisation' => 'school-a', 'organisationRef' => $company] : null);

		$tenancy = $this->createMock(originalClassName: EmployerCompanyTenancy::class);
		$tenancy->method('holds')->willReturnCallback(static fn (string $organisationRef, string $organisation): bool => $organisationRef === 'co-a' && $organisation === 'school-a');

		$archives = null;
		if ($archive === true) {
			$archives = $this->createMock(originalClassName: EmployerCertificatesArchiveInterface::class);
			$archives->method('make')->willReturnCallback(
				function (string $organisationRef): PortalOutcome {
					$this->made[] = $organisationRef;
					return new PortalOutcome(status: 201, body: ['status' => 'created', 'count' => 4]);
				}
			);
		}

		return new PortalEmployerController(
			request: $request,
			verifier: $verifier,
			bookings: $this->createMock(originalClassName: PortalEmployerBookings::class),
			tenancy: $tenancy,
			archives: $archives
		);
	}//end controller()
}//end class
