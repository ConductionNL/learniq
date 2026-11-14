<?php

/**
 * Unit tests for CredentialVerifyPageController and the verification link's two answers.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\CredentialVerifyController;
use OCA\Learniq\Controller\CredentialVerifyPageController;
use OCA\Learniq\Kernel\Contracts\JwsProofVerifierInterface;
use OCA\Learniq\Kernel\Contracts\SchoolPhoneInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Opening a certificate's link in a browser shows the page; a program asking for JSON gets JSON.
 *
 * @spec openspec/changes/certificate-verification-and-key-rotation/specs/certification/spec.md#requirement-the-verification-link-opens-a-page-that-says-in-words-what-was-checked
 */
class CredentialVerifyPageControllerTest extends TestCase {

	/**
	 * A request with this Accept header.
	 *
	 * @param string $accept The Accept header.
	 *
	 * @return IRequest
	 */
	private function requestAccepting(string $accept): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => (strtolower($name) === 'accept' ? $accept : '')
		);
		return $request;
	}//end requestAccepting()

	/**
	 * Accept headers and whether each is a browser asking for the page.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function acceptHeaders(): array {
		return [
			'a browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', true],
			'a browser, upper case' => ['TEXT/HTML', true],
			'a program asking for JSON' => ['application/json', false],
			'JSON and HTML both named' => ['application/json, text/html', false],
			'a wildcard' => ['*/*', false],
			'no header' => ['', false],
		];
	}//end acceptHeaders()

	/**
	 * Which requests count as a browser asking for the page.
	 *
	 * @param string $accept   The Accept header.
	 * @param bool   $expected Whether the page is wanted.
	 *
	 * @return void
	 */
	#[DataProvider('acceptHeaders')]
	public function testOnlyABrowserAskingForHtmlWantsThePage(string $accept, bool $expected): void {
		$this->assertSame($expected, CredentialVerifyPageController::wantsPage(request: $this->requestAccepting(accept: $accept)));
	}//end testOnlyABrowserAskingForHtmlWantsThePage()

	/**
	 * The page is the app's template in the public layout with the id and the phone number, and nothing from the certificate.
	 *
	 * @return void
	 */
	public function testThePageHandsTheScriptTheIdAndThePhoneNumberOnly(): void {
		$initialState = $this->createMock(IInitialState::class);
		$provided = [];
		$initialState->expects($this->exactly(2))
			->method('provideInitialState')
			->willReturnCallback(static function (string $key, mixed $value) use (&$provided): void {
				$provided[$key] = $value;
			});
		$phone = $this->createMock(SchoolPhoneInterface::class);
		$phone->method('phone')->willReturn('020 123 45 67');

		$controller = new CredentialVerifyPageController(
			request: $this->requestAccepting(accept: 'text/html'),
			initialState: $initialState,
			schoolPhone: $phone,
		);
		$response = $controller->show(id: 'cred-1');

		$this->assertSame('verify', $response->getTemplateName());
		$this->assertSame('learniq', $response->getApp());
		$this->assertSame(TemplateResponse::RENDER_AS_PUBLIC, $response->getRenderAs());
		$this->assertSame([], $response->getParams());
		$this->assertSame(['verify-id' => 'cred-1', 'school-phone' => '020 123 45 67'], $provided);
	}//end testThePageHandsTheScriptTheIdAndThePhoneNumberOnly()

	/**
	 * The verification route as a browser or a program opens it.
	 *
	 * @param string        $accept The Accept header.
	 * @param ObjectService $objects The object service.
	 *
	 * @return CredentialVerifyController
	 */
	private function verifyController(string $accept, ObjectService $objects): CredentialVerifyController {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/apps/learniq/' . $route . '/' . $params['id']
		);
		return new CredentialVerifyController(
			request: $this->requestAccepting(accept: $accept),
			objectService: $objects,
			proofs: $this->createMock(JwsProofVerifierInterface::class),
			throttler: $this->createMock(IThrottler::class),
			logger: $this->createMock(LoggerInterface::class),
			urlGenerator: $urls,
		);
	}//end verifyController()

	/**
	 * A browser on the link is sent to the page, and the certificate is not read for it.
	 *
	 * @return void
	 */
	public function testABrowserOnTheLinkIsSentToThePageWithoutALookup(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->never())->method('find');

		$response = $this->verifyController(accept: 'text/html,*/*;q=0.8', objects: $objects)->verify(id: 'cred-1');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/learniq/learniq.credentialVerifyPage.show/cred-1', $response->getRedirectURL());
	}//end testABrowserOnTheLinkIsSentToThePageWithoutALookup()

	/**
	 * A program asking for JSON keeps getting the route's answer.
	 *
	 * @return void
	 */
	public function testAProgramAskingForJsonKeepsGettingJson(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->expects($this->once())
			->method('find')
			->with('cred-1', $this->anything(), $this->anything(), 'learniq', 'credential')
			->willReturn(null);

		$response = $this->verifyController(accept: 'application/json', objects: $objects)->verify(id: 'cred-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['valid' => false, 'error' => 'not_found'], $response->getData());
	}//end testAProgramAskingForJsonKeepsGettingJson()
}//end class
