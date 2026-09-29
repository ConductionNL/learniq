<?php

/**
 * Every learniq path that stamps or scopes a tenant resolves it through
 * CallerTenantResolver, so an unbound user lands in the default tenant and
 * never in the instance id.
 *
 * @category Test
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-fix-cross-tenant-idor-planid-lookups/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use FilesystemIterator;
use OCA\Learniq\Controller\CoursePackageImportController;
use OCA\Learniq\Controller\QtiImportController;
use OCA\Learniq\Service\AuditPackBuilder;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CourseStore\CourseStoreInstaller;
use OCA\Learniq\Service\LearningRecordImportIntakeService;
use OCA\Learniq\Service\LessonOnboarding\OnboardingFolderSetting;
use OCA\Learniq\Service\XapiDocumentCodec;
use OCA\Learniq\Service\XapiDocumentStore;
use OCA\Learniq\Service\XapiStatementIngest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The fallback lives in one place and every caller uses it.
 */
class DefaultTenantRoutingTest extends TestCase {

	/**
	 * Every class that used to carry its own instance-id fallback.
	 */
	private const ROUTED = [
		AuditPackBuilder::class,
		CoursePackageImportController::class,
		CourseStoreInstaller::class,
		LearningRecordImportIntakeService::class,
		OnboardingFolderSetting::class,
		QtiImportController::class,
		XapiDocumentStore::class,
		XapiStatementIngest::class,
	];

	/**
	 * A resolver for an unbound user on an instance whose id is readable, so a
	 * caller that still read the instance id would be caught.
	 *
	 * @return CallerTenantResolver
	 */
	private function unbound(): CallerTenantResolver {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');
		$config->method('getSystemValue')->willReturn('ocuhb9wy3beh');

		return new CallerTenantResolver($config, $this->createMock(ObjectService::class));
	}//end unbound()

	/**
	 * Each routed class takes CallerTenantResolver, and nothing in lib/ reads
	 * the instance id except the repair step that moves old rows off it.
	 *
	 * @return void
	 */
	public function testEveryCallerTakesTheResolverAndNoneReadsTheInstanceId(): void {
		foreach (self::ROUTED as $class) {
			$types = array_map(
				static fn ($parameter): string => (string)$parameter->getType(),
				(new ReflectionClass($class))->getConstructor()->getParameters()
			);
			self::assertContains(CallerTenantResolver::class, $types, $class);
		}

		$root  = dirname(__DIR__, 3) . '/lib';
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'Repair/MoveInstanceIdTenantToDefaultTenant.php') === true) {
				continue;
			}

			self::assertStringNotContainsString("'instanceid'", (string)file_get_contents($file->getPathname()), $file->getPathname());
		}
	}//end testEveryCallerTakesTheResolverAndNoneReadsTheInstanceId()

	/**
	 * An unbound learner's xAPI statement is stored in the default tenant, a
	 * UUID the schema accepts (the instance id made this answer 500).
	 *
	 * @return void
	 */
	public function testAnUnboundLearnersStatementLandsInTheDefaultTenant(): void {
		$saved   = [];
		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		(new XapiStatementIngest(objectService: $objects, tenants: $this->unbound()))->ingest(
			statements: [['actor' => ['name' => 'pupil'], 'verb' => ['id' => 'http://adlnet.gov/expapi/verbs/completed'], 'object' => ['id' => 'urn:x']]],
			actorId: 'pupil1'
		);

		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $saved[0]['tenant_id']);
	}//end testAnUnboundLearnersStatementLandsInTheDefaultTenant()

	/**
	 * An unbound learner's xAPI document key carries the default tenant.
	 *
	 * @return void
	 */
	public function testAnUnboundLearnersDocumentKeyUsesTheDefaultTenant(): void {
		$store = new XapiDocumentStore(objectService: $this->createMock(ObjectService::class), tenants: $this->unbound(), codec: new XapiDocumentCodec());

		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $store->key(kind: 'state', actorId: 'pupil1', activityId: 'urn:x', registration: '', documentId: 'd')['tenantId']);
	}//end testAnUnboundLearnersDocumentKeyUsesTheDefaultTenant()

	/**
	 * An unbound coordinator's learning-record intake resolves the default tenant.
	 *
	 * @return void
	 */
	public function testAnUnboundCoordinatorsIntakeUsesTheDefaultTenant(): void {
		$service = new LearningRecordImportIntakeService(
			objectService: $this->createMock(ObjectService::class),
			transitionEngine: $this->createMock(TransitionEngine::class),
			rootFolder: $this->createMock(IRootFolder::class),
			tenants: $this->unbound(),
			logger: new NullLogger(),
		);
		$user    = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('coordinator-1');

		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $service->resolveTenantId(user: $user));
	}//end testAnUnboundCoordinatorsIntakeUsesTheDefaultTenant()
}//end class
