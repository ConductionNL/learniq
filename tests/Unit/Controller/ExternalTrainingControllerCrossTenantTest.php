<?php

/**
 * Learniq ExternalTrainingController cross-tenant lookup tests.
 *
 * The action matrix answers "may this user issue credentials", never "does
 * this record belong to this user's organisation". issueCredential() and
 * learnerCoverage() must not reach another tenant's record or learner.
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
 * @spec openspec/changes/fix-cross-tenant-idor-planid-lookups/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Controller;

use OCA\Learniq\Controller\ExternalTrainingController;
use OCA\Learniq\Service\ActionAuthService;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\CredentialSigningService;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Tests that external-training actions stay inside the caller's tenant.
 */
class ExternalTrainingControllerCrossTenantTest extends TestCase {
	private const CALLER_TENANT = 'tenant-a';
	private const OTHER_TENANT = 'tenant-b';

	/**
	 * A verified record of another tenant returns 404 and gets no credential.
	 *
	 * @return void
	 */
	public function testIssueCredentialForAnotherTenantsRecordIsNotFoundAndIssuesNothing(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			OrEntityFactory::make(
				[
					'id' => 'record-b',
					'learnerId' => 'learner-b',
					'lifecycle' => 'verified',
					'validUntil' => '2028-09-01T00:00:00+02:00',
					'tenant_id' => self::OTHER_TENANT,
				],
				'external-training-record'
			)
		);
		$objectService->expects(self::never())->method('saveObject');

		$signing = $this->createMock(CredentialSigningService::class);
		$signing->expects(self::never())->method('sign');

		$response = $this->controller(objectService: $objectService, signing: $signing)->issueCredential(recordId: 'record-b');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('Record not found', ((array)$response->getData())['error'] ?? null);
	}//end testIssueCredentialForAnotherTenantsRecordIsNotFoundAndIssuesNothing()

	/**
	 * A learner of another tenant reads as not covered, whatever the truth is.
	 *
	 * @return void
	 */
	public function testCoverageOfAnotherTenantsLearnerIsNotDisclosed(): void {
		$response = $this->controller(
			objectService: $this->learnerStore(learnerTenant: self::OTHER_TENANT),
			training: $this->coveredTraining()
		)->learnerCoverage(learnerId: 'learner-b', regulationSlug: 'nis2');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['covered' => false, 'evidenceClass' => null], $response->getData());
	}//end testCoverageOfAnotherTenantsLearnerIsNotDisclosed()

	/**
	 * An unknown learner reads as not covered, the same as a foreign one.
	 *
	 * @return void
	 */
	public function testCoverageOfAnUnknownLearnerIsNotCovered(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willThrowException(new DoesNotExistException('no such learner'));

		$response = $this->controller(objectService: $objectService, training: $this->coveredTraining())
			->learnerCoverage(learnerId: 'nobody', regulationSlug: 'nis2');

		self::assertSame(['covered' => false, 'evidenceClass' => null], $response->getData());
	}//end testCoverageOfAnUnknownLearnerIsNotCovered()

	/**
	 * Control: the caller's own learner still gets the real coverage answer.
	 *
	 * @return void
	 */
	public function testCoverageOfTheCallersOwnLearnerIsReported(): void {
		$response = $this->controller(
			objectService: $this->learnerStore(learnerTenant: self::CALLER_TENANT),
			training: $this->coveredTraining()
		)->learnerCoverage(learnerId: 'learner-a', regulationSlug: 'nis2');

		self::assertSame(['covered' => true, 'evidenceClass' => 'credential'], $response->getData());
	}//end testCoverageOfTheCallersOwnLearnerIsReported()

	/**
	 * An ExternalTrainingService double that reports the learner as covered.
	 *
	 * @return ExternalTrainingService
	 */
	private function coveredTraining(): ExternalTrainingService {
		$training = $this->createMock(ExternalTrainingService::class);
		$training->method('isLearnerCovered')->willReturn(true);
		$training->method('coveringEvidenceClass')->willReturn('credential');

		return $training;
	}//end coveredTraining()

	/**
	 * An ObjectService whose find() returns one learner profile of the given tenant.
	 *
	 * @param string $learnerTenant Tenant the learner belongs to.
	 *
	 * @return ObjectService&MockObject
	 */
	private function learnerStore(string $learnerTenant): ObjectService&MockObject {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(
			OrEntityFactory::make(
				['id' => 'learner-x', 'ncUserId' => 'someone', 'tenant_id' => $learnerTenant],
				'learner-profile'
			)
		);

		return $objectService;
	}//end learnerStore()

	/**
	 * Build the controller for a caller bound to CALLER_TENANT.
	 *
	 * @param ObjectService $objectService OR double.
	 * @param ExternalTrainingService|null $training Training service double.
	 * @param CredentialSigningService|null $signing Signing service double.
	 *
	 * @return ExternalTrainingController
	 */
	private function controller(
		ObjectService $objectService,
		?ExternalTrainingService $training = null,
		?CredentialSigningService $signing = null,
	): ExternalTrainingController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('officer-a');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $userId, string $appName, string $key, mixed $default = ''): mixed => ($key === 'tenant_id' ? self::CALLER_TENANT : $default)
		);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, mixed $default = ''): mixed => ($key === 'instanceid' ? 'instance-x' : $default)
		);

		$class = new ReflectionClass(ExternalTrainingController::class);
		$arguments = [];
		foreach (($class->getConstructor()?->getParameters() ?? []) as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$arguments[$parameter->getName()] = match ($type->getName()) {
				IUserSession::class => $userSession,
				ObjectService::class => $objectService,
				ExternalTrainingService::class => ($training ?? $this->createMock(ExternalTrainingService::class)),
				CredentialSigningService::class => ($signing ?? $this->createMock(CredentialSigningService::class)),
				CallerTenantResolver::class => new CallerTenantResolver($config, $objectService),
				// requireAction() returns void on success: the authorised case.
				ActionAuthService::class => $this->createMock(ActionAuthService::class),
				default => $this->createStub($type->getName()),
			};
		}

		return $class->newInstanceArgs($arguments);
	}//end controller()
}//end class
