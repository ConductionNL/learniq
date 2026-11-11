<?php

/**
 * Golden tests for PortfolioSubmissionGuard and PortfolioShareGrantHandler.
 *
 * Pins the submit guard's allowed and refused outcomes with their log lines
 * and queries, and the teacher file-share grant's full sequence of lookups,
 * Nextcloud Files share calls and log lines, including every early exit.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Lifecycle
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

namespace OCA\Learniq\Tests\Unit\Lifecycle;

use OCA\Learniq\Lifecycle\LearnerCaller;
use OCA\Learniq\Lifecycle\PortfolioShareGrantHandler;
use OCA\Learniq\Lifecycle\PortfolioSubmissionGuard;
use OCA\Learniq\Tests\Support\TransitionScope;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IGroupManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../../Support/SlugMapper.php';
require_once __DIR__ . '/../../Support/TransitionScope.php';

/**
 * Golden tests for the portfolio submit guard and the teacher share grant.
 */
class PortfolioSharingGoldenTest extends TestCase {

	/**
	 * Every collaborator call and log line, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $trace = [];

	/**
	 * An object service answering per schema and recording each query.
	 *
	 * @param array<string,array<int,mixed>> $bySchema Rows per schema slug.
	 *
	 * @return ObjectService
	 */
	private function objects(array $bySchema): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($bySchema): array {
				$this->trace[] = ['findAll', $config];
				return $bySchema[$config['filters']['schema']] ?? [];
			}
		);

		return $objects;
	}//end objects()

	/**
	 * A logger recording into the trace.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$test = $this;
		return new class($test) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param PortfolioSharingGoldenTest $test Recorder.
			 */
			public function __construct(private PortfolioSharingGoldenTest $test) {
			}

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array<mixed>       $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->trace[] = ['log', (string)$level, (string)$message, $context];
			}
		};
	}//end logger()

	/**
	 * An entity whose jsonSerialize() returns the row.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return object
	 */
	private static function entity(array $row): object {
		return new class($row) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $row The row.
			 */
			public function __construct(private array $row) {
			}

			/**
			 * Serialised row.
			 *
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->row;
			}
		};
	}//end entity()

	/**
	 * Run the submit guard.
	 *
	 * @param array<string,mixed>            $portfolio The Portfolio.
	 * @param array<string,array<int,mixed>> $bySchema  Rows per schema.
	 *
	 * @return bool
	 */
	private function submit(array $portfolio, array $bySchema): bool {
		$guard = new PortfolioSubmissionGuard($this->objects($bySchema), $this->logger(), new LearnerCaller($this->createMock(IGroupManager::class)));
		return $guard->check($portfolio, 'submit', '')->isAllowed();
	}//end submit()

	/**
	 * The submit guard: no template, empty or id-less sections allow; an unresolvable template refuses.
	 *
	 * @return void
	 */
	public function testSubmitGuardWithoutRequirements(): void {
		self::assertTrue($this->submit(['id' => 'p-1'], []));
		self::assertTrue($this->submit(['id' => 'p-1', 'templateId' => ''], []));
		self::assertSame([], $this->trace);

		self::assertTrue($this->submit(['id' => 'p-1', 'templateId' => 'tpl-1'], ['portfolio-template' => [['sections' => []]]]));
		self::assertTrue($this->submit(['id' => 'p-1', 'templateId' => 'tpl-1'], ['portfolio-template' => [self::entity(['sections' => [['title' => 'x'], ['sectionId' => '']]])]]));
		self::assertSame(
			['findAll', ['ids' => ['tpl-1'], 'filters' => ['register' => 'learniq', 'schema' => 'portfolio-template'], 'limit' => 1]],
			$this->trace[0]
		);
		self::assertCount(2, $this->trace);

		$this->trace = [];
		self::assertFalse($this->submit(['uuid' => 'p-2', 'templateId' => 'tpl-9'], []));
		self::assertSame(
			[
				'log',
				'warning',
				'[PortfolioSubmissionGuard] Portfolio {id} references PortfolioTemplate {templateId} '
				. 'which could not be resolved; blocking submit.',
				['id' => 'p-2', 'templateId' => 'tpl-9'],
			],
			$this->trace[1]
		);
	}//end testSubmitGuardWithoutRequirements()

	/**
	 * The submit guard: every required section covered allows with an info line; a gap refuses naming the sections.
	 *
	 * @return void
	 */
	public function testSubmitGuardSectionCoverage(): void {
		$template = ['portfolio-template' => [['sections' => [['sectionId' => 's1'], ['sectionId' => 's2'], ['sectionId' => 's3']]]]];
		$covered = $template + ['portfolio-entry' => [['sectionId' => 's1'], self::entity(['sectionId' => 's2']), ['sectionId' => 's3'], ['sectionId' => '']]];

		self::assertTrue($this->submit(['id' => 'p-1', 'templateId' => 'tpl-1'], $covered));
		self::assertSame(['findAll', ['filters' => ['register' => 'learniq', 'schema' => 'portfolio-entry', 'portfolioId' => 'p-1']]], $this->trace[1]);
		self::assertSame(
			['log', 'info', '[PortfolioSubmissionGuard] Portfolio {id} has evidence for every required section — allowing submit.', ['id' => 'p-1']],
			$this->trace[2]
		);

		$this->trace = [];
		self::assertFalse($this->submit(['id' => 'p-1', 'templateId' => 'tpl-1'], $template + ['portfolio-entry' => [['sectionId' => 's2']]]));
		self::assertSame(
			[
				'log',
				'info',
				'[PortfolioSubmissionGuard] Portfolio {id} is missing evidence for required section(s) '
				. '{sections}; blocking submit.',
				['id' => 'p-1', 'sections' => 's1, s3'],
			],
			$this->trace[2]
		);
		self::assertCount(3, $this->trace);
	}//end testSubmitGuardSectionCoverage()

	/**
	 * Run the grant for a share.
	 *
	 * @param array<string,mixed>            $share    The PortfolioShare.
	 * @param array<string,array<int,mixed>> $bySchema Rows per schema.
	 * @param array<int,string>              $existing Who each attachment is already shared with.
	 *
	 * @return void
	 */
	private function grant(array $share, array $bySchema, array $existing = []): void {
		$shares = $this->createMock(IManager::class);
		$shares->method('getSharesBy')->willReturnCallback(
			function (string $userId, int $shareType, $path) use ($existing): array {
				$this->trace[] = ['getSharesBy', $userId, $shareType, $path->getName()];
				return array_map(
					function (string $who): IShare {
						$s = $this->createMock(IShare::class);
						$s->method('getSharedWith')->willReturn($who);
						return $s;
					},
					$existing
				);
			}
		);
		$shares->method('newShare')->willReturnCallback(
			function (): IShare {
				$share = $this->createMock(IShare::class);
				foreach (['setNode', 'setShareType', 'setSharedWith', 'setSharedBy', 'setPermissions'] as $setter) {
					$share->method($setter)->willReturnCallback(
						function ($value) use ($setter, $share): IShare {
							$this->trace[] = [$setter, $value instanceof Node ? $value->getName() : $value];
							return $share;
						}
					);
				}

				return $share;
			}
		);
		$shares->method('createShare')->willReturnCallback(
			function (IShare $share): IShare {
				$this->trace[] = ['createShare'];
				return $share;
			}
		);

		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $path): Node {
				$this->trace[] = ['get', $path];
				if ($path === 'gone.pdf') {
					throw new NotFoundException();
				}

				$node = $this->createMock(Node::class);
				$node->method('getName')->willReturn($path);
				return $node;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($folder): Folder {
				$this->trace[] = ['getUserFolder', $uid];
				return $folder;
			}
		);

		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($share);
		$event = $this->createMock(ObjectTransitionedEvent::class);
		$event->method('getObject')->willReturn($entity);
		$event->method('getRegister')->willReturn('learniq');
		$event->method('getSchema')->willReturn('portfolio-share');
		$event->method('getTo')->willReturn('active');

		(new PortfolioShareGrantHandler($this->objects($bySchema), $shares, $root, $this->logger(), TransitionScope::resolver()))->handle($event);
	}//end grant()

	/**
	 * The teacher grant shares each file entry the filter admits, once, read-only, and logs the count.
	 *
	 * @return void
	 */
	public function testTeacherGrantSequence(): void {
		$share = ['id' => 'sh-1', 'portfolioId' => 'p-1', 'sharedWithKind' => 'teacher', 'sharedWithTeacherId' => 'mentor', 'entryIds' => ['e1', 'e2', 'e3', 'e5']];
		$rows = [
			'portfolio' => [self::entity(['id' => 'p-1', 'learnerId' => 'lea'])],
			'portfolio-entry' => [
				['id' => 'e1', 'evidenceKind' => 'file', 'attachmentRef' => 'a.pdf'],
				self::entity(['uuid' => 'e2', 'evidenceKind' => 'file', 'attachmentRef' => 'b.pdf']),
				['id' => 'e3', 'evidenceKind' => 'text', 'attachmentRef' => 'c.pdf'],
				['id' => 'e4', 'evidenceKind' => 'file', 'attachmentRef' => 'd.pdf'],
				['id' => 'e5', 'evidenceKind' => 'file'],
			],
		];

		$this->grant($share, $rows, ['someone-else']);

		self::assertSame(
			[
				['findAll', ['ids' => ['p-1'], 'filters' => ['register' => 'learniq', 'schema' => 'portfolio'], 'limit' => 1]],
				['findAll', ['filters' => ['register' => 'learniq', 'schema' => 'portfolio-entry', 'portfolioId' => 'p-1']]],
				['getUserFolder', 'lea'],
				['get', 'a.pdf'],
				['getSharesBy', 'lea', IShare::TYPE_USER, 'a.pdf'],
				['setNode', 'a.pdf'],
				['setShareType', IShare::TYPE_USER],
				['setSharedWith', 'mentor'],
				['setSharedBy', 'lea'],
				['setPermissions', 1],
				['createShare'],
				['getUserFolder', 'lea'],
				['get', 'b.pdf'],
				['getSharesBy', 'lea', IShare::TYPE_USER, 'b.pdf'],
				['setNode', 'b.pdf'],
				['setShareType', IShare::TYPE_USER],
				['setSharedWith', 'mentor'],
				['setSharedBy', 'lea'],
				['setPermissions', 1],
				['createShare'],
				['log', 'info', '[PortfolioShareGrantHandler] PortfolioShare {id} granted — {n} file(s) shared with teacher {t}.', ['id' => 'sh-1', 'n' => 2, 't' => 'mentor']],
			],
			$this->trace
		);
	}//end testTeacherGrantSequence()

	/**
	 * Without a filter every file entry is shared; an existing share and a missing file count as handled / skipped.
	 *
	 * @return void
	 */
	public function testTeacherGrantWithoutFilter(): void {
		$share = ['uuid' => 'sh-2', 'portfolioId' => 'p-1', 'sharedWithKind' => 'teacher', 'sharedWithTeacherId' => 'mentor', 'entryIds' => []];
		$rows = [
			'portfolio' => [['learnerId' => 'lea']],
			'portfolio-entry' => [
				['id' => 'e1', 'evidenceKind' => 'file', 'attachmentRef' => 'a.pdf'],
				['id' => 'e2', 'evidenceKind' => 'file', 'attachmentRef' => 'gone.pdf'],
			],
		];

		$this->grant($share, $rows, ['mentor']);

		self::assertSame(['getSharesBy', 'lea', IShare::TYPE_USER, 'a.pdf'], $this->trace[4]);
		self::assertSame(
			['log', 'warning', '[PortfolioShareGrantHandler] Could not resolve attachmentRef "{ref}" under {owner}\'s home — skipping.', ['ref' => 'gone.pdf', 'owner' => 'lea']],
			$this->trace[7]
		);
		self::assertSame(
			['log', 'info', '[PortfolioShareGrantHandler] PortfolioShare {id} granted — {n} file(s) shared with teacher {t}.', ['id' => 'sh-2', 'n' => 1, 't' => 'mentor']],
			$this->trace[8]
		);
		self::assertCount(9, $this->trace);
	}//end testTeacherGrantWithoutFilter()

	/**
	 * Early exits: no portfolio or teacher, an unresolvable portfolio, and a portfolio without a learner.
	 *
	 * @return void
	 */
	public function testTeacherGrantEarlyExits(): void {
		$this->grant(['id' => 'sh-3', 'sharedWithKind' => 'teacher', 'sharedWithTeacherId' => 'mentor'], []);
		self::assertSame(
			[['log', 'warning', '[PortfolioShareGrantHandler] PortfolioShare {id} missing portfolioId/sharedWithTeacherId — cannot create NC Files share.', ['id' => 'sh-3']]],
			$this->trace
		);

		$this->trace = [];
		$this->grant(['id' => 'sh-3', 'portfolioId' => 'p-1', 'sharedWithKind' => 'teacher', 'sharedWithTeacherId' => ''], []);
		self::assertSame('warning', $this->trace[0][1]);
		self::assertCount(1, $this->trace);

		$this->trace = [];
		$this->grant(['id' => 'sh-4', 'portfolioId' => 'p-9', 'sharedWithKind' => 'teacher', 'sharedWithTeacherId' => 'mentor'], []);
		self::assertSame(
			['log', 'warning', '[PortfolioShareGrantHandler] PortfolioShare {id} references Portfolio {pid} which could not be resolved — cannot create NC Files share.', ['id' => 'sh-4', 'pid' => 'p-9']],
			$this->trace[1]
		);
		self::assertCount(2, $this->trace);

		$this->trace = [];
		$this->grant(['id' => 'sh-5', 'portfolioId' => 'p-1', 'sharedWithKind' => 'teacher', 'sharedWithTeacherId' => 'mentor'], ['portfolio' => [['learnerId' => '']]]);
		self::assertCount(1, $this->trace);
	}//end testTeacherGrantEarlyExits()
}//end class
