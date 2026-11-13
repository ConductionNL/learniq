<?php

/**
 * Unit tests for CertificatePdfDelegationService.
 *
 * @category  Tests
 * @package   OCA\Learniq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec openspec/changes/credentials-certificate-pdf/specs/certification/spec.md#requirement-issuing-a-certificate-renders-its-pdf-fail-soft
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Kernel\Contracts\ReadableCopiesInterface;
use OCA\Learniq\Service\CertificatePdfDelegationService;
use OCA\Learniq\Support\FleetAppId;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A certificate's PDF is asked of filinq on the course's template, and never blocks the certificate.
 */
#[CoversClass(CertificatePdfDelegationService::class)]
#[UsesClass(FleetAppId::class)]
class CertificatePdfDelegationServiceTest extends TestCase {

	/**
	 * The HTTP client.
	 *
	 * @var IClient&MockObject
	 */
	private IClient $client;

	/**
	 * Whether filinq is installed.
	 *
	 * @var bool
	 */
	private bool $filinq = true;

	/**
	 * The configured token.
	 *
	 * @var string
	 */
	private string $token = 'tok';

	/**
	 * The readable copies OpenRegister would write on save.
	 *
	 * @var array<string,string|null>|null
	 */
	private ?array $derived = [];

	/**
	 * A certificate as issuance hands it over.
	 *
	 * @var array<string,mixed>
	 */
	private array $credential = [
		'learnerName' => 'Tom de Vries',
		'courseName' => 'VCA Basis',
		'issuedAt' => '2026-11-01T10:00:00+01:00',
		'expiresAt' => null,
		'validUntilLabel' => 'Geldig tot 1 november 2036',
		'verificationUrl' => 'https://learn.example/verify/c-1',
		'organisationRef' => 'org-1',
	];


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->client = $this->createMock(IClient::class);
	}//end setUp()


	/**
	 * The service, wired to the current filinq and token state.
	 *
	 * @return CertificatePdfDelegationService
	 */
	private function service(): CertificatePdfDelegationService {
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($this->client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => ($app === 'learniq' && $key === 'docudesk_api_token' ? $this->token : ''));
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $id): bool => $this->filinq && $id === 'filinq');

		$copies = $this->createMock(ReadableCopiesInterface::class);
		if ($this->derived === null) {
			$copies->method('derive')->willThrowException(new RuntimeException('register unreadable'));
		} else {
			$copies->method('derive')->with('credential', $this->anything())->willReturn($this->derived);
		}

		return new CertificatePdfDelegationService($clients, $urls, $config, $apps, new NullLogger(), $copies);
	}//end service()


	/**
	 * A response with this body.
	 *
	 * @param string $body The body.
	 *
	 * @return IResponse
	 */
	private function response(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		return $response;
	}//end response()


	/**
	 * A rendered PDF stores its reference; the request carries the template, the data and the token.
	 *
	 * @return void
	 */
	public function testARenderedPdfStoresItsReference(): void {
		$this->client->expects($this->once())
			->method('post')
			->with(
				'https://nc.example/index.php/apps/filinq/api/v1/documents/render',
				$this->callback(
					function (array $options): bool {
						$this->assertSame('Bearer tok', $options['headers']['Authorization']);
						$this->assertSame('vca-basis', $options['json']['templateSlug']);
						$this->assertSame('Tom de Vries', $options['json']['data']['learnerName']);
						$this->assertSame('https://learn.example/verify/c-1', $options['json']['data']['qrTarget']);
						return true;
					}
				)
			)
			->willReturn($this->response('{"documentRef":"doc-42"}'));

		$out = $this->service()->render($this->credential, ['certificateTemplateSlug' => ' vca-basis ']);

		$this->assertSame('rendered', $out['pdfRenderStatus']);
		$this->assertSame('doc-42', $out['pdfDocumentRef']);
		$this->assertNull($out['pdfRenderError']);
		$this->assertSame('Tom de Vries', $out['learnerName']);
	}//end testARenderedPdfStoresItsReference()


	/**
	 * A course without a template asks nothing and changes nothing.
	 *
	 * @return void
	 */
	public function testACourseWithoutATemplateRendersNothing(): void {
		$this->client->expects($this->never())->method('post');

		$this->assertSame($this->credential, $this->service()->render($this->credential, ['certificateTemplateSlug' => '  ']));
		$this->assertSame($this->credential, $this->service()->render($this->credential, []));
	}//end testACourseWithoutATemplateRendersNothing()


	/**
	 * Without filinq the certificate is unavailable, with a reason that names filinq.
	 *
	 * @return void
	 */
	public function testWithoutFilinqThePdfIsUnavailable(): void {
		$this->filinq = false;
		$this->client->expects($this->never())->method('post');

		$out = $this->service()->render($this->credential, ['certificateTemplateSlug' => 'vca-basis']);

		$this->assertSame('unavailable', $out['pdfRenderStatus']);
		$this->assertStringContainsString('filinq', $out['pdfRenderError']);
		$this->assertArrayNotHasKey('pdfDocumentRef', $out);
	}//end testWithoutFilinqThePdfIsUnavailable()


	/**
	 * Without a token the PDF is unavailable and nothing is sent.
	 *
	 * @return void
	 */
	public function testWithoutATokenThePdfIsUnavailable(): void {
		$this->token = '';
		$this->client->expects($this->never())->method('post');

		$out = $this->service()->render($this->credential, ['certificateTemplateSlug' => 'vca-basis']);

		$this->assertSame('unavailable', $out['pdfRenderStatus']);
		$this->assertStringContainsString('docudesk_api_token', $out['pdfRenderError']);
	}//end testWithoutATokenThePdfIsUnavailable()


	/**
	 * An HTTP error is a failed render with its reason, never an exception.
	 *
	 * @return void
	 */
	public function testAnHttpErrorFailsSoft(): void {
		$this->client->method('post')->willThrowException(new RuntimeException('502 Bad Gateway'));

		$out = $this->service()->render($this->credential, ['certificateTemplateSlug' => 'vca-basis']);

		$this->assertSame('failed', $out['pdfRenderStatus']);
		$this->assertSame('filinq render error: 502 Bad Gateway', $out['pdfRenderError']);
	}//end testAnHttpErrorFailsSoft()


	/**
	 * A malformed answer, or one without a reference, is a failed render.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutAReferenceFails(): void {
		$this->client->method('post')->willReturnOnConsecutiveCalls(
			$this->response('<html>'),
			$this->response('{"documentRef":""}')
		);

		foreach ([1, 2] as $attempt) {
			$out = $this->service()->render($this->credential, ['certificateTemplateSlug' => 'vca-basis']);
			$this->assertSame('failed', $out['pdfRenderStatus'], 'attempt ' . $attempt);
			$this->assertSame('filinq answered without a document reference', $out['pdfRenderError']);
		}
	}//end testAnAnswerWithoutAReferenceFails()


	/**
	 * The payload prints the verification URL as link and QR target, and falls back to the course's title.
	 *
	 * @return void
	 */
	public function testThePayloadPrintsHowToCheckIt(): void {
		$payload = CertificatePdfDelegationService::payload(
			['verificationUrl' => 'https://learn.example/verify/c-9', 'issuedAt' => '2026-11-01', 'expiresAt' => '', 'learnerName' => ['x']],
			['title' => 'BHV'],
			'bhv'
		);

		$this->assertSame(
			[
				'templateSlug' => 'bhv',
				'outputQuality' => 'print',
				'data' => [
					'learnerName' => null,
					'courseName' => 'BHV',
					'issuedAt' => '2026-11-01',
					'expiresAt' => null,
					'validUntilLabel' => null,
					'verificationUrl' => 'https://learn.example/verify/c-9',
					'qrTarget' => 'https://learn.example/verify/c-9',
					'organisationRef' => null,
				],
			],
			$payload
		);
	}//end testThePayloadPrintsHowToCheckIt()

	/**
	 * Copies the credential does not carry yet are derived before the render; its own values win.
	 *
	 * @return void
	 */
	public function testMissingCopiesAreDerivedBeforeTheRender(): void {
		$this->derived = ['learnerName' => 'Derived Name', 'validUntilLabel' => 'Geldig tot 1 november 2031', 'courseName' => 'Derived Course'];
		$sent = null;
		$this->client->method('post')->willReturnCallback(
			function (string $url, array $options) use (&$sent): IResponse {
				$sent = $options['json']['data'];
				return $this->response('{"documentRef":"doc-1"}');
			}
		);

		$out = $this->service()->render(['learnerName' => '', 'courseName' => 'VCA Basis', 'verificationUrl' => 'https://v'], ['certificateTemplateSlug' => 'vca']);

		$this->assertSame('Derived Name', $sent['learnerName']);
		$this->assertSame('VCA Basis', $sent['courseName']);
		$this->assertSame('Geldig tot 1 november 2031', $sent['validUntilLabel']);
		$this->assertSame('', $out['learnerName'], 'the derived copies are sent, not written: the save writes them');
	}//end testMissingCopiesAreDerivedBeforeTheRender()


	/**
	 * Copies that cannot be derived do not stop the render.
	 *
	 * @return void
	 */
	public function testUnderivableCopiesStillRender(): void {
		$this->derived = null;
		$this->client->method('post')->willReturn($this->response('{"documentRef":"doc-2"}'));

		$this->assertSame('rendered', $this->service()->render($this->credential, ['certificateTemplateSlug' => 'vca'])['pdfRenderStatus']);
	}//end testUnderivableCopiesStillRender()
}//end class
