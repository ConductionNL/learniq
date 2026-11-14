<?php

/**
 * The PDF fields CertificatePdfDelegationService writes onto a Credential,
 * validated with Opis against the shipped Credential schema.
 *
 * @category  Tests
 * @package   OCA\Learniq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/learniq
 *
 * @spec openspec/changes/credentials-certificate-pdf/tasks.md#task-2.3
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
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Every outcome of a render passes the real schema fragment, and a value the
 * schema refuses fails, so a drift between service and schema shows here.
 */
#[CoversClass(CertificatePdfDelegationService::class)]
#[UsesClass(FleetAppId::class)]
class CertificatePdfFieldsSchemaTest extends TestCase {

	/**
	 * The PDF properties of Credential.
	 */
	private const PDF_FIELDS = ['pdfDocumentRef', 'pdfRenderStatus', 'pdfRenderError'];

	/**
	 * Whether filinq is installed.
	 *
	 * @var boolean
	 */
	private bool $filinq = true;

	/**
	 * What filinq answers: a body, or null to throw.
	 *
	 * @var string|null
	 */
	private ?string $answer = '{"documentRef":"doc-42"}';

	/**
	 * The service under test, against stubbed boundaries.
	 *
	 * @return CertificatePdfDelegationService
	 */
	private function service(): CertificatePdfDelegationService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)$this->answer);
		$client = $this->createMock(IClient::class);
		if ($this->answer === null) {
			$client->method('post')->willThrowException(new RuntimeException('connection refused'));
		} else {
			$client->method('post')->willReturn($response);
		}

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://nc.example' . $path);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('tok');
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturnCallback(fn (string $id): bool => $this->filinq && $id === 'filinq');
		$copies = $this->createMock(ReadableCopiesInterface::class);
		$copies->method('derive')->willReturn([]);

		return new CertificatePdfDelegationService($clients, $urls, $config, $apps, new NullLogger(), $copies);
	}//end service()

	/**
	 * The Credential schema reduced to its PDF properties, without
	 * OpenRegister's own keys, the way OpenRegister validates a write.
	 *
	 * @return string The JSON schema.
	 */
	private function fragment(): string {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$properties = $register['components']['schemas']['Credential']['properties'];

		$fragment = ['type' => 'object', 'properties' => []];
		foreach (self::PDF_FIELDS as $field) {
			self::assertArrayHasKey($field, $properties, 'Credential declares ' . $field);
			$property = array_filter(
				$properties[$field],
				static fn (string $key): bool => str_starts_with($key, 'x-') === false,
				ARRAY_FILTER_USE_KEY
			);
			// As OpenRegister's ValidateObject does for an optional property:
			// null is accepted unless an enum leaves it out.
			$required = in_array($field, ($register['components']['schemas']['Credential']['required'] ?? []), true);
			$enumExcludesNull = is_array($property['enum'] ?? null) === true && in_array(null, $property['enum'], true) === false;
			if ($required === false && $enumExcludesNull === false && is_string($property['type'] ?? null) === true) {
				$property['type'] = [$property['type'], 'null'];
			}

			$fragment['properties'][$field] = $property;
		}

		return (string)json_encode($fragment);
	}//end fragment()

	/**
	 * The PDF fields of a rendered credential, as OpenRegister receives them.
	 *
	 * @return object
	 */
	private function written(): object {
		$rendered = $this->service()->render(
			credential: ['id' => 'c-1', 'learnerName' => 'Tom', 'verificationUrl' => 'https://learn.example/verify/c-1'],
			course: ['name' => 'VCA Basis', 'certificateTemplateSlug' => 'vca-basis']
		);

		return json_decode((string)json_encode(array_intersect_key($rendered, array_flip(self::PDF_FIELDS))));
	}//end written()

	/**
	 * A rendered, a failed and an unavailable outcome each pass the real schema.
	 *
	 * @return void
	 */
	public function testEveryOutcomePassesTheRealSchema(): void {
		$validator = new Validator();
		$cases = [];

		$cases['rendered'] = $this->written();
		$this->answer = null;
		$cases['failed'] = $this->written();
		$this->filinq = false;
		$cases['unavailable'] = $this->written();

		foreach ($cases as $status => $written) {
			self::assertSame($status, $written->pdfRenderStatus);
			$result = $validator->validate($written, $this->fragment());
			self::assertTrue($result->isValid(), $status . ': ' . json_encode($result->error()?->message()));
		}

		self::assertSame('doc-42', $cases['rendered']->pdfDocumentRef);
		self::assertNull($cases['rendered']->pdfRenderError);
		self::assertStringContainsString('filinq', (string)$cases['unavailable']->pdfRenderError);
	}//end testEveryOutcomePassesTheRealSchema()

	/**
	 * Control: a status the schema does not list is refused, so the test
	 * above would catch a service writing one.
	 *
	 * @return void
	 */
	public function testAStatusTheSchemaDoesNotListIsRefused(): void {
		$written = $this->written();
		$written->pdfRenderStatus = 'done';

		self::assertFalse((new Validator())->validate($written, $this->fragment())->isValid());

		$written->pdfRenderStatus = 'rendered';
		$written->pdfDocumentRef = 42;
		self::assertFalse((new Validator())->validate($written, $this->fragment())->isValid(), 'a non-string reference is refused');
	}//end testAStatusTheSchemaDoesNotListIsRefused()
}//end class
