<?php

/**
 * A write made from a preview records no learner activity.
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
 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-preview-leaves-no-records
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\IntegrityListenerRegistrar;
use OCA\Learniq\Listener\PreviewWriteGuard;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PreviewWriteGuard.
 */
class PreviewWriteGuardTest extends TestCase {

	/**
	 * The guard for a request with or without the preview header.
	 *
	 * @param string $header The X-Learniq-Preview header value.
	 * @param string $slug   The schema the resolver reports ('!' throws).
	 *
	 * @return PreviewWriteGuard
	 */
	private function guard(string $header, string $slug): PreviewWriteGuard {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		if ($slug === '!') {
			$resolver->method('guardSchemaSlug')->willThrowException(new \RuntimeException('unknown schema'));
		} else {
			$resolver->method('guardSchemaSlug')->willReturn($slug);
		}

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(static fn (string $name): string => $name === PreviewWriteGuard::HEADER ? $header : '');

		return new PreviewWriteGuard(schemaResolver: $resolver, request: $request);
	}//end guard()

	/**
	 * A completion, a result and a statement from a preview are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/content-adaptive-next-step-and-preview/specs/content-preview-as-learner/spec.md#scenario-a-preview-leaves-no-records
	 */
	public function testActivityFromAPreviewIsRefused(): void {
		foreach (['lesson-completion', 'assessment-result', 'xapi-statement'] as $slug) {
			$event = new ObjectCreatingEvent(OrEntityFactory::make(['learnerId' => 'author', 'lessonId' => 'l-1'], $slug));
			$this->guard(header: '1', slug: $slug)->handle($event);

			self::assertTrue($event->isPropagationStopped(), $slug);
			self::assertSame('preview-records-nothing', $event->getErrors()['reason']);
		}
	}//end testActivityFromAPreviewIsRefused()

	/**
	 * Outside a preview the same writes pass, and a preview may still write
	 * what is not learner activity; updates and an unknown schema pass.
	 *
	 * @return void
	 */
	public function testOtherWritesPass(): void {
		$completion = OrEntityFactory::make(['learnerId' => 'jan'], 'lesson-completion');

		$learner = new ObjectCreatingEvent($completion);
		$this->guard(header: '', slug: 'lesson-completion')->handle($learner);
		self::assertFalse($learner->isPropagationStopped());

		$lesson = new ObjectCreatingEvent(OrEntityFactory::make(['name' => 'x'], 'lesson'));
		$this->guard(header: '1', slug: 'lesson')->handle($lesson);
		self::assertFalse($lesson->isPropagationStopped());

		$update = new ObjectUpdatingEvent($completion, $completion);
		$this->guard(header: '1', slug: 'lesson-completion')->handle($update);
		self::assertFalse($update->isPropagationStopped());

		$unknown = new ObjectCreatingEvent($completion);
		$this->guard(header: '1', slug: '!')->handle($unknown);
		self::assertFalse($unknown->isPropagationStopped());
	}//end testOtherWritesPass()

	/**
	 * The guard is registered on creates.
	 *
	 * @return void
	 */
	public function testTheGuardIsRegisteredOnCreate(): void {
		$wired = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$wired): void {
				$wired[] = [$event, $listener];
			}
		);

		(new IntegrityListenerRegistrar())->register(context: $context);

		self::assertContains([ObjectCreatingEvent::class, PreviewWriteGuard::class], $wired);
	}//end testTheGuardIsRegisteredOnCreate()
}//end class
