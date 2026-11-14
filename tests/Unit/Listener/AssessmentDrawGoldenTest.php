<?php

/**
 * Learniq AssessmentDrawResolver golden tests.
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
 *
 * @spec openspec/specs/assessment/spec.md
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\Tests\Support\PassedArguments;
use OCA\Learniq\Listener\AssessmentDrawResolver;
use OCA\Learniq\Service\ItemPoolFilter;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Service\QtiChoiceOrderResolver;
use OCA\Learniq\Tests\Support\CapturingLogger;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins every read, the saved drawnItemRefs and the log of handle() per
 * scenario against tests/Unit/Listener/golden/AssessmentDraw.json. A random
 * draw or a shuffled order is recorded with its refs sorted by item id, so
 * only what was drawn is compared, not the random order. With
 * RECORD_GOLDEN=1 the file is written instead of compared.
 *
 * @covers \OCA\Learniq\Listener\AssessmentDrawResolver
 * @uses   \OCA\Learniq\Service\ItemPoolFilter
 * @uses   \OCA\Learniq\Service\AssessmentObjectReads
 */
class AssessmentDrawGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/AssessmentDraw.json';

	/**
	 * Every call, in order.
	 *
	 * @var list<array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * Scenarios: the assessment (null: none), the result, whether the order is random, the schema slug.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:array<string,mixed>,2:bool,3:string}>
	 */
	public static function scenarios(): array {
		$result = ['id' => 'r-1', 'assessmentId' => 'a-1', 'tenant_id' => 't-1', 'responses' => []];
		$fixed = ['id' => 'a-1', 'itemRefs' => [['itemId' => 'i-1', 'points' => 4], ['points' => 2], ['itemId' => 'i-2'], ['itemId' => 'i-missing', 'points' => 1], ['itemId' => 'i-3', 'points' => null]]];
		$pool = ['itemBankId' => 'bank-1', 'drawCount' => 3];
		return [
			'fixed items in order' => [$fixed, $result, false, 'assessment-result'],
			'fixed items, options shuffled' => [$fixed + ['shuffleAnswerOptions' => true], $result, false, 'assessment-result'],
			'fixed items, order shuffled' => [$fixed + ['shuffleItemOrder' => true], $result, true, 'assessment-result'],
			'no item refs' => [['id' => 'a-1'], $result, false, 'assessment-result'],
			'random draw' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => ['itemBankId' => 'bank-1', 'drawCount' => 2, 'difficultyMax' => 0.5]], $result, true, 'assessment-result'],
			'random draw, shuffled, every group' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'shuffleItemOrder' => true, 'itemPoolConfig' => ['itemBankId' => 'bank-1', 'drawCount' => 4]], $result, true, 'assessment-result'],
			'random draw, too few groups' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => ['itemBankId' => 'bank-1', 'drawCount' => 5]], $result, true, 'assessment-result'],
			'random draw, no pool config' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => 'x'], $result, true, 'assessment-result'],
			'random draw, no bank' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => ['drawCount' => 2]], $result, true, 'assessment-result'],
			'random draw, zero count' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => ['itemBankId' => 'bank-1', 'drawCount' => 0]], $result, true, 'assessment-result'],
			'random draw, no tenant, difficulty filter' => [['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => $pool + ['difficultyMax' => 0.5]], ['tenant_id' => null] + $result, true, 'assessment-result'],
			'assessment missing' => [null, $result, false, 'assessment-result'],
			'no assessment id' => [['id' => 'a-1'], ['id' => 'r-1', 'tenant_id' => 't-1'], false, 'assessment-result'],
			'another schema' => [$fixed, $result, false, 'exam'],
		];
	}//end scenarios()

	/**
	 * handle() matches the recorded scenario.
	 *
	 * @param array<string,mixed>|null $assessment The assessment.
	 * @param array<string,mixed>      $result     The created AssessmentResult.
	 * @param bool                     $random     Whether the drawn order is random.
	 * @param string                   $schema     The created object's schema slug.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testHandleMatchesGolden(?array $assessment, array $result, bool $random, string $schema): void {
		$this->calls = [];
		$logger = new CapturingLogger();
		$resolver = new AssessmentDrawResolver($this->objectService(assessment: $assessment), $this->choices(), new ItemPoolFilter(), $this->schemas(schema: $schema), $logger);
		$resolver->handle($this->created(data: $result));
		$resolver->handle(new Event());

		$calls = $this->calls;
		foreach ($calls as $index => $call) {
			if (isset($call['saveObject']) === true) {
				$calls[$index]['saveObject'] = self::comparable(args: $call['saveObject'], random: $random);
			}
		}

		$fetches = [];
		if ($random === true) {
			$fetches = array_values(array_filter($calls, static fn (array $call): bool => isset($call['findAll']['ids']) && $call['findAll']['filters']['schema'] === 'item'));
			$calls = array_values(array_filter($calls, static fn (array $call): bool => in_array($call, $fetches, true) === false));
			usort($fetches, static fn (array $a, array $b): int => strcmp($a['findAll']['ids'][0], $b['findAll']['ids'][0]));
		}

		$this->assertGolden(actual: json_decode((string)json_encode(['calls' => $calls, 'itemFetches' => $fetches, 'log' => $logger->records]), true));
	}//end testHandleMatchesGolden()

	/**
	 * A random draw takes exactly drawCount items, each from another variant group.
	 *
	 * @return void
	 */
	public function testARandomDrawTakesDrawCountItems(): void {
		$this->calls = [];
		$assessment = ['id' => 'a-1', 'itemSelectionMode' => 'random-draw', 'itemPoolConfig' => ['itemBankId' => 'bank-1', 'drawCount' => 3]];
		$resolver = new AssessmentDrawResolver($this->objectService(assessment: $assessment), $this->choices(), new ItemPoolFilter(), $this->schemas(schema: 'assessment-result'), new CapturingLogger());
		$resolver->handle($this->created(data: ['id' => 'r-1', 'assessmentId' => 'a-1', 'tenant_id' => 't-1']));
		$saves = array_values(array_filter($this->calls, static fn (array $call): bool => isset($call['saveObject'])));
		$ids = array_column($saves[0]['saveObject']['object']['drawnItemRefs'], 'itemId');
		$this->assertCount(3, $ids);
		$this->assertCount(3, array_unique($ids));
	}//end testARandomDrawTakesDrawCountItems()

	/**
	 * The saved arguments with option orders replaced by their sorted options, and the refs sorted when the order is random.
	 *
	 * @param array<string,mixed> $args   The saveObject arguments.
	 * @param bool                $random Whether the drawn order is random.
	 *
	 * @return array<string,mixed>
	 */
	private static function comparable(array $args, bool $random): array {
		$refs = $args['object']['drawnItemRefs'];
		foreach ($refs as $index => $ref) {
			if (is_array($ref['optionOrder']) === true) {
				sort($ref['optionOrder']);
				$refs[$index]['optionOrder'] = ['shuffled' => $ref['optionOrder']];
			}
		}

		if ($random === true) {
			usort($refs, static fn (array $a, array $b): int => strcmp($a['itemId'], $b['itemId']));
		}

		$args['object']['drawnItemRefs'] = $refs;
		return $args;
	}//end comparable()

	/**
	 * An ObjectService over a fixed item bank that records every call.
	 *
	 * @param array<string,mixed>|null $assessment The assessment.
	 *
	 * @return ObjectService
	 */
	private function objectService(?array $assessment): ObjectService {
		$choice = '<assessmentItem><choiceInteraction><simpleChoice identifier="A"/><simpleChoice identifier="B" fixed="true"/><simpleChoice identifier="C"/></choiceInteraction></assessmentItem>';
		$items = [
			['id' => 'i-1', 'maxScore' => 2, 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'interactionType' => 'choice', 'qtiBody' => $choice, 'difficulty' => 0.3],
			['id' => 'i-2', 'maxScore' => 5, 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'difficulty' => 0.9],
			['id' => 'i-3', 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'variantGroupId' => 'g-3'],
			['id' => 'i-4', 'maxScore' => 1, 'itemBankId' => 'bank-1', 'lifecycle' => 'published', 'difficulty' => 0.4],
		];
		$service = $this->createMock(ObjectService::class);
		$service->method('findAll')->willReturnCallback(
			function (array $config) use ($assessment, $items): array {
				$this->calls[] = ['findAll' => $config];
				$schema = $config['filters']['schema'];
				if ($schema === 'exam') {
					return array_filter([$assessment], static fn ($row): bool => $row !== null && in_array($row['id'] ?? null, $config['ids'], true));
				}

				if (isset($config['ids']) === true) {
					$items = array_filter($items, static fn (array $row): bool => in_array($row['id'], $config['ids'], true));
				}

				return array_map(static fn (array $row): ObjectEntity => OrEntityFactory::make($row, 'item'), array_values($items));
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (...$args): ObjectEntity {
				$this->calls[] = ['saveObject' => PassedArguments::of(method: [ObjectService::class, 'saveObject'], args: $args)];
				return OrEntityFactory::make([], 'assessment-result');
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A choice order resolver that orders every choice item C, B, A and records its calls.
	 *
	 * @return QtiChoiceOrderResolver
	 */
	private function choices(): QtiChoiceOrderResolver {
		$resolver = $this->createMock(QtiChoiceOrderResolver::class);
		$resolver->method('resolveOrder')->willReturnCallback(
			function (array $item): ?array {
				$this->calls[] = ['resolveOrder' => $item['id']];
				if (($item['interactionType'] ?? null) !== 'choice') {
					return null;
				}

				return ['C', 'B', 'A'];
			}
		);
		return $resolver;
	}//end choices()

	/**
	 * A schema resolver that reads every object as learniq/$schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function schemas(string $schema): ListenerSchemaResolver {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('registerSlug')->willReturn('learniq');
		$resolver->method('schemaSlug')->willReturn($schema);
		return $resolver;
	}//end schemas()

	/**
	 * An ObjectCreatedEvent for the result.
	 *
	 * @param array<string,mixed> $data The result.
	 *
	 * @return ObjectCreatedEvent
	 */
	private function created(array $data): ObjectCreatedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);
		$event = $this->createMock(ObjectCreatedEvent::class);
		$event->method('getObject')->willReturn($entity);
		return $event;
	}//end created()

	/**
	 * Compare with, or record, this scenario's golden entry.
	 *
	 * @param mixed $actual What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(mixed $actual): void {
		$name   = (string)$this->dataName();
		$golden = [];
		if (file_exists(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertArrayHasKey($name, $golden);
		$this->assertSame($golden[$name], $actual);
	}//end assertGolden()
}//end class
