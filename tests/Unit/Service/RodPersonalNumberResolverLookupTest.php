<?php

/**
 * RodPersonalNumberResolver lookup test: the profile query per learner, every
 * answer, and the checks' negative-sum edge.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
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
 * @spec openspec/specs/data-exchange/spec.md#requirement-a-school-advice-goes-to-rod-with-duos-aanleverenadviesvo-field-set
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

use OCA\Learniq\Service\RodPersonalNumberResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins RodPersonalNumberResolver::forLearner and the checks' edges.
 */
class RodPersonalNumberResolverLookupTest extends TestCase {

	/**
	 * Every findAll call and log line, in order.
	 *
	 * @var \ArrayObject<int,mixed>
	 */
	private \ArrayObject $calls;

	/**
	 * Fresh record.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new \ArrayObject();
	}//end setUp()

	/**
	 * A resolver whose findAll answers with the given results, or throws.
	 *
	 * @param array<int,mixed>|null $results The results, or null to throw.
	 *
	 * @return RodPersonalNumberResolver
	 */
	private function resolver(?array $results): RodPersonalNumberResolver {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($results): array {
				$this->calls[] = ['findAll', $config, $_rbac, $_multitenancy];
				if ($results === null) {
					throw new RuntimeException('store down');
				}

				return $results;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = ['info', $message, $context];
			}
		);

		return new RodPersonalNumberResolver($objects, $logger);
	}//end resolver()

	/**
	 * The query is exact with and without a tenant, and each answer is exact:
	 * a valid pair from an array or an entity, another tenant, an invalid
	 * number, no row, a failed read and an empty user id.
	 *
	 * @return void
	 */
	public function testEachLookupAndAnswerIsExact(): void {
		$none = ['persoonsgebondenNummer' => null, 'persoonsgebondenNummerType' => null];
		$row  = ['tenant_id' => 't1', 'personalNumber' => '101234564', 'personalNumberType' => 'onderwijsnummer'];

		self::assertSame(
			['persoonsgebondenNummer' => '101234564', 'persoonsgebondenNummerType' => 'onderwijsnummer'],
			$this->resolver([$row])->forLearner('pupil', 't1')
		);
		self::assertSame(
			[['findAll', ['filters' => ['register' => 'learniq', 'schema' => 'learner-profile', 'ncUserId' => 'pupil', 'tenant_id' => 't1'], 'limit' => 1], false, false]],
			$this->calls->getArrayCopy()
		);

		$this->setUp();
		$entity = OrEntityFactory::make(['tenant_id' => 'other', 'personalNumber' => '111222333'], 'learner-profile');
		self::assertSame(
			['persoonsgebondenNummer' => '111222333', 'persoonsgebondenNummerType' => 'burgerservicenummer'],
			$this->resolver([$entity])->forLearner('pupil', '')
		);
		self::assertSame(['register' => 'learniq', 'schema' => 'learner-profile', 'ncUserId' => 'pupil'], $this->calls[0][1]['filters']);

		self::assertSame($none, $this->resolver([$row])->forLearner('pupil', 't2'));
		self::assertSame($none, $this->resolver([['personalNumber' => '123456789']])->forLearner('pupil', ''));
		self::assertSame($none, $this->resolver([['personalNumber' => 123456782]])->forLearner('pupil', ''));
		self::assertSame($none, $this->resolver([])->forLearner('pupil', 't1'));

		$this->setUp();
		self::assertSame($none, $this->resolver(null)->forLearner('pupil', 't1'));
		self::assertSame(
			['info', '[RodPersonalNumberResolver] the learner profile of a school advice could not be read ({class}).', ['class' => RuntimeException::class]],
			$this->calls[1]
		);

		$this->setUp();
		self::assertSame($none, $this->resolver([$row])->forLearner('', 't1'));
		self::assertSame([], $this->calls->getArrayCopy());
	}//end testEachLookupAndAnswerIsExact()

	/**
	 * A weighted sum below the ninth digit still lands in 0..10.
	 *
	 * @return void
	 */
	public function testANegativeSumStillChecks(): void {
		self::assertTrue(RodPersonalNumberResolver::isValid('000000006', 'onderwijsnummer'));
		self::assertFalse(RodPersonalNumberResolver::isValid('000000006', 'bsn'));
		self::assertFalse(RodPersonalNumberResolver::isValid('000000005', 'onderwijsnummer'));
		self::assertTrue(RodPersonalNumberResolver::isValid('100000009', 'bsn'));
	}//end testANegativeSumStillChecks()
}//end class
