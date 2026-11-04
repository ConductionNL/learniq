<?php

/**
 * An employer invite against OpenRegister's real read rules.
 *
 * This test runs inside an OpenRegister checkout, because it needs
 * OpenRegister's real MagicRbacHandler and MagicSearchHandler, which learniq's
 * own tests replace with stubs. From the OpenRegister checkout:
 *
 *   vendor/bin/phpunit -c phpunit.xml --no-coverage <learniq>/tests/openregister-harness/EmployerInviteTenancyHarnessTest.php
 *
 * The company reads of EmployerPortalInvitation are answered by the SQL that
 * OpenRegister builds for `client-organisation` with learniq's own read rule
 * (lib/Settings/learniq_register.json), run against an in-memory SQLite
 * table. The caller is a member of `hr` in organisation org-home. Her read
 * rule is a plain group rule, so OpenRegister lifts the organisation filter
 * and returns the company of org-other too; the invitation must refuse it
 * itself.
 *
 * @category Tests
 * @package  OCA\Learniq\Tests\OpenRegisterHarness
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Learniq\Tests\OpenRegisterHarness;

use Composer\Autoload\ClassLoader;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use OCA\Learniq\Portal\EmployerPortalInvitation;
use OCA\Learniq\Portal\PortalTenant;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Query\RelatedRowQueryApplier;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

foreach (ClassLoader::getRegisteredLoaders() as $loader) {
	$loader->addPsr4('OCA\\Learniq\\', __DIR__ . '/../../lib/');
	break;
}

/**
 * Another organisation's company answers like an unknown one, whatever OpenRegister's read rule returns.
 *
 * @coversNothing
 */
class EmployerInviteTenancyHarnessTest extends TestCase {

	/**
	 * The real RBAC handler.
	 *
	 * @var MagicRbacHandler
	 */
	private MagicRbacHandler $rbac;

	/**
	 * Every company read: rbac and multitenancy.
	 *
	 * @var list<array{0: bool, 1: bool}>
	 */
	private array $reads = [];

	/**
	 * Build the real RBAC handler over a real PermissionHandler, for an `hr` member of org-home.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('hr-home');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn(['hr']);
		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturn([]);
		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$conditionMatcher = $this->createMock(originalClassName: ConditionMatcher::class);
		$conditionMatcher->method('resolveDynamicValue')->willReturnCallback(
			static fn (mixed $value): mixed => match ($value) {
				'$organisation' => 'org-home',
				default => $value,
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn(true);
		$container = $this->createMock(originalClassName: ContainerInterface::class);

		$permissionHandler = new PermissionHandler(
			$userSession,
			$userManager,
			$groupManager,
			$this->createMock(originalClassName: SchemaMapper::class),
			$this->createMock(originalClassName: MagicMapper::class),
			$conditionMatcher,
			$appConfig,
			$logger,
			$container
		);
		$container->method('get')->willReturnCallback(
			static function (string $service) use ($permissionHandler, $registerMapper): object {
				if ($service === PermissionHandler::class) {
					return $permissionHandler;
				}

				if ($service === RegisterMapper::class) {
					return $registerMapper;
				}

				throw new RuntimeException('Unexpected service ' . $service);
			}
		);

		$this->rbac = new MagicRbacHandler($userSession, $groupManager, $userManager, $appConfig, $conditionMatcher, $container, $logger);
	}//end setUp()

	/**
	 * The control: OpenRegister itself hands the hr member the other organisation's company.
	 *
	 * @return void
	 */
	public function testOpenRegistersReadRuleReturnsTheOtherOrganisationsCompany(): void {
		self::assertSame(expected: ['co-home', 'co-other'], actual: $this->visibleUuids(ids: ['co-home', 'co-other'], flags: [true, true]));
	}//end testOpenRegistersReadRuleReturnsTheOtherOrganisationsCompany()

	/**
	 * The invitation refuses the other organisation's company exactly like an unknown one, and invites its own.
	 *
	 * @return void
	 */
	public function testTheCallerInviteRefusesTheOtherOrganisationsCompany(): void {
		$invitation = $this->invitation();

		$other   = $this->inviteAsCaller(invitation: $invitation, organisationRef: 'co-other');
		$unknown = $this->inviteAsCaller(invitation: $invitation, organisationRef: 'co-none');
		self::assertSame(expected: ['status' => 'refused', 'reason' => 'company-unknown'], actual: $other);
		self::assertSame(expected: $unknown, actual: $other);

		self::assertSame(
			expected: ['status' => 'invited', 'subjectRef' => 'contact-account'],
			actual: $this->inviteAsCaller(invitation: $invitation, organisationRef: 'co-home')
		);
		self::assertSame(expected: [[true, true], [true, true], [true, true]], actual: $this->reads);
	}//end testTheCallerInviteRefusesTheOtherOrganisationsCompany()

	/**
	 * The occ path reads as the system and still refuses the other organisation's company.
	 *
	 * @return void
	 */
	public function testTheCommandInviteRefusesTheOtherOrganisationsCompany(): void {
		$invite = new ReflectionMethod(EmployerPortalInvitation::class, 'invite');
		if (self::takesATenant(method: $invite) === false) {
			self::fail(message: 'invite() takes no tenant, so the command path cannot check the company against the portal');
		}

		$invitation = $this->invitation();
		$tenant     = new PortalTenant(slug: 'home', uuid: 'org-home');
		self::assertSame(
			expected: ['status' => 'refused', 'reason' => 'company-unknown'],
			actual: $invitation->invite(organisationRef: 'co-other', tenant: $tenant)
		);
		self::assertSame(
			expected: ['status' => 'invited', 'subjectRef' => 'contact-account'],
			actual: $invitation->invite(organisationRef: 'co-home', tenant: $tenant)
		);
		self::assertSame(expected: [[false, false], [false, false]], actual: $this->reads);
	}//end testTheCommandInviteRefusesTheOtherOrganisationsCompany()

	/**
	 * Invite for the caller into portal `home`; an older signature that takes the slug gets the slug.
	 *
	 * @param EmployerPortalInvitation $invitation      The invitation.
	 * @param string                   $organisationRef The company.
	 *
	 * @return array<string, string>
	 */
	private function inviteAsCaller(EmployerPortalInvitation $invitation, string $organisationRef): array {
		$portal = 'home';
		if (self::takesATenant(method: new ReflectionMethod(EmployerPortalInvitation::class, 'inviteAsCaller')) === true) {
			$portal = new PortalTenant(slug: 'home', uuid: 'org-home');
		}

		return $invitation->inviteAsCaller($organisationRef, $portal);
	}//end inviteAsCaller()

	/**
	 * Whether the method's second parameter is a PortalTenant.
	 *
	 * @param ReflectionMethod $method The method.
	 *
	 * @return bool
	 */
	private static function takesATenant(ReflectionMethod $method): bool {
		$type = $method->getParameters()[1]->getType();

		return $type instanceof ReflectionNamedType && $type->getName() === 'OCA\\Learniq\\Portal\\PortalTenant';
	}//end takesATenant()

	/**
	 * The invitation over an object service whose company reads run OpenRegister's SQL.
	 *
	 * @return EmployerPortalInvitation
	 */
	private function invitation(): EmployerPortalInvitation {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				$this->reads[] = [$_rbac, $_multitenancy];
				return $this->companies(ids: (array)($config['ids'] ?? []), flags: [$_rbac, $_multitenancy]);
			}
		);

		return new EmployerPortalInvitation(
			objectService: $objects,
			dispatcher: $this->createMock(originalClassName: IEventDispatcher::class),
			logger: new NullLogger(),
			provisionEventClass: HarnessProvisionEvent::class,
			claimEventClass: HarnessClaimEvent::class
		);
	}//end invitation()

	/**
	 * The companies OpenRegister returns for these ids, as entities.
	 *
	 * @param array<int, string>       $ids   The uuids asked for.
	 * @param array{0: bool, 1: bool}  $flags The rbac and multitenancy flags findAll passes on.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function companies(array $ids, array $flags): array {
		$organisations = ['co-home' => 'org-home', 'co-other' => 'org-other'];
		$entities = [];
		foreach ($this->visibleUuids(ids: $ids, flags: $flags) as $uuid) {
			$entity = new ObjectEntity();
			$entity->setUuid($uuid);
			$entity->setOrganisation($organisations[$uuid]);
			$entity->setObject(['name' => $uuid, 'contactEmail' => 'contact@' . $uuid . '.example', 'lifecycle' => 'active']);
			$entities[] = $entity;
		}

		return $entities;
	}//end companies()

	/**
	 * Run the list's organisation and RBAC conditions against a real table.
	 *
	 * ObjectService::findAll() passes `_rbac` and `_multitenancy` on, and never
	 * `_multitenancy_explicit`.
	 *
	 * @param array<int, string>      $ids   The uuids asked for.
	 * @param array{0: bool, 1: bool} $flags The rbac and multitenancy flags.
	 *
	 * @return array<int, string> The uuids returned, sorted.
	 */
	private function visibleUuids(array $ids, array $flags): array {
		$conditions = $this->searchHandler()->buildWhereConditionsSql(query: ['_rbac' => $flags[0], '_multitenancy' => $flags[1]], schema: self::schema());

		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn ($value) => $value, 1);
		$pdo->exec(
			'CREATE TABLE t (_uuid TEXT, _owner TEXT, _organisation TEXT, _authorization TEXT,'
			. ' _deleted TEXT, _archived TEXT, _status TEXT, _expires TEXT, _published TEXT, _depublished TEXT)'
		);
		$pdo->exec("INSERT INTO t (_uuid, _owner, _organisation) VALUES ('co-home', 'importer', 'org-home'), ('co-other', 'importer', 'org-other')");

		$where = '';
		if ($conditions !== []) {
			$where = ' AND ' . implode(' AND ', $conditions);
		}

		$wanted = implode(', ', array_map(static fn (string $id): string => $pdo->quote($id), $ids));
		if ($wanted === '') {
			return [];
		}

		$uuids = $pdo->query('SELECT _uuid FROM t WHERE _uuid IN (' . $wanted . ')' . $where)->fetchAll(PDO::FETCH_COLUMN);
		sort($uuids);

		return $uuids;
	}//end visibleUuids()

	/**
	 * The client-organisation schema with learniq's own authorization block.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../lib/Settings/learniq_register.json'), true);
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Client organisation');
		$schema->setAuthorization($register['components']['schemas']['ClientOrganisation']['authorization']);

		return $schema;
	}//end schema()

	/**
	 * A search handler over the real RBAC handler, scoping the caller to org-home.
	 *
	 * @return MagicSearchHandler
	 */
	private function searchHandler(): MagicSearchHandler {
		$connection = $this->createMock(originalClassName: IDBConnection::class);
		$connection->method('quote')->willReturnCallback(static fn ($value): string => "'" . str_replace("'", "''", (string)$value) . "'");
		$queryBuilder = $this->createMock(originalClassName: IQueryBuilder::class);
		$queryBuilder->method('getConnection')->willReturn($connection);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($queryBuilder);
		$db->method('getDatabasePlatform')->willReturn(new SqlitePlatform());

		$organisation = $this->createMock(originalClassName: MagicOrganizationHandler::class);
		$organisation->method('resolveOrganizationScope')->willReturn(['mode' => MagicOrganizationHandler::SCOPE_IN, 'uuids' => ['org-home']]);
		$organisation->method('isAdminOverrideEnabled')->willReturn(false);

		return new MagicSearchHandler(
			$db,
			$this->createMock(originalClassName: LoggerInterface::class),
			$this->rbac,
			$organisation,
			$this->createMock(originalClassName: SchemaTypeConverter::class),
			$this->createMock(originalClassName: DateTimeNormalizer::class),
			relatedRows: $this->createMock(originalClassName: RelatedRowQueryApplier::class)
		);
	}//end searchHandler()
}//end class

/**
 * Stands in for portaliq's provision event: answers an account.
 */
class HarnessProvisionEvent extends Event {

	/**
	 * Constructor, with the arguments learniq passes.
	 *
	 * @param string $appId         The app.
	 * @param string $audience      The audience.
	 * @param string $organisation  The portal organisation.
	 * @param string $identityType  The identity type.
	 * @param string $identityRef   The identity reference.
	 * @param string $email         The address.
	 * @param bool   $verifiedEmail Whether the address is verified.
	 * @param string $displayName   The contact's name.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $audience,
		public readonly string $organisation,
		public readonly string $identityType='',
		public readonly string $identityRef='',
		public readonly string $email='',
		public readonly bool $verifiedEmail=false,
		public readonly string $displayName='',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The account provisioned.
	 *
	 * @return string
	 */
	public function getSubjectRef(): string {
		return 'contact-account';
	}//end getSubjectRef()
}//end class

/**
 * Stands in for portaliq's claim event: every claim lands.
 */
class HarnessClaimEvent extends Event {

	/**
	 * Constructor, with the arguments learniq passes.
	 *
	 * @param string $appId      The app.
	 * @param string $subjectRef The account.
	 * @param string $claimName  The claim.
	 * @param string $value      The value.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $subjectRef,
		public readonly string $claimName,
		public readonly string $value,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The claim landed.
	 *
	 * @return string
	 */
	public function getResult(): string {
		return 'ok';
	}//end getResult()
}//end class
