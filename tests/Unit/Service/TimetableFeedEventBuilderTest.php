<?php

/**
 * Edge paths of the calendar feed: TimetableFeedEventBuilder over the real
 * TimetableVisibilityService, TimetableFeedTokenService and TimetableIcsWriter.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Service;

if (class_exists('\\OCA\\Planninq\\Event\\TimetableSessionsQueryEvent') === false) {
	require_once __DIR__ . '/../../Stubs/Planninq/Event/TimetableSessionsQueryEvent.php';
}

use OCA\Learniq\Service\TimetableDirectory;
use OCA\Learniq\Service\TimetableFeedEventBuilder;
use OCA\Learniq\Service\TimetableFeedTokenService;
use OCA\Learniq\Service\TimetableIcsWriter;
use OCA\Learniq\Service\TimetableVisibilityService;
use OCA\Learniq\Timetabling\Source\LocalSessionTimetableSource;
use OCA\Learniq\Timetabling\Source\PlanninqTimetableSource;
use OCA\Learniq\Timetabling\Source\TimetableSourceResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The event builder's less common lessons, the token store's refusals and the writer's empty times.
 */
class TimetableFeedEventBuilderTest extends TestCase {
	/**
	 * The builder, with every teacher visible to the reader.
	 *
	 * @return TimetableFeedEventBuilder
	 */
	private function builder(): TimetableFeedEventBuilder {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['schema'] ?? '') === 'timetable-visibility-policy') ? [['id' => 'p', 'learnerSeesTeachers' => 'all']] : []
		);
		$appManager = $this->createMock(IAppManager::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('auto');
		$sources = new TimetableSourceResolver(
			$appConfig,
			new LocalSessionTimetableSource($objects),
			new PlanninqTimetableSource($appManager, $this->createMock(IEventDispatcher::class))
		);
		$groups = $this->createMock(IGroupManager::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturn(null);

		return new TimetableFeedEventBuilder(new TimetableVisibilityService(new TimetableDirectory($objects, $sources), $groups, $users), $users);
	}//end builder()

	/**
	 * Strings as written, with their parameters filled in.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		return $l10n;
	}//end l10n()

	/**
	 * Every reason kind has a label; an unknown kind, a session without id and
	 * the reader's own cover all read as intended.
	 *
	 * @return void
	 */
	public function testReasonKindsCoverAndFallbacks(): void {
		$base = ['startsAt' => '2026-01-08T09:00:00+00:00', 'endsAt' => '2026-01-08T10:00:00+00:00', 'lifecycle' => 'cancelled'];
		$sessions = [
			array_merge($base, ['id' => 'a', 'changeReasonKind' => 'room-unavailable', 'location' => 'Hall']),
			array_merge($base, ['id' => 'b', 'changeReasonKind' => 'timetable-change', 'room' => ['name' => '  ']]),
			array_merge($base, ['id' => 'c', 'changeReasonKind' => 'other']),
			array_merge($base, ['id' => 'd', 'changeReasonKind' => 'made-up']),
			array_merge($base, ['title' => 'No id']),
			['id' => 'e', 'title' => 'Cover', 'startsAt' => $base['startsAt'], 'lifecycle' => 'scheduled', 'substituteTeacherId' => 'alice', 'cover' => true],
			['id' => 'f', 'title' => 'Sam', 'startsAt' => $base['startsAt'], 'lifecycle' => 'scheduled', 'substituteTeacherId' => 'sam', 'changeReasonKind' => 'teacher-absence'],
		];

		$events = $this->builder()->events(uid: 'alice', sessions: $sessions, l10n: $this->l10n());
		$byUid = array_column($events, null, 'uid');

		$this->assertCount(6, $events, 'a session without an id is left out');
		$this->assertStringContainsString('Reason: room not available', $byUid['a@learniq']['description']);
		$this->assertSame('Hall', $byUid['a@learniq']['location']);
		$this->assertSame('Cancelled: Untitled lesson', $byUid['a@learniq']['summary']);
		$this->assertStringContainsString('Reason: timetable change', $byUid['b@learniq']['description']);
		$this->assertSame('', $byUid['b@learniq']['location'], 'a blank room name falls back to the empty location');
		$this->assertStringContainsString('Reason: other', $byUid['c@learniq']['description']);
		$this->assertStringNotContainsString('Reason', $byUid['d@learniq']['description']);
		$this->assertSame('You cover this lesson.', $byUid['e@learniq']['description'], 'said once when the reader is the substitute');
		// A teacher without a display name is named by their user id.
		$this->assertStringContainsString('Substitute teacher: sam', $byUid['f@learniq']['description']);
		$this->assertStringContainsString('Reason: teacher absent', $byUid['f@learniq']['description']);
	}//end testReasonKindsCoverAndFallbacks()

	/**
	 * A hash two users hold, or a stored value that no longer matches, resolves to nobody.
	 *
	 * @return void
	 */
	public function testTheTokenStoreRefusesAmbiguousOrStaleMatches(): void {
		$token = str_repeat('ab', 32);
		$stored = '';
		$config = $this->createMock(IUserConfig::class);
		$config->method('searchUsersByValueString')->willReturnCallback(
			static function () use (&$stored): \Generator {
				yield 'alice';
				if ($stored === 'two') {
					yield 'bob';
				}
			}
		);
		$config->method('getValueString')->willReturnCallback(static fn (): string => ($stored === 'stale') ? hash('sha256', 'other') : '');
		$tokens = new TimetableFeedTokenService($config, $this->createMock(ISecureRandom::class));

		$stored = 'two';
		$this->assertNull($tokens->userFor(token: $token));
		$stored = 'stale';
		$this->assertNull($tokens->userFor(token: $token));
		$stored = 'empty';
		$this->assertNull($tokens->userFor(token: $token));
		$this->assertFalse($tokens->exists(uid: 'alice'));
	}//end testTheTokenStoreRefusesAmbiguousOrStaleMatches()

	/**
	 * An event without an end has no DTEND, and an unparseable end is left out too.
	 *
	 * @return void
	 */
	public function testTheWriterLeavesOutEndsThatDoNotParse(): void {
		$ics = (new TimetableIcsWriter())->write(
			name: 'x',
			events: [['uid' => 'a', 'start' => '2026-03-02T08:30:00Z', 'end' => 'later', 'summary' => 'A', 'location' => '', 'description' => '']],
			now: 0
		);

		$this->assertStringContainsString("DTSTART:20260302T083000Z\r\n", $ics);
		$this->assertStringNotContainsString('DTEND', $ics);
		$this->assertStringNotContainsString('LOCATION', $ics);
		$this->assertStringContainsString("STATUS:CONFIRMED\r\n", $ics);
	}//end testTheWriterLeavesOutEndsThatDoNotParse()
}//end class
