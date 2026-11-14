<?php

/**
 * Learniq SSO Profile Fill Listener
 *
 * On a Nextcloud sign-in through the school's SAML single sign-on, applies the
 * active SsoAttributeMapping rows to the attributes the sign-in sent and
 * writes the result to the user's LearnerProfile. A field no mapping names is
 * left as it was; a mapped role is added to the profile's roles.
 *
 * user_saml keeps the attributes of the assertion it just accepted in the
 * session under `user_saml.samlUserData`; without them (a password login, or
 * another sign-in app) nothing is written. Sign-in never fails because of
 * this listener: every error is logged and swallowed.
 *
 * @category Listener
 * @package  OCA\Learniq\Listener
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
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/identity-federation/spec.md#requirement-signing-in-writes-the-mapped-fields-to-the-learners-profile
 */

declare(strict_types=1);

namespace OCA\Learniq\Listener;

use OCA\Learniq\Service\SsoAttributeMappingApplier;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the mapped sign-in attributes to the signing-in user's profile.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-rows-say-what-they-do/specs/identity-federation/spec.md#requirement-signing-in-writes-the-mapped-fields-to-the-learners-profile
 */
class SsoProfileFillListener implements IEventListener {

	/**
	 * The session key user_saml stores the accepted assertion's attributes under.
	 */
	public const SAML_SESSION_KEY = 'user_saml.samlUserData';

	private const REGISTER = 'learniq';
	private const PROFILE_SCHEMA = 'learner-profile';

	/**
	 * Constructor.
	 *
	 * @param SsoAttributeMappingApplier $applier       Turns the attributes into profile fields.
	 * @param ObjectService              $objectService Reads and writes the profile.
	 * @param ISession                   $session       Holds the sign-in's attributes.
	 * @param LoggerInterface            $logger        Logs a failed write.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SsoAttributeMappingApplier $applier,
		private readonly ObjectService $objectService,
		private readonly ISession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fill the profile from a SAML sign-in.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-rows-say-what-they-do/specs/identity-federation/spec.md#requirement-signing-in-writes-the-mapped-fields-to-the-learners-profile
	 */
	public function handle(Event $event): void {
		if (($event instanceof UserLoggedInEvent) === false) {
			return;
		}

		$attributes = self::flatten(raw: $this->session->get(self::SAML_SESSION_KEY));
		if ($attributes === []) {
			return;
		}

		$this->fillLoggingFailure(userId: $event->getUser()->getUID(), attributes: $attributes);
	}//end handle()

	/**
	 * Fill the profile; a failure is logged, never thrown into the sign-in.
	 *
	 * @param string              $userId     The Nextcloud user.
	 * @param array<string,mixed> $attributes The sign-in attributes.
	 *
	 * @return void
	 */
	private function fillLoggingFailure(string $userId, array $attributes): void {
		try {
			$this->fill(userId: $userId, attributes: $attributes);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[SsoProfileFillListener] Could not fill the profile of {user} from the sign-in: {msg}',
				['user' => $userId, 'msg' => $exception->getMessage()]
			);
		}
	}//end fillLoggingFailure()

	/**
	 * Write the mapped fields to the user's profile, when they change something.
	 *
	 * @param string              $userId     The Nextcloud user.
	 * @param array<string,mixed> $attributes The sign-in attributes.
	 *
	 * @return void
	 */
	private function fill(string $userId, array $attributes): void {
		$profile = $this->profileOf(userId: $userId);
		if ($profile === null) {
			return;
		}

		$changed = $this->changedFields(profile: $profile, attributes: $attributes);
		if ($changed === []) {
			return;
		}

		$this->save(profile: $profile, changed: $changed);
	}//end fill()

	/**
	 * Save the profile with its changed fields.
	 *
	 * @param array<string,mixed> $profile The LearnerProfile.
	 * @param array<string,mixed> $changed The changed fields.
	 *
	 * @return void
	 */
	private function save(array $profile, array $changed): void {
		$object = array_merge($profile, $changed);
		unset($object['@self']);

		$this->objectService->saveObject(
			object: $object,
			register: self::REGISTER,
			schema: self::PROFILE_SCHEMA,
			uuid: (string)($profile['id'] ?? ($profile['uuid'] ?? '')),
			_rbac: false,
			_multitenancy: false
		);
	}//end save()

	/**
	 * The mapped fields whose value differs from the profile; roles are added to the profile's own.
	 *
	 * @param array<string,mixed> $profile    The LearnerProfile.
	 * @param array<string,mixed> $attributes The sign-in attributes.
	 *
	 * @return array<string,mixed> The changed fields.
	 */
	private function changedFields(array $profile, array $attributes): array {
		$updates = $this->applier->apply(attributes: $attributes, provider: 'saml', tenantId: (string)($profile['tenant_id'] ?? ''));
		if (isset($updates['roles']) === true) {
			$updates['roles'] = array_values(array_unique(array_merge((array)($profile['roles'] ?? []), (array)$updates['roles'])));
		}

		return array_filter($updates, static fn ($value, string $field): bool => ($profile[$field] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
	}//end changedFields()

	/**
	 * The user's LearnerProfile, or null when they have none.
	 *
	 * @param string $userId The Nextcloud user.
	 *
	 * @return array<string,mixed>|null
	 */
	private function profileOf(string $userId): ?array {
		$rows = $this->objectService->findAll(
			['filters' => ['ncUserId' => $userId, 'register' => self::REGISTER, 'schema' => self::PROFILE_SCHEMA], 'limit' => 1]
		);
		foreach ($rows as $row) {
			$profile = $row;
			if (is_array($row) === false) {
				$profile = $row->jsonSerialize();
			}

			if (is_array($profile) === true && (string)($profile['ncUserId'] ?? '') === $userId) {
				return $profile;
			}
		}

		return null;
	}//end profileOf()

	/**
	 * The attributes with single-valued lists taken as their one value.
	 *
	 * SAML sends every attribute as a list of values; a mapping compares
	 * against one value, so a list of one becomes that value.
	 *
	 * @param mixed $raw The session value.
	 *
	 * @return array<string,mixed>
	 */
	private static function flatten(mixed $raw): array {
		if (is_array($raw) === false) {
			return [];
		}

		$attributes = [];
		foreach ($raw as $name => $value) {
			if (is_array($value) === true && count($value) === 1) {
				$value = reset($value);
			}

			$attributes[(string)$name] = $value;
		}

		return $attributes;
	}//end flatten()
}//end class
