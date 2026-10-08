<?php

/**
 * Learniq AccessibilityFeedbackReporterStamp unit tests.
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
 * @spec openspec/specs/accessibility-conformance/spec.md#requirement-any-authenticated-user-must-be-able-to-report-an-accessibility-barrier
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\Unit\Listener;

use OCA\Learniq\AppInfo\Registrar\EventListenerWiring;
use OCA\Learniq\Listener\AccessibilityFeedbackReporterStamp;
use OCA\Learniq\Service\CallerTenantResolver;
use OCA\Learniq\Service\ListenerSchemaResolver;
use OCA\Learniq\Tests\Support\OrEntityFactory;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AccessibilityFeedbackReporterStamp::handle().
 */
class AccessibilityFeedbackReporterStampTest extends TestCase {

	private const TENANT = '00000000-0000-4000-8000-000000000001';

	/**
	 * A barrier report as the create form sends it: no reporter, no tenant.
	 *
	 * @var array<string, string>
	 */
	private const REPORT = [
		'affectedSurface' => 'Course authoring — lesson reorder',
		'description' => 'The reorder control has no keyboard-operable equivalent.',
		'severity' => 'serious',
	];

	/**
	 * Build the stamp, signed in as $userId; only 'bound-user' has a tenant binding.
	 *
	 * @param string|null $userId The session user, null for no session.
	 * @param string      $slug   What the schema resolver answers.
	 *
	 * @return AccessibilityFeedbackReporterStamp
	 */
	private function makeStamp(?string $userId, string $slug = 'accessibility-feedback'): AccessibilityFeedbackReporterStamp {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $user, string $app, string $key, mixed $default = ''): mixed => ($user === 'bound-user' && $key === 'tenant_id' ? self::TENANT : $default)
		);

		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('guardSchemaSlug')->willReturn($slug);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);

		return new AccessibilityFeedbackReporterStamp(
			schemaResolver: $schemaResolver,
			tenants: new CallerTenantResolver($config, $this->createMock(ObjectService::class)),
			userSession: $session,
		);
	}//end makeStamp()

	/**
	 * A report without reporter or tenant is filed for the signed-in user and their tenant.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/accessibility-conformance/spec.md#scenario-a-user-submits-a-barrier-report-and-it-notifies-the-compliance-officer
	 */
	public function testTheReporterAndTenantComeFromTheSession(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::REPORT, 'accessibility-feedback'));
		$this->makeStamp(userId: 'bound-user')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('bound-user', $event->getModifiedData()['reporterUserId']);
		self::assertSame(self::TENANT, $event->getModifiedData()['tenant_id']);
	}//end testTheReporterAndTenantComeFromTheSession()

	/**
	 * A report filed in someone else's name is stored in the reporter's own.
	 *
	 * @return void
	 */
	public function testAReportCannotBeFiledInSomeoneElsesName(): void {
		$event = new ObjectCreatingEvent(
			OrEntityFactory::make(array_merge(self::REPORT, ['reporterUserId' => 'someone-else', 'tenant_id' => '99999999-0000-4000-8000-000000000009']), 'accessibility-feedback')
		);
		$this->makeStamp(userId: 'bound-user')->handle($event);

		self::assertSame('bound-user', $event->getModifiedData()['reporterUserId']);
		self::assertSame(self::TENANT, $event->getModifiedData()['tenant_id']);
	}//end testAReportCannotBeFiledInSomeoneElsesName()

	/**
	 * A user without a tenant binding files under the default tenant.
	 *
	 * @return void
	 */
	public function testAnUnboundUserFilesUnderTheDefaultTenant(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::REPORT, 'accessibility-feedback'));
		$this->makeStamp(userId: 'unbound-user')->handle($event);

		self::assertSame('unbound-user', $event->getModifiedData()['reporterUserId']);
		self::assertSame(CallerTenantResolver::DEFAULT_TENANT, $event->getModifiedData()['tenant_id']);
	}//end testAnUnboundUserFilesUnderTheDefaultTenant()

	/**
	 * A create without a signed-in user is refused.
	 *
	 * @return void
	 */
	public function testACreateWithoutASessionIsRefused(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(self::REPORT, 'accessibility-feedback'));
		$this->makeStamp(userId: null)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('accessibility-feedback-no-session', $event->getErrors()['reason']);
	}//end testACreateWithoutASessionIsRefused()

	/**
	 * Triage cannot move a report to another reporter or tenant.
	 *
	 * @return void
	 */
	public function testAnUpdateKeepsTheStoredReporterAndTenant(): void {
		$stored = array_merge(self::REPORT, ['reporterUserId' => 'bound-user', 'tenant_id' => self::TENANT, 'lifecycle' => 'submitted']);
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge($stored, ['reporterUserId' => 'officer', 'tenant_id' => null]), 'accessibility-feedback'),
			OrEntityFactory::make($stored, 'accessibility-feedback')
		);
		$this->makeStamp(userId: 'officer')->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame('bound-user', $event->getModifiedData()['reporterUserId']);
		self::assertSame(self::TENANT, $event->getModifiedData()['tenant_id']);
	}//end testAnUpdateKeepsTheStoredReporterAndTenant()

	/**
	 * An update of a stored report without a reporter is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAReportWithoutAReporterIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			OrEntityFactory::make(array_merge(self::REPORT, ['reporterUserId' => 'officer']), 'accessibility-feedback'),
			OrEntityFactory::make(self::REPORT, 'accessibility-feedback')
		);
		$this->makeStamp(userId: 'officer')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('accessibility-feedback-reporter-missing', $event->getErrors()['reason']);
	}//end testAnUpdateOfAReportWithoutAReporterIsRefused()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent(OrEntityFactory::make(['reporterUserId' => 'someone-else'], 'accessibility-limitation'));
		$this->makeStamp(userId: 'bound-user', slug: 'accessibility-limitation')->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The schema leaves the stamped fields out of `required` (OpenRegister
	 * checks it before the stamp runs) and marks them readOnly, so the create
	 * form no longer asks for them.
	 *
	 * @return void
	 */
	public function testTheFormDoesNotAskForTheStampedFields(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/learniq_register.json'), true);
		$schema   = $register['components']['schemas']['AccessibilityFeedback'];

		foreach (['reporterUserId', 'tenant_id'] as $field) {
			self::assertNotContains($field, $schema['required']);
			self::assertTrue($schema['properties'][$field]['readOnly']);
		}
	}//end testTheFormDoesNotAskForTheStampedFields()

	/**
	 * The stamp is wired on both create and update, asserted from the caller.
	 *
	 * @return void
	 */
	public function testTheStampIsRegisteredForCreateAndUpdate(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = $event . ' => ' . $listener;
			}
		);

		(new EventListenerWiring())->registerAll(context: $context);

		self::assertContains(ObjectCreatingEvent::class . ' => ' . AccessibilityFeedbackReporterStamp::class, $registered);
		self::assertContains(ObjectUpdatingEvent::class . ' => ' . AccessibilityFeedbackReporterStamp::class, $registered);
	}//end testTheStampIsRegisteredForCreateAndUpdate()
}//end class
