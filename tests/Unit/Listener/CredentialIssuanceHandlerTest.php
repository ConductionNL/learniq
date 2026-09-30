<?php

/**
 * Learniq CredentialIssuanceHandler unit tests.
 *
 * A completed enrolment on a course with a certificate template must produce a
 * Credential that is signed when it is saved. OpenRegister runs lifecycle
 * guards and actions on updates only, so nothing signs a credential on create
 * unless the handler does (learniq#182). These tests hand the handler the real
 * ObjectTransitionedEvent, sign with the real CredentialSigningService and a
 * real RSA key, and check the saved object against the Credential schema's own
 * `required` list in learniq_register.json.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/certification/spec.md#requirement-an-issued-certificate-carries-a-signed-europass-form
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\CredentialIssuanceHandler;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\Learniq\Service\EdciPayloadBuilder;
use OCA\Learniq\Service\EuropassIssuer;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\SigningKeyConfigKey;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Tests for the Enrolment.completed to signed Credential bridge.
 */
class CredentialIssuanceHandlerTest extends TestCase {
	private const TENANT = 'tenant-kompas';
	private const SCHOOL_NAME = 'Voorbeeld Opleidingscentrum Het Kompas';

	/**
	 * The learner's Nextcloud user id, what Enrolment.learnerId holds.
	 */
	private const LEARNER_UID = 'k.jansen';

	/**
	 * The learner's LearnerProfile uuid in the tenant, what Credential.learnerId must hold.
	 */
	private const LEARNER_PROFILE = 'ee06000c-0000-4000-8000-000000000042';

	/**
	 * Whether the learner has a LearnerProfile in the tenant.
	 *
	 * @var bool
	 */
	private bool $learnerHasProfile = true;

	/**
	 * Warnings the handler logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

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
	 * Every saveObject() call the handler made, as [object, schema, uuid].
	 *
	 * @var array<int, array{object: array<string,mixed>, schema: mixed, uuid: ?string, rbac: bool}>
	 */
	private array $saved = [];

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
		$details = openssl_pkey_get_details($resource);
		$this->publicKeyPem = (string)$details['key'];
		$this->saved = [];
		$this->warnings = [];
		$this->learnerHasProfile = true;
	}//end setUp()

	/**
	 * The saved credential carries every property the Credential schema requires.
	 *
	 * Red before the fix: `signature`, `openbadges3Payload` and `issuerDid` were
	 * never sent, because the handler relied on a lifecycle guard that
	 * OpenRegister does not run on a create.
	 *
	 * @return void
	 */
	public function testACompletedEnrolmentSavesACredentialWithEveryRequiredProperty(): void {
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		self::assertCount(1, $this->saved, 'Exactly one credential is saved for one completion.');
		$credential = $this->saved[0]['object'];
		self::assertSame('credential', $this->saved[0]['schema']);

		$missing = [];
		foreach ($this->credentialRequiredProperties() as $property) {
			if (array_key_exists($property, $credential) === false
				|| $credential[$property] === null
				|| $credential[$property] === ''
				|| $credential[$property] === []
			) {
				$missing[] = $property;
			}
		}

		self::assertSame([], $missing, 'The saved credential lacks properties the Credential schema requires.');
		self::assertStringStartsWith('did:', (string)$credential['issuerDid']);
		self::assertIsArray($credential['openbadges3Payload']);
		self::assertArrayHasKey('proof', $credential['openbadges3Payload']);
	}//end testACompletedEnrolmentSavesACredentialWithEveryRequiredProperty()

	/**
	 * A certificate is saved with its Europass form, signed with the same key
	 * and key id as the Open Badges payload, naming the school as issuer.
	 *
	 * @return void
	 */
	public function testACertificateIsIssuedWithASignedEuropassForm(): void {
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		$credential = $this->saved[0]['object'];
		self::assertIsArray($credential['edciPayload']);
		$edci = $credential['edciPayload'];
		self::assertSame(EdciPayloadBuilder::ELM_CONTEXT, $edci['@context'][1]);
		self::assertSame('urn:uuid:' . $this->saved[0]['uuid'], $edci['id']);
		self::assertSame(self::SCHOOL_NAME, $edci['issuer']['legalName']['en']);
		self::assertSame('00X6', $edci['issuer']['registration']['notation']);
		self::assertSame($credential['openbadges3Payload']['proof']['verificationMethod'], $edci['proof']['verificationMethod']);
		self::assertSame('BHV basisopleiding', $edci['credentialSubject']['hasClaim'][0]['title']['en']);
	}//end testACertificateIsIssuedWithASignedEuropassForm()

	/**
	 * The signature verifies against the tenant's public key, and the payload
	 * names the id the credential is saved under, so the public verify route
	 * can find it and check it.
	 *
	 * @return void
	 */
	public function testTheSignatureVerifiesAndThePayloadNamesTheSavedId(): void {
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		self::assertCount(1, $this->saved);
		$uuid = $this->saved[0]['uuid'];
		$credential = $this->saved[0]['object'];

		self::assertIsString($uuid, 'The credential is saved under the id it was signed with.');
		self::assertNotSame('', $uuid);
		self::assertSame('https://example.test/apps/learniq/api/credentials/' . $uuid . '/verify', $credential['verificationUrl']);
		self::assertSame($credential['verificationUrl'], $credential['openbadges3Payload']['id']);

		$payload = $credential['openbadges3Payload'];
		unset($payload['proof']);
		[$headerB64, $sigB64] = explode('..', (string)$credential['signature'], 2);
		$signingInput = $headerB64 . '.' . json_encode($this->sortKeysRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$padded = str_pad($sigB64, (int)ceil(strlen($sigB64) / 4) * 4, '=');
		$signature = base64_decode(strtr($padded, '-_', '+/'), true);
		self::assertIsString($signature);

		$valid = openssl_verify($signingInput, $signature, (string)$this->publicKeyPem, OPENSSL_ALGO_SHA256);
		self::assertSame(1, $valid, 'The saved signature must verify against the tenant public key.');
	}//end testTheSignatureVerifiesAndThePayloadNamesTheSavedId()

	/**
	 * `issuedBy` is the name of the tenant's School record, a declared field,
	 * and the signed payload names the same issuer.
	 *
	 * Red before the fix: the handler read `Course.issuerName`, which the Course
	 * schema does not declare, so `issuedBy` was always empty.
	 *
	 * @return void
	 */
	public function testIssuedByIsTheNameOfTheTenantsSchool(): void {
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		self::assertCount(1, $this->saved);
		$credential = $this->saved[0]['object'];
		self::assertSame(self::SCHOOL_NAME, $credential['issuedBy']);
		self::assertSame(self::SCHOOL_NAME, $credential['openbadges3Payload']['issuer']['name']);
	}//end testIssuedByIsTheNameOfTheTenantsSchool()

	/**
	 * Without a signing key for the tenant, no credential is saved at all: an
	 * unsigned credential is one the public verify route can never verify.
	 *
	 * @return void
	 */
	public function testNoCredentialIsSavedWhenTheTenantHasNoSigningKey(): void {
		$handler = $this->buildHandler(tenantHasKey: false);
		$handler->handle($this->completionEvent());

		self::assertSame([], $this->saved, 'An unsigned credential must not be saved.');
	}//end testNoCredentialIsSavedWhenTheTenantHasNoSigningKey()

	/**
	 * The credential names the learner by LearnerProfile uuid, as its schema
	 * declares, and carries the Nextcloud user id as learnerUserId. Red before
	 * the fix: the enrolment's user id was copied into learnerId, which fails
	 * the schema's format uuid for every real user.
	 *
	 * @return void
	 */
	public function testTheCredentialNamesTheProfileAndCarriesTheUserId(): void {
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		self::assertCount(1, $this->saved);
		$credential = $this->saved[0]['object'];
		self::assertSame(self::LEARNER_PROFILE, $credential['learnerId']);
		self::assertSame(self::LEARNER_UID, $credential['learnerUserId']);
		self::assertSame('urn:learniq:learner:' . self::LEARNER_PROFILE, $credential['openbadges3Payload']['credentialSubject']['id']);
		self::assertStringNotContainsString(self::LEARNER_UID, (string)json_encode($credential['openbadges3Payload']), 'The user id never enters the signed payload.');
	}//end testTheCredentialNamesTheProfileAndCarriesTheUserId()

	/**
	 * The credential is saved as the system, not under the rights of the
	 * teacher whose transition completed the enrolment. Credential `create`
	 * belongs to hr and compliance officers only; saving with RBAC made
	 * OpenRegister refuse every live issue.
	 *
	 * @return void
	 */
	public function testTheCredentialIsSavedAsTheSystemNotAsTheTeacher(): void {
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		self::assertCount(1, $this->saved);
		self::assertFalse($this->saved[0]['rbac'], 'saveObject() must run with _rbac: false');
	}//end testTheCredentialIsSavedAsTheSystemNotAsTheTeacher()

	/**
	 * A learner without a LearnerProfile in the tenant gets no credential,
	 * and the warning names the learner and the course.
	 *
	 * @return void
	 */
	public function testNoCredentialIsIssuedToALearnerWithoutAProfile(): void {
		$this->learnerHasProfile = false;
		$handler = $this->buildHandler(tenantHasKey: true);
		$handler->handle($this->completionEvent());

		self::assertSame([], $this->saved, 'No credential is saved without a profile to name.');
		self::assertCount(1, $this->warnings);
		self::assertSame(self::LEARNER_UID, $this->warnings[0][1]['learner']);
		self::assertSame('course-bhv', $this->warnings[0][1]['course']);
	}//end testNoCredentialIsIssuedToALearnerWithoutAProfile()

	/**
	 * A logger that records warnings.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->warnings[] = [(string)$message, $context];
			}
		);

		return $logger;
	}//end logger()

	/**
	 * The handler is registered for the event OpenRegister dispatches on a
	 * transition, through the app's real listener wiring.
	 *
	 * @return void
	 */
	public function testTheHandlerIsRegisteredForTheTransitionEvent(): void {
		$pairs = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$pairs): void {
				$pairs[] = $event . ' => ' . $listener;
			}
		);

		$wiring = new EventListenerWiring();
		$wiring->registerAll(context: $context);
		$wiring->bootFilteredListeners(dispatcher: $this->createMock(IEventDispatcher::class), appId: 'learniq');

		self::assertContains(ObjectTransitionedEvent::class . ' => ' . CredentialIssuanceHandler::class, $pairs);
	}//end testTheHandlerIsRegisteredForTheTransitionEvent()

	/**
	 * The real event OpenRegister dispatches when an enrolment completes.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function completionEvent(): ObjectTransitionedEvent {
		$enrolment = OrEntityFactory::make(
			[
				'id' => 'enrol-0001',
				'courseId' => 'course-bhv',
				'learnerId' => self::LEARNER_UID,
				'tenant_id' => self::TENANT,
				'completedAt' => '2026-09-28T10:00:00+02:00',
				'lifecycle' => 'completed',
			],
			'enrolment',
			'learniq'
		);

		return new ObjectTransitionedEvent($enrolment, 'complete', 'active', 'completed', 'teacher-1', 'learniq', 'enrolment');
	}//end completionEvent()

	/**
	 * Build the handler with whatever collaborators its constructor declares:
	 * the ObjectService double, the REAL signing service over a real key, and a
	 * logger stub.
	 *
	 * @param bool $tenantHasKey Whether the tenant has a signing keypair.
	 *
	 * @return CredentialIssuanceHandler
	 */
	private function buildHandler(bool $tenantHasKey): CredentialIssuanceHandler {
		$class = new ReflectionClass(CredentialIssuanceHandler::class);
		$arguments = [];
		foreach (($class->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[$parameter->getName()] = match ($type->getName()) {
				ObjectService::class => $this->objectService(),
				CredentialSigningService::class => $this->signingService(tenantHasKey: $tenantHasKey),
				EuropassIssuer::class => new EuropassIssuer(
					objects: $this->objectService(),
					builder: new EdciPayloadBuilder(),
					signer: $this->signingService(tenantHasKey: $tenantHasKey),
					config: $this->createStub(IAppConfig::class)
				),
				LoggerInterface::class => $this->logger(),
				LearnerRefResolver::class => new LearnerRefResolver(objectService: $this->objectService()),
				ListenerSchemaResolver::class => TransitionScope::resolver(),
				default => $this->createStub($type->getName()),
			};
		}

		return $class->newInstanceArgs($arguments);
	}//end buildHandler()

	/**
	 * An ObjectService double that answers with OpenRegister's real method names:
	 * the course, no earlier credential, the tenant's school, and a capture of
	 * every save.
	 *
	 * @return ObjectService
	 */
	private function objectService(): ObjectService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			static function (mixed $id): ?ObjectEntity {
				if ($id !== 'course-bhv') {
					return null;
				}

				return OrEntityFactory::make(
					[
						'id' => 'course-bhv',
						'code' => 'BHV-BASIS',
						'name' => 'BHV basisopleiding',
						'certificateTemplate' => '/Certificaten/bhv.pdf',
						'tenant_id' => self::TENANT,
					],
					'course'
				);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				$filters = ($config['filters'] ?? []);
				if ($this->learnerHasProfile === true
					&& ($filters['schema'] ?? null) === 'learner-profile'
					&& ($filters['ncUserId'] ?? null) === self::LEARNER_UID
					&& ($filters['tenant_id'] ?? null) === self::TENANT
				) {
					return [OrEntityFactory::make(['id' => self::LEARNER_PROFILE, 'ncUserId' => self::LEARNER_UID, 'tenant_id' => self::TENANT], 'learner-profile')];
				}

				if (($filters['schema'] ?? null) === 'school' && ($filters['tenant_id'] ?? null) === self::TENANT) {
					return [
						OrEntityFactory::make(
							['id' => 'school-1', 'name' => self::SCHOOL_NAME, 'brin' => '00X6', 'tenant_id' => self::TENANT],
							'school'
						),
					];
				}

				return [];
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true): ObjectEntity {
				$data = $object;
				if ($object instanceof ObjectEntity) {
					$data = (array)$object->getObject();
				}

				$this->saved[] = ['object' => $data, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
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
				if ($tenantHasKey === false || in_array($key, [SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PRIVATE, tenantId: self::TENANT), SigningKeyConfigKey::forTenant(purpose: SigningKeyConfigKey::PUBLIC, tenantId: self::TENANT)], true) === false) {
					return $default;
				}

				if (str_contains($key, '.private.') === true) {
					return 'encrypted-private-key';
				}

				if (str_contains($key, '.public.') === true) {
					return $this->publicKeyPem;
				}

				return $default;
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

	/**
	 * The Credential schema's `required` list, read from the register file.
	 *
	 * @return list<string>
	 */
	private function credentialRequiredProperties(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$required = ($register['components']['schemas']['Credential']['required'] ?? []);
		self::assertNotSame([], $required, 'The Credential schema declares no required properties; the register was not read.');

		return array_values($required);
	}//end credentialRequiredProperties()

	/**
	 * Sort keys recursively (RFC 8785 JCS), as the signer does.
	 *
	 * @param array<mixed> $data The payload.
	 *
	 * @return array<mixed>
	 */
	private function sortKeysRecursive(array $data): array {
		$isObject = count(array_filter(array_keys($data), 'is_string')) > 0;
		if ($isObject === true) {
			ksort($data, SORT_STRING);
		}

		foreach ($data as $key => $value) {
			if (is_array($value) === true) {
				$data[$key] = $this->sortKeysRecursive($value);
			}
		}

		return $data;
	}//end sortKeysRecursive()
}//end class
