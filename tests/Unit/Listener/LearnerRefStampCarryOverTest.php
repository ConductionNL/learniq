<?php

/**
 * Learniq learnerRef stamp carry-over unit tests.
 *
 * Pins what GradeEntryLearnerRefStamp and LvsResultLearnerRefStamp do on a
 * failed lookup: the warning they log, the stored value they keep or drop,
 * and how a non-string learnerId or tenant_id is read.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Listener
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
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Listener\GradeEntryLearnerRefStamp;
use OCA\Learniq\Listener\LvsResultLearnerRefStamp;
use OCA\Learniq\Service\LearnerRefResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\IEventListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the failed-lookup paths of the two learnerRef stamps.
 */
class LearnerRefStampCarryOverTest extends TestCase {

	/**
	 * Warnings the logger received, as [message, context] pairs.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Calls the resolver received, as [method, learnerId, tenantId] triples.
	 *
	 * @var list<array{0: string, 1: string, 2: string}>
	 */
	private array $lookups = [];

	/**
	 * A resolver that records each lookup and fails or answers.
	 *
	 * @param string|null $answer The profile UUID to answer, null to fail.
	 *
	 * @return LearnerRefResolver
	 */
	private function resolver(?string $answer): LearnerRefResolver {
		$resolver = $this->createMock(LearnerRefResolver::class);
		$respond = function (string $method, string $learnerId, string $tenantId) use ($answer): ?string {
			$this->lookups[] = [$method, $learnerId, $tenantId];
			if ($answer === null) {
				throw new RuntimeException('database gone');
			}

			return $answer;
		};
		$resolver->method('resolve')->willReturnCallback(
			fn (string $learnerId): ?string => $respond('resolve', $learnerId, '')
		);
		$resolver->method('resolveInTenant')->willReturnCallback(
			fn (string $learnerId, string $tenantId): ?string => $respond('resolveInTenant', $learnerId, $tenantId)
		);
		return $resolver;
	}//end resolver()

	/**
	 * A logger that records each warning.
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
	 * The stamp under test for a schema slug.
	 *
	 * @param string $slug 'grade-entry' or 'lvs-result'.
	 * @param string|null $answer The resolver's answer, null to fail.
	 *
	 * @return IEventListener
	 */
	private function stamp(string $slug, ?string $answer): IEventListener {
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);
		if ($slug === 'grade-entry') {
			return new GradeEntryLearnerRefStamp(
				schemaResolver: $schemaResolver,
				learnerRefs: $this->resolver(answer: $answer),
				logger: $this->logger(),
			);
		}

		return new LvsResultLearnerRefStamp(
			schemaResolver: $schemaResolver,
			learnerRefs: $this->resolver(answer: $answer),
			logger: $this->logger(),
		);
	}//end stamp()

	/**
	 * The slugs and the class names their warnings carry.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function stamps(): array {
		return [
			'grade entry' => ['grade-entry', 'GradeEntryLearnerRefStamp'],
			'lvs result'  => ['lvs-result', 'LvsResultLearnerRefStamp'],
		];
	}//end stamps()

	/**
	 * A failed lookup on update logs the value it keeps.
	 *
	 * @param string $slug The schema slug.
	 * @param string $label The class name in the warning.
	 *
	 * @return void
	 *
	 * @dataProvider stamps
	 */
	public function testAFailedLookupLogsTheKeptValue(string $slug, string $label): void {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(['id' => 'x-1', 'learnerId' => 'pupil-1'], $slug),
			OrEntityFactory::make(['id' => 'x-1', 'learnerId' => 'pupil-1', 'learnerRef' => 'lp-1'], $slug)
		);
		$this->stamp(slug: $slug, answer: null)->handle($event);

		self::assertSame('lp-1', $event->getModifiedData()['learnerRef']);
		self::assertSame(
			[['['.$label.'] Could not resolve the learner profile, keeping {kept}: {msg}', ['kept' => 'lp-1', 'msg' => 'database gone']]],
			$this->warnings
		);
	}//end testAFailedLookupLogsTheKeptValue()

	/**
	 * A failed lookup on create logs that it keeps null.
	 *
	 * @param string $slug The schema slug.
	 * @param string $label The class name in the warning.
	 *
	 * @return void
	 *
	 * @dataProvider stamps
	 */
	public function testAFailedLookupOnCreateLogsNull(string $slug, string $label): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['id' => 'x-1', 'learnerId' => 'pupil-1'], $slug));
		$this->stamp(slug: $slug, answer: null)->handle($event);

		self::assertNull($event->getModifiedData()['learnerRef']);
		self::assertSame(
			[['['.$label.'] Could not resolve the learner profile, keeping {kept}: {msg}', ['kept' => 'null', 'msg' => 'database gone']]],
			$this->warnings
		);
	}//end testAFailedLookupOnCreateLogsNull()

	/**
	 * An empty or non-string stored learnerRef is not kept on a failed lookup.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 *
	 * @dataProvider stamps
	 */
	public function testAnEmptyStoredValueIsNotKept(string $slug): void {
		foreach (['', 42] as $storedRef) {
			$event = new ObjectUpdatingEvent(
				OrEntityFactory::make(['id' => 'x-1', 'learnerId' => 'pupil-1'], $slug),
				OrEntityFactory::make(['id' => 'x-1', 'learnerId' => 'pupil-1', 'learnerRef' => $storedRef], $slug)
			);
			$this->stamp(slug: $slug, answer: null)->handle($event);

			self::assertNull($event->getModifiedData()['learnerRef']);
		}
	}//end testAnEmptyStoredValueIsNotKept()

	/**
	 * A non-string learnerId is looked up as the empty string.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return void
	 *
	 * @dataProvider stamps
	 */
	public function testANonStringLearnerIdIsReadAsEmpty(string $slug): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['id' => 'x-1', 'learnerId' => 7], $slug));
		$this->stamp(slug: $slug, answer: 'lp-9')->handle($event);

		self::assertSame('lp-9', $event->getModifiedData()['learnerRef']);
		self::assertSame([['resolve', '', '']], $this->lookups);
	}//end testANonStringLearnerIdIsReadAsEmpty()

	/**
	 * Only an LvsResult is looked up in the tenant it carries; a non-string
	 * tenant falls back to the session, and a grade entry always uses it.
	 *
	 * @return void
	 */
	public function testOnlyTheLvsResultUsesItsOwnTenant(): void {
		$payloads = [
			['id' => 'x-1', 'learnerId' => 'pupil-1', 'tenant_id' => 't-a'],
			['id' => 'x-1', 'learnerId' => 'pupil-1', 'tenant_id' => 9],
		];
		foreach (['grade-entry', 'lvs-result'] as $slug) {
			foreach ($payloads as $payload) {
				$this->stamp(slug: $slug, answer: 'lp-1')->handle(new ObjectCreatingEvent(OrEntityFactory::make($payload, $slug)));
			}
		}

		self::assertSame(
			[
				['resolve', 'pupil-1', ''],
				['resolve', 'pupil-1', ''],
				['resolveInTenant', 'pupil-1', 't-a'],
				['resolve', 'pupil-1', ''],
			],
			$this->lookups
		);
	}//end testOnlyTheLvsResultUsesItsOwnTenant()
}//end class
