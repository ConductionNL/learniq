<?php

/**
 * Tests for ExternalTrainingController::import: authentication, the action
 * gate, the row limits, and the tenant the server (not the client) supplies.
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
 *
 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExternalTrainingController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\ExternalTrainingImport;
use OCA\Learniq\Service\ExternalTrainingLearnerMatch;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\Learniq\Controller\ExternalTrainingController::import
 * @uses \OCA\Learniq\Controller\ExternalTrainingController::__construct
 * @uses \OCA\Learniq\Service\CallerTenantResolver
 * @uses \OCA\Learniq\Service\ExternalTrainingImport
 * @uses \OCA\Learniq\Service\ExternalTrainingLearnerMatch
 * @uses \OCA\Learniq\Service\ExternalTrainingRowCheck
 */
class ExternalTrainingControllerImportTest extends TestCase {
	private const CALLER_TENANT = 'tenant-a';

	/**
	 * Every save the import made.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * The controller over the REAL import and tenant resolver.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $allowed Whether the action gate lets the caller through.
	 *
	 * @return ExternalTrainingController
	 */
	private function controller(bool $signedIn = true, bool $allowed = true): ExternalTrainingController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-a');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($signedIn === true ? $user : null);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key, mixed $default = ''): mixed => ($key === 'tenant_id' ? self::CALLER_TENANT : $default)
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['schema'] ?? '') === 'learner-profile'
				? OrEntityFactory::makeMany(
					[
						['id' => '00000000-0000-4000-8000-000000000001', 'ncUserId' => 'u1', 'personalNumber' => 'P1', 'tenant_id' => self::CALLER_TENANT],
						['id' => '00000000-0000-4000-8000-000000000002', 'ncUserId' => 'u2', 'personalNumber' => 'P2', 'tenant_id' => 'tenant-b'],
					],
					'learner-profile'
				)
				: [])
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], $register = null, $schema = null) {
				$this->saved[] = $object;
				return OrEntityFactory::make($object, (string)$schema);
			}
		);

		$actionAuth = $this->createMock(ActionAuthService::class);
		if ($allowed === false) {
			$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('Not allowed.'));
		}

		$class = new ReflectionClass(ExternalTrainingController::class);
		$arguments = [];
		foreach (($class->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[$parameter->getName()] = match ($type->getName()) {
				IUserSession::class => $userSession,
				ObjectService::class => $objectService,
				CallerTenantResolver::class => new CallerTenantResolver($config, $objectService),
				ExternalTrainingImport::class => new ExternalTrainingImport($objectService, new ExternalTrainingLearnerMatch($objectService, $this->createMock(IUserManager::class)), new NullLogger()),
				ActionAuthService::class => $actionAuth,
				default => $this->createStub($type->getName()),
			};
		}

		return $class->newInstanceArgs($arguments);
	}//end controller()

	/**
	 * A row of the list.
	 *
	 * @param string $learner The learner column.
	 *
	 * @return array<string,string>
	 */
	private static function row(string $learner): array {
		return ['learner' => $learner, 'title' => 'BHV', 'provider' => 'Oranje Kruis', 'completedAt' => '2026-01-15', 'tenant_id' => 'tenant-b'];
	}//end row()

	/**
	 * The preview writes nothing; the confirm records in the caller's tenant, whatever the row says.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-an-officer-uploads-a-providers-attendance-list
	 */
	public function testThePreviewWritesNothingAndTheConfirmUsesTheCallersTenant(): void {
		$preview = $this->controller()->import(rows: [self::row('P1'), self::row('P2')]);
		self::assertSame(Http::STATUS_OK, $preview->getStatus());
		self::assertSame(['ready', 'unmatched'], array_column($preview->getData()['rows'], 'status'));
		self::assertSame([], $this->saved);

		$result = $this->controller()->import(rows: [self::row('P1'), self::row('P2')], dryRun: false);
		self::assertSame(1, $result->getData()['summary']['created']);
		self::assertCount(1, $this->saved);
		self::assertSame(self::CALLER_TENANT, $this->saved[0]['tenant_id']);
		self::assertSame('officer-a', $this->saved[0]['submittedBy']);
	}//end testThePreviewWritesNothingAndTheConfirmUsesTheCallersTenant()

	/**
	 * A caller without the compliance role is refused before anything is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#scenario-a-learner-cannot-upload
	 */
	public function testALearnerCannotUpload(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->controller(allowed: false)->import(rows: [self::row('P1')], dryRun: false);
	}//end testALearnerCannotUpload()

	/**
	 * No user, no rows and too many rows each get a plain answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/compliance-external-training-spreadsheet-upload/specs/external-training-upload/spec.md#requirement-spreadsheet-import-of-external-training
	 */
	public function testTheRefusals(): void {
		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(signedIn: false)->import(rows: [self::row('P1')])->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->import(rows: [])->getStatus());

		$tooMany = $this->controller()->import(rows: array_fill(0, ExternalTrainingImport::MAX_ROWS + 1, self::row('P1')));
		self::assertSame(Http::STATUS_BAD_REQUEST, $tooMany->getStatus());
		self::assertSame('A file can hold at most 1000 rows. Split it and upload the parts.', $tooMany->getData()['error']);
		self::assertSame([], $this->saved);
	}//end testTheRefusals()
}//end class
