<?php

/**
 * Golden tests for OsoDossierReviewGuard.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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
 *
 * @spec openspec/specs/data-exchange/spec.md#requirement-the-gate-refuses-an-oso-or-swv-file-until-a-parent-approved-it
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\OsoDossierReviewGuard;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only a parent listed on the learner's profile may decide on an OSO dossier review.
 */
class OsoDossierReviewGuardTest extends TestCase {

	/**
	 * The profile filters asked for, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * What the guard logged, as [level, message, context].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $logged = [];

	/**
	 * A guard over a store answering the given profiles for every query.
	 *
	 * @param array<int, mixed> $profiles The profiles.
	 *
	 * @return OsoDossierReviewGuard
	 */
	private function guard(array $profiles): OsoDossierReviewGuard {
		$this->queries = [];
		$this->logged = [];
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($profiles): array {
				$this->queries[] = $config;
				return $profiles;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new OsoDossierReviewGuard(objectService: $objectService, logger: $logger);
	}//end guard()

	/**
	 * A listed parent is allowed, reading the profile within the review's tenant.
	 *
	 * @return void
	 */
	public function testAListedParentIsAllowed(): void {
		$guard = $this->guard([['ncUserId' => 'kind-1', 'parentIds' => ['ouder-1', 'ouder-2']]]);

		$verdict = $guard->check(['id' => 'r-1', 'learnerUserId' => 'kind-1', 'tenant_id' => 't-1'], 'approve', 'ouder-2');

		self::assertTrue($verdict->isAllowed());
		self::assertSame(
			[['filters' => ['ncUserId' => 'kind-1', 'tenant_id' => 't-1', 'register' => 'learniq', 'schema' => 'learner-profile'], 'limit' => 1]],
			$this->queries
		);
		self::assertSame([], $this->logged);

		$entity = $this->guard([OrEntityFactory::make(['id' => 'p-1', 'ncUserId' => 'kind-1', 'parentIds' => ['ouder-1']], 'learner-profile')]);
		self::assertTrue($entity->check(['learnerUserId' => 'kind-1'], 'reject', 'ouder-1')->isAllowed());
		self::assertSame([['filters' => ['ncUserId' => 'kind-1', 'register' => 'learniq', 'schema' => 'learner-profile'], 'limit' => 1]], $this->queries);
	}//end testAListedParentIsAllowed()

	/**
	 * Every refusal: no actor, no learner, no profile, no parent list, and an
	 * actor who is not a listed parent, each with its own log line.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsLoggedAndDenied(): void {
		$profile = [['ncUserId' => 'kind-1', 'parentIds' => ['ouder-1']]];
		$cases = [
			'no actor' => [$profile, ['learnerUserId' => 'kind-1'], '', [['warning', '[OsoDossierReviewGuard] No acting user — denying approveDossier.', []]], 0],
			'no learner' => [$profile, ['id' => 'r-7', 'learnerUserId' => ''], 'ouder-1', [['warning', '[OsoDossierReviewGuard] Review {id}: no learnerUserId — cannot verify parent.', ['id' => 'r-7']]], 0],
			'no learner, no id' => [$profile, [], 'ouder-1', [['warning', '[OsoDossierReviewGuard] Review {id}: no learnerUserId — cannot verify parent.', ['id' => '?']]], 0],
			'no profile' => [[], ['learnerUserId' => 'kind-1'], 'ouder-1', [['warning', '[OsoDossierReviewGuard] No LearnerProfile found for learnerId {l} — denying.', ['l' => 'kind-1']]], 1],
			'no parent list' => [[['ncUserId' => 'kind-1']], ['learnerUserId' => 'kind-1'], 'ouder-1', [['info', '[OsoDossierReviewGuard] Actor {a} is not in parentIds for learner {l} — denying.', ['a' => 'ouder-1', 'l' => 'kind-1']]], 1],
			'not a parent' => [$profile, ['learnerUserId' => 'kind-1', 'tenant_id' => ''], 'leraar-1', [['info', '[OsoDossierReviewGuard] Actor {a} is not in parentIds for learner {l} — denying.', ['a' => 'leraar-1', 'l' => 'kind-1']]], 1],
		];

		foreach ($cases as $label => [$profiles, $object, $actor, $logged, $queries]) {
			$verdict = $this->guard($profiles)->check($object, 'approve', $actor);

			self::assertFalse($verdict->isAllowed(), $label);
			self::assertSame('Only a parent of this learner can approve the dossier transfer.', $verdict->getMessage(), $label);
			self::assertSame($logged, $this->logged, $label);
			self::assertCount($queries, $this->queries, $label);
		}
	}//end testEveryRefusalIsLoggedAndDenied()
}//end class
