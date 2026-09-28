<?php

/**
 * Learniq ExternalTrainingController::issueCredential() signing tests.
 *
 * A manual credential for a verified external-training record must be signed
 * when it is saved. OpenRegister runs no lifecycle guard or action on a create,
 * so the comment that promised a signing guard on the `issue` transition was
 * never true (learniq#182). These tests sign with the real
 * CredentialSigningService over a real RSA key and check the saved object
 * against the Credential schema's `required` list.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credentials-europass-edci-export/tasks.md#task-1-prove-or-repair-the-signing-path
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExternalTrainingController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Tests that issueCredential() saves a signed credential, or none.
 */
class ExternalTrainingControllerIssueCredentialTest extends TestCase {
	private const TENANT = 'tenant-kompas';

	/**
	 * PEM private key of the test tenant.
	 *
	 * @var string
	 */
	private string $privateKeyPem = '';

	/**
	 * PEM public key of the test tenant.
	 *
	 * @var string
	 */
	private string $publicKeyPem = '';

	/**
	 * Every credential saveObject() call, as [object, uuid].
	 *
	 * @var array<int, array{object: array<string,mixed>, uuid: ?string}>
	 */
	private array $savedCredentials = [];

	/**
	 * Generate a real RSA keypair for the tenant.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$resource = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
		self::assertNotFalse($resource);
		openssl_pkey_export($resource, $privateKeyPem);
		$this->privateKeyPem = (string)$privateKeyPem;
		$this->publicKeyPem = (string)openssl_pkey_get_details($resource)['key'];
		$this->savedCredentials = [];
	}//end setUp()

	/**
	 * A verified record gets a credential that carries every property the
	 * Credential schema requires, saved under the id its payload names.
	 *
	 * @return void
	 */
	public function testAVerifiedRecordGetsASignedCredential(): void {
		$response = $this->controller(tenantHasKey: true)->issueCredential(recordId: 'record-1');

		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		self::assertCount(1, $this->savedCredentials);
		$credential = $this->savedCredentials[0]['object'];
		$uuid = (string)$this->savedCredentials[0]['uuid'];

		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$required = ($register['components']['schemas']['Credential']['required'] ?? []);
		self::assertNotSame([], $required);

		$missing = array_values(
			array_filter(
				$required,
				static fn (string $property): bool => in_array(($credential[$property] ?? null), [null, '', []], true)
			)
		);
		self::assertSame([], $missing, 'The saved credential lacks properties the Credential schema requires.');

		self::assertNotSame('', $uuid, 'The credential is saved under the id it was signed with.');
		self::assertSame($uuid, $response->getData()['credentialId']);
		self::assertStringContainsString('/credentials/' . $uuid . '/verify', (string)$credential['openbadges3Payload']['id']);
	}//end testAVerifiedRecordGetsASignedCredential()

	/**
	 * Without a signing key the controller saves nothing and says why.
	 *
	 * @return void
	 */
	public function testNoCredentialIsSavedWhenTheTenantHasNoSigningKey(): void {
		$response = $this->controller(tenantHasKey: false)->issueCredential(recordId: 'record-1');

		self::assertSame([], $this->savedCredentials, 'An unsigned credential must not be saved.');
		self::assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testNoCredentialIsSavedWhenTheTenantHasNoSigningKey()

	/**
	 * Build the controller with whatever collaborators its constructor declares.
	 *
	 * @param bool $tenantHasKey Whether the tenant has a signing keypair.
	 *
	 * @return ExternalTrainingController
	 */
	private function controller(bool $tenantHasKey): ExternalTrainingController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-1');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$objectService = $this->objectService();

		$class = new ReflectionClass(ExternalTrainingController::class);
		$arguments = [];
		foreach (($class->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[$parameter->getName()] = match ($type->getName()) {
				IUserSession::class => $userSession,
				ObjectService::class => $objectService,
				ExternalTrainingService::class => new ExternalTrainingService($objectService, $this->createStub(LoggerInterface::class)),
				CredentialSigningService::class => $this->signingService(tenantHasKey: $tenantHasKey),
				// requireAction() returns void on success: the authorised case.
				ActionAuthService::class => $this->createMock(ActionAuthService::class),
				default => $this->createStub($type->getName()),
			};
		}

		return $class->newInstanceArgs($arguments);
	}//end controller()

	/**
	 * An ObjectService double with OpenRegister's real method names.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			OrEntityFactory::make(
				[
					'id' => 'record-1',
					'learnerId' => 'ee06000c-0000-4000-8000-000000000042',
					'completedAt' => '2026-09-01T09:00:00+02:00',
					'validUntil' => '2028-09-01T00:00:00+02:00',
					'regulationSlug' => 'bhv',
					'lifecycle' => 'verified',
					'tenant_id' => self::TENANT,
				],
				'external-training-record'
			)
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$data = $object;
				if ($object instanceof ObjectEntity) {
					$data = (array)$object->getObject();
				}

				if ($schema === 'credential') {
					$this->savedCredentials[] = ['object' => $data, 'uuid' => $uuid];
					$uuid = ($uuid ?? 'generated-by-or');
				}

				return OrEntityFactory::make($data, (string)$schema, 'learniq', $uuid);
			}
		);

		return $objectService;
	}//end objectService()

	/**
	 * The real signing service over a real tenant keypair.
	 *
	 * @param bool $tenantHasKey Whether the tenant has a signing keypair.
	 *
	 * @return CredentialSigningService
	 */
	private function signingService(bool $tenantHasKey): CredentialSigningService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($tenantHasKey): string {
				if ($tenantHasKey === false || str_ends_with($key, '.' . self::TENANT) === false) {
					return $default;
				}

				if (str_contains($key, '.private.') === true) {
					return 'encrypted-private-key';
				}

				return $this->publicKeyPem;
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturn($this->privateKeyPem);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => 'https://example.test/apps/learniq/api/credentials/' . ($parameters['id'] ?? '') . '/verify'
		);

		return new CredentialSigningService($appConfig, $crypto, $urlGenerator);
	}//end signingService()
}//end class
