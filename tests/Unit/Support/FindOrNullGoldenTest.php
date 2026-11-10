<?php

/**
 * Learniq find-or-null characterization test.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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

namespace OCA\Learniq\Tests\Unit\Support;

use OCA\Learniq\Tests\Support\FindCallShape;
use OCA\Learniq\Controller\CheckInCodeController;
use OCA\Learniq\Controller\ComplianceRollupController;
use OCA\Learniq\Controller\CredentialEuropassController;
use OCA\Learniq\Controller\LeaderboardController;
use OCA\Learniq\Controller\LessonNextStepController;
use OCA\Learniq\Controller\LessonReleaseController;
use OCA\Learniq\Controller\PeerReviewController;
use OCA\Learniq\Controller\SubmissionMarkController;
use OCA\Learniq\Grading\GradeFormulaEvaluator;
use OCA\Learniq\Listener\GradeRollupHandler;
use OCA\Learniq\Listener\SubmissionOwnerStamp;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\Catalogue\CatalogueSignUpService;
use OCA\Learniq\Service\CheckIn\CheckInService;
use OCA\Learniq\Service\EuropassIssuer;
use OCA\Learniq\Service\ExternalTrainingLearnerMatch;
use OCA\Learniq\Service\ExternalTrainingService;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\PokParentSignatureRule;
use OCA\Learniq\Service\Portal\PortalAttemptReader;
use OCA\Learniq\Service\Portal\PortalSubmissionHandIn;
use OCA\Learniq\Service\Programme\ProgrammeProgress;
use OCA\Learniq\Service\Signing\SigningService;
use OCA\Learniq\Service\WorkGroup\WorkGroupReader;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * Pins what every single-object OpenRegister read in learniq answers when
 * find() reports the object missing, returns null, returns a row, or fails,
 * against tests/Unit/Support/golden/FindOrNull.json. With RECORD_GOLDEN=1
 * the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Support\FindOrNull
 * @covers \OCA\Learniq\Controller\CheckInCodeController
 * @covers \OCA\Learniq\Controller\ComplianceRollupController
 * @covers \OCA\Learniq\Controller\CredentialEuropassController
 * @covers \OCA\Learniq\Controller\LeaderboardController
 * @covers \OCA\Learniq\Controller\LessonNextStepController
 * @covers \OCA\Learniq\Controller\LessonReleaseController
 * @covers \OCA\Learniq\Controller\PeerReviewController
 * @covers \OCA\Learniq\Controller\SubmissionMarkController
 * @covers \OCA\Learniq\Grading\GradeFormulaEvaluator
 * @covers \OCA\Learniq\Listener\GradeRollupHandler
 * @covers \OCA\Learniq\Listener\SubmissionOwnerStamp
 * @covers \OCA\Learniq\Service\CallerTenantResolver
 * @covers \OCA\Learniq\Service\Catalogue\CatalogueSignUpService
 * @covers \OCA\Learniq\Service\CheckIn\CheckInService
 * @covers \OCA\Learniq\Service\EuropassIssuer
 * @covers \OCA\Learniq\Service\ExternalTrainingLearnerMatch
 * @covers \OCA\Learniq\Service\ExternalTrainingService
 * @covers \OCA\Learniq\Service\LearnerRefResolver
 * @covers \OCA\Learniq\Service\PokParentSignatureRule
 * @covers \OCA\Learniq\Service\Portal\PortalAttemptReader
 * @covers \OCA\Learniq\Service\Portal\PortalSubmissionHandIn
 * @covers \OCA\Learniq\Service\Programme\ProgrammeProgress
 * @covers \OCA\Learniq\Service\Signing\SigningService
 * @covers \OCA\Learniq\Service\WorkGroup\WorkGroupReader
 * @uses   \OCA\Learniq\Exception\SigningRefusedException
 */
class FindOrNullGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/FindOrNull.json';

	private const ID = '0a1b2c3d-0000-4000-8000-000000000001';

	/**
	 * Each read: class, method and the named arguments it is called with.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: array<string, mixed>}>
	 */
	private function sites(): array {
		$byId     = ['id' => self::ID, 'schema' => 'thing'];
		$user     = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		return [
			'CheckInCodeController::code' => [CheckInCodeController::class, 'code', ['windowId' => self::ID]],
			'ComplianceRollupController::assignRegulation' => [ComplianceRollupController::class, 'assignRegulation', ['id' => self::ID]],
			'CredentialEuropassController::read' => [CredentialEuropassController::class, 'read', $byId],
			'LeaderboardController::fetchObject' => [LeaderboardController::class, 'fetchObject', $byId],
			'LessonNextStepController::read' => [LessonNextStepController::class, 'read', $byId],
			'LessonReleaseController::resolveObject' => [LessonReleaseController::class, 'resolveObject', $byId],
			'PeerReviewController::fetchObject' => [PeerReviewController::class, 'fetchObject', $byId],
			'SubmissionMarkController::read' => [SubmissionMarkController::class, 'read', $byId],
			'GradeFormulaEvaluator::systemRead' => [GradeFormulaEvaluator::class, 'systemRead', $byId],
			'GradeRollupHandler::fetchGradeVisibilityPolicy' => [GradeRollupHandler::class, 'fetchGradeVisibilityPolicy', ['curriculumPlanId' => self::ID]],
			'SubmissionOwnerStamp::assignment' => [SubmissionOwnerStamp::class, 'assignment', ['id' => self::ID]],
			'CallerTenantResolver::findOwned' => [CallerTenantResolver::class, 'findOwned', ['user' => $user] + $byId],
			'CatalogueSignUpService::read' => [CatalogueSignUpService::class, 'read', $byId],
			'CheckInService::read' => [CheckInService::class, 'read', $byId],
			'EuropassIssuer::read' => [EuropassIssuer::class, 'read', $byId],
			'ExternalTrainingLearnerMatch::profileById' => [ExternalTrainingLearnerMatch::class, 'profileById', ['id' => self::ID, 'tenantId' => 't1']],
			'ExternalTrainingService::profileUserId' => [ExternalTrainingService::class, 'profileUserId', ['profileId' => self::ID]],
			'LearnerRefResolver::byRef' => [LearnerRefResolver::class, 'byRef', ['learnerRef' => self::ID]],
			'PokParentSignatureRule::row' => [PokParentSignatureRule::class, 'row', $byId],
			'PortalAttemptReader::one' => [PortalAttemptReader::class, 'one', $byId],
			'PortalSubmissionHandIn::submission' => [PortalSubmissionHandIn::class, 'submission', ['id' => self::ID]],
			'ProgrammeProgress::read' => [ProgrammeProgress::class, 'read', $byId],
			'SigningService::load' => [SigningService::class, 'load', $byId],
			'SigningService::findPlacement' => [SigningService::class, 'findPlacement', ['placementId' => self::ID]],
			'WorkGroupReader::read' => [WorkGroupReader::class, 'read', $byId],
		];
	}//end sites()

	/**
	 * What find() does in each case.
	 *
	 * @return array<string, callable(): mixed>
	 */
	private static function answers(): array {
		$row = [
			'id' => self::ID, 'tenant_id' => 't1', 'ncUserId' => 'ann', 'lifecycle' => 'published',
			'active' => true, 'mode' => 'fixed', 'gradeVisibilityPolicy' => ['showTo' => 'learner'],
		];
		return [
			'missing' => static function (): never {
				throw new DoesNotExistException('missing');
			},
			'null' => static fn (): mixed => null,
			'found' => static fn (): mixed => OrEntityFactory::make($row, 'thing'),
			'found without id' => static fn (): mixed => OrEntityFactory::make(['lifecycle' => 'active'] + array_diff_key($row, ['id' => true]), 'thing'),
			'found in another tenant' => static fn (): mixed => OrEntityFactory::make(['tenant_id' => 't2'] + $row, 'thing'),
			'found with only an @self id' => static fn (): mixed => new class extends ObjectEntity {
				/**
				 * A row whose id sits in its @self block only.
				 *
				 * @return array<string, mixed>
				 */
				public function jsonSerialize(): array {
					return ['@self' => ['id' => 'self-id'], 'tenant_id' => 't1', 'lifecycle' => 'active', 'ncUserId' => 'ann'];
				}//end jsonSerialize()
			},
			'fault' => static function (): never {
				throw new RuntimeException('fault');
			},
		];
	}//end answers()

	/**
	 * Every read answers each case as recorded, with the recorded find() arguments.
	 *
	 * @return void
	 */
	public function testEveryReadAnswersAsRecorded(): void {
		$actual = [];
		foreach ($this->sites() as $name => [$class, $method, $args]) {
			foreach (self::answers() as $case => $answer) {
				$actual[$name][$case] = $this->outcome($class, $method, $args, $answer);
			}
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testEveryReadAnswersAsRecorded()

	/**
	 * Call one read with find() answering one way; return the calls and the outcome.
	 *
	 * @param class-string         $class  The class.
	 * @param string               $method The method.
	 * @param array<string, mixed> $args   Its named arguments.
	 * @param callable(): mixed    $answer What find() does.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $class, string $method, array $args, callable $answer): array {
		$calls   = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('find')->willReturnCallback(
			static function (...$values) use ($answer, &$calls): mixed {
				$calls[] = FindCallShape::named(args: $values);
				return $answer();
			}
		);
		$service->method('findAll')->willReturn([]);

		$subject = $this->subject($class, $service);
		try {
			$result = (new ReflectionMethod($class, $method))->invokeArgs($subject, $args);
			$out    = ['returned' => self::plain($result)];
		} catch (Throwable $thrown) {
			$out = ['threw' => get_class($thrown), 'message' => $thrown->getMessage()];
		}

		return ['find' => $calls] + $out;
	}//end outcome()

	/**
	 * An instance of the class without its constructor, with the given
	 * ObjectService and a mock for every other object-typed dependency.
	 *
	 * @param class-string  $class   The class.
	 * @param ObjectService $service The ObjectService double.
	 *
	 * @return object
	 */
	private function subject(string $class, ObjectService $service): object {
		$reflection = new ReflectionClass($class);
		$subject    = $reflection->newInstanceWithoutConstructor();
		foreach ($reflection->getProperties() as $property) {
			$type = $property->getType();
			if ($property->isStatic() === true || ($type instanceof ReflectionNamedType) === false || $type->isBuiltin() === true) {
				continue;
			}

			$value = $this->dependency($type->getName(), $service);
			if ($value === null) {
				continue;
			}

			$set = function (object $value) use ($property): void {
				$this->{$property->getName()} = $value;
			};
			\Closure::bind($set, $subject, $property->getDeclaringClass()->getName())($value);
		}

		return $subject;
	}//end subject()

	/**
	 * A double for one dependency type, or null when it cannot be made.
	 *
	 * @param string        $type    The declared type.
	 * @param ObjectService $service The ObjectService double.
	 *
	 * @return object|null
	 */
	private function dependency(string $type, ObjectService $service): ?object {
		if ($type === ObjectService::class) {
			return $service;
		}

		if ((new ReflectionClass($type))->isFinal() === true) {
			return null;
		}

		$double = $this->createMock($type);
		if ($double instanceof IUserSession) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('ann');
			$double->method('getUser')->willReturn($user);
		}

		if ($double instanceof IGroupManager) {
			$double->method('isAdmin')->willReturn(true);
		}

		if ($double instanceof IConfig) {
			$double->method('getUserValue')->willReturn('t1');
		}

		return $double;
	}//end dependency()

	/**
	 * A result as plain data, without OpenRegister's version-dependent @self block.
	 *
	 * @param mixed $result The result.
	 *
	 * @return mixed
	 */
	private static function plain(mixed $result): mixed {
		if ($result instanceof JSONResponse) {
			return ['status' => $result->getStatus(), 'data' => self::plain($result->getData())];
		}

		if ($result instanceof \JsonSerializable) {
			$result = ['entity' => $result->jsonSerialize()];
		}

		if (is_array($result) === true) {
			unset($result['@self']);
			return array_map(static fn (mixed $value): mixed => self::plain($value), $result);
		}

		return $result;
	}//end plain()
}//end class
