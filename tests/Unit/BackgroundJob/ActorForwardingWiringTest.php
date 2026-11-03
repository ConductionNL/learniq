<?php

/**
 * Every deferred job hands the actor forwarding collaborators to OpenRegister's base.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\BackgroundJob;

use OCA\Learniq\BackgroundJob\ActorForwarding;
use OCA\Learniq\BackgroundJob\AttendanceSummaryRecomputeJob;
use OCA\Learniq\BackgroundJob\CohortNameRestampJob;
use OCA\Learniq\BackgroundJob\CompetencyAttainmentRollupJob;
use OCA\Learniq\BackgroundJob\EmployerBookingRestampJob;
use OCA\Learniq\BackgroundJob\EngagementSignalJob;
use OCA\Learniq\BackgroundJob\EnrolmentProgressRollupJob;
use OCA\Learniq\BackgroundJob\LearnerEngagementRollupJob;
use OCA\Learniq\BackgroundJob\LearnerGroupLabelRestampJob;
use OCA\Learniq\BackgroundJob\SchoolAdviesVoorlopigRodJob;
use OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob;
use OCA\OpenRegister\BackgroundJob\ActorForwardedJob;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Builds each job from its constructor signature and reads back what the
 * OpenRegister base received.
 *
 * @covers \OCA\Learniq\BackgroundJob\ForwardedJob
 * @covers \OCA\Learniq\BackgroundJob\AttendanceSummaryRecomputeJob
 * @covers \OCA\Learniq\BackgroundJob\CohortNameRestampJob
 * @covers \OCA\Learniq\BackgroundJob\CompetencyAttainmentRollupJob
 * @covers \OCA\Learniq\BackgroundJob\EmployerBookingRestampJob
 * @covers \OCA\Learniq\BackgroundJob\EngagementSignalJob
 * @covers \OCA\Learniq\BackgroundJob\EnrolmentProgressRollupJob
 * @covers \OCA\Learniq\BackgroundJob\LearnerEngagementRollupJob
 * @covers \OCA\Learniq\BackgroundJob\LearnerGroupLabelRestampJob
 * @covers \OCA\Learniq\BackgroundJob\SchoolAdviesVoorlopigRodJob
 * @covers \OCA\Learniq\BackgroundJob\XapiStatementFollowUpJob
 * @uses   \OCA\Learniq\BackgroundJob\ActorForwarding
 */
class ActorForwardingWiringTest extends TestCase {

	/**
	 * The deferred jobs.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function jobs(): array {
		$jobs = [
			AttendanceSummaryRecomputeJob::class,
			CohortNameRestampJob::class,
			CompetencyAttainmentRollupJob::class,
			EmployerBookingRestampJob::class,
			EngagementSignalJob::class,
			EnrolmentProgressRollupJob::class,
			LearnerEngagementRollupJob::class,
			LearnerGroupLabelRestampJob::class,
			SchoolAdviesVoorlopigRodJob::class,
			XapiStatementFollowUpJob::class,
		];

		return array_combine($jobs, array_map(static fn (string $job): array => [$job], $jobs));
	}//end jobs()

	/**
	 * The base gets the session, user manager, organisation and logger the job was built with.
	 *
	 * @param class-string $job The job class.
	 *
	 * @return void
	 */
	#[DataProvider('jobs')]
	public function testTheBaseGetsTheForwardingCollaborators(string $job): void {
		$shared = [
			ITimeFactory::class => $this->createMock(ITimeFactory::class),
			IUserSession::class => $this->createMock(IUserSession::class),
			IUserManager::class => $this->createMock(IUserManager::class),
			OrganisationService::class => $this->createMock(OrganisationService::class),
			LoggerInterface::class => $this->createMock(LoggerInterface::class),
		];

		$instance = (new ReflectionClass($job))->newInstanceArgs($this->arguments(job: $job, shared: $shared));

		self::assertInstanceOf(ActorForwardedJob::class, $instance);
		self::assertSame($shared[IUserSession::class], $this->baseValue(job: $instance, property: 'userSession'));
		self::assertSame($shared[IUserManager::class], $this->baseValue(job: $instance, property: 'userManager'));
		self::assertSame($shared[OrganisationService::class], $this->baseValue(job: $instance, property: 'organisation'));
		self::assertSame($shared[LoggerInterface::class], $this->baseValue(job: $instance, property: 'logger'));
	}//end testTheBaseGetsTheForwardingCollaborators()

	/**
	 * One argument per constructor parameter: the shared double for the
	 * forwarding types, a bundle of them, or a fresh double.
	 *
	 * @param class-string         $job    The job class.
	 * @param array<string, object> $shared The shared doubles by type.
	 *
	 * @return list<object>
	 */
	private function arguments(string $job, array $shared): array {
		$arguments = [];
		foreach ((new ReflectionClass($job))->getConstructor()->getParameters() as $parameter) {
			$type = $parameter->getType();
			self::assertInstanceOf(ReflectionNamedType::class, $type);
			$name = $type->getName();
			$arguments[] = match (true) {
				isset($shared[$name]) === true => $shared[$name],
				$name === ActorForwarding::class => new ActorForwarding(
					time: $shared[ITimeFactory::class],
					userSession: $shared[IUserSession::class],
					userManager: $shared[IUserManager::class],
					organisation: $shared[OrganisationService::class]
				),
				(new ReflectionClass($name))->isFinal() === true => (new ReflectionClass($name))->newInstanceWithoutConstructor(),
				default => $this->createMock($name),
			};
		}

		return $arguments;
	}//end arguments()

	/**
	 * A property as OpenRegister's base holds it.
	 *
	 * @param object $job      The job.
	 * @param string $property The base property.
	 *
	 * @return mixed
	 */
	private function baseValue(object $job, string $property): mixed {
		return (new ReflectionProperty(ActorForwardedJob::class, $property))->getValue($job);
	}//end baseValue()
}//end class
