<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Model\Trait;

defined('AKEEBA') || die;

use Akeeba\BackupJsonApi\Connector;
use Akeeba\BackupJsonApi\Exception\RemoteError;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientGuzzle;
use Akeeba\BackupJsonApi\HttpAbstraction\HttpClientInterface;
use Akeeba\BackupJsonApi\Options as JsonApiOptions;
use Akeeba\Panopticon\Container;
use Akeeba\Panopticon\Exception\AkeebaBackup\AkeebaBackupInvalidBody;
use Akeeba\Panopticon\Exception\AkeebaBackup\AkeebaBackupNoCredentials;
use Akeeba\Panopticon\Exception\AkeebaBackup\AkeebaBackupNoEndpoint;
use Akeeba\Panopticon\Exception\AkeebaBackup\AkeebaBackupNotInstalled;
use Akeeba\Panopticon\Library\Cache\CallbackController;
use Akeeba\Panopticon\Library\Enumerations\CMSType;
use Akeeba\Panopticon\Library\Logger\MemoryLogger;
use Akeeba\Panopticon\Library\Task\Status;
use Akeeba\Panopticon\Model\Exception\AkeebaBackupCannotConnectException;
use Akeeba\Panopticon\Model\Exception\AkeebaBackupIsNotPro;
use Akeeba\Panopticon\Model\Exception\AkeebaBackupNoInfoException;
use Akeeba\Panopticon\Model\Site;
use Akeeba\Panopticon\Model\Task;
use Awf\Mvc\DataModel\Collection;
use Awf\Uri\Uri;
use Awf\User\User;
use Composer\CaBundle\CaBundle;
use DateTimeZone;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Model Trait for the integration with Akeeba Backup Professional for Joomla!
 *
 * @since  1.0.0
 */
trait AkeebaBackupIntegrationTrait
{
	private ?CallbackController $callbackControllerForAkeebaBackup = null;

	/**
	 * Test the connection to the remote site's Akeeba Backup installation.
	 *
	 * First, we use the API to get information about whether Akeeba Backup is installed and has the JSON API available.
	 *
	 * If so, we test the endpoints returned by the API to see to which one and how we can connect.
	 *
	 * Note: When `$throw` is enabled we throw an exception immediately upon encountering an error.
	 *
	 * @param   bool  $withEndpoints  Should I also test the endpoints?
	 * @param   bool  $throw          Throw exceptions describing the error conditions.
	 *
	 * @return  bool  True if the site's configuration must be saved.
	 * @since   1.0.0
	 */
	public function testAkeebaBackupConnection(bool $withEndpoints = true, bool $throw = false): bool
	{
		/** @var \Akeeba\Panopticon\Container $container */
		$container = $this->container;
		$session   = $container->segment;

		// Initialise debug information
		$session->set('testconnection.akeebabackup.step', null);
		$session->set('testconnection.akeebabackup.http_status', null);
		$session->set('testconnection.akeebabackup.body', null);
		$session->set('testconnection.akeebabackup.headers', null);
		$throwThis = null;

		// Get the information from the API
		$session->set('testconnection.akeebabackup.step', 'Retrieve Akeeba Backup connection information from the API');

		$client = $container->httpFactory->makeClient(cache: false, singleton: false);

		[$url, $options] = match ($this->cmsType())
		{
			CMSType::JOOMLA => $this->getRequestOptions($this, '/index.php/v1/panopticon/akeebabackup/info'),
			CMSType::WORDPRESS => $this->getRequestOptions($this, '/v1/panopticon/akeebabackup/info'),
			default => [null, null]
		};

		if ($url === null)
		{
			// TODO Raise exception: unsupported site
		}

		$options[RequestOptions::HTTP_ERRORS] = false;

		try
		{
			$response = $client->get($url, $options);
		}
		catch (GuzzleException $e)
		{
			$throwThis ??= $e;
		}
		finally
		{
			$bodyContent = $response?->getBody()?->getContents();
		}

		$refreshResponse = (object) [
			'statusCode'   => $response?->getStatusCode(),
			'reasonPhrase' => $response?->getReasonPhrase(),
			'body'         => $this->sanitizeJson($bodyContent ?? ''),
		];

		try
		{
			$results = @json_decode($refreshResponse->body ?? '{}', flags: JSON_THROW_ON_ERROR);
		}
		catch (Throwable)
		{
			$results = null;

			$throwThis ??= new AkeebaBackupInvalidBody();
		}

		if (method_exists($this, 'updateDebugInfoInSession'))
		{
			$this->updateDebugInfoInSession(
				$response ?? null, $bodyContent, $throwThis, 'testconnection.akeebabackup.'
			);
		}

		// Do I have updated information?
		$config      = $this->getConfig();
		$info        = match ($this->cmsType()) {
			CMSType::JOOMLA => $results?->data?->attributes ?? null,
			CMSType::WORDPRESS => $results ?? null,
			default => null
		};
		$currentInfo = $config->get('akeebabackup.info') ?: new stdClass();
		$dirtyFlag   = false;

		$hasUpdatedInfo = array_reduce(
			['installed', 'version', 'api', 'secret', 'endpoints'],
			function (bool $carry, $key) use ($info, $currentInfo) {
				if ($carry)
				{
					return true;
				}

				$current = $currentInfo?->{$key} ?? null;
				$new     = $info?->{$key} ?? null;

				if (is_array($current))
				{
					$current = (object) $current;
				}

				if (is_array($new))
				{
					$new = (object) $new;
				}

				return $current != $new;
			},
			false
		);

		if ($hasUpdatedInfo || empty($info))
		{
			$config->set('akeebabackup.info', $info);
			$config->set('akeebabackup.lastRefreshResponse', $refreshResponse);

			$dirtyFlag = true;
		}

		if (is_array($results?->errors ?? null))
		{
			$firstError = reset($results->errors);

			$throwThis ??= new \RuntimeException(
				$firstError->title ?? 'Unknown API error',
				$firstError->code ?? 500
			);
		}

		// If `installed` is not true we cannot proceed with auto-detection.
		if (($info?->installed ?? false) !== true)
		{
			$config->set('akeebabackup.endpoint', null);

			$dirtyFlag = true;

			$throwThis ??= new AkeebaBackupNotInstalled();
		}
		elseif ($withEndpoints)
		{
			// Find an endpoint for the Akeeba Backup JSON API
			$session->set('testconnection.akeebabackup.step', 'Find the most suitable Akeeba Backup JSON API endpoint');

			// Auto-detect the best way to connect.
			$credentials              = $this->getAkeebaBackupCredentials($info);
			$candidates               = $this->getAkeebaBackupConnectionCandidates($info);
			$newEndpointConfiguration = null;

			if (empty($candidates))
			{
				$throwThis ??= new AkeebaBackupIsNotPro();
			}

			/**
			 * Neither credential is available, so there is nothing to authenticate with.
			 *
			 * This is a real possibility now that the Secret Word is deprecated. The connector reports the Secret Word
			 * it found or provisioned, and failing to provision one is no longer fatal on its side — a site may
			 * legitimately end up without one, as long as we have a Joomla! API token to present instead.
			 */
			if (empty($credentials['secret']) && empty($credentials['token']))
			{
				$candidates = [];

				$throwThis ??= new AkeebaBackupNoCredentials();
			}

			foreach ($candidates as $candidate)
			{
				try
				{
					$options = new JsonApiOptions(
						array_merge(
							[
								'capath' => defined('AKEEBA_CACERT_PEM') ? AKEEBA_CACERT_PEM
									: CaBundle::getBundledCaBundlePath(),
								'ua'     => 'panopticon/' . AKEEBA_PANOPTICON_VERSION,
							],
							$credentials,
							$candidate
						)
					);
				}
				catch (Throwable)
				{
					// A candidate the client refuses to even describe is a candidate we cannot try.
					continue;
				}

				$httpClient = new HttpClientGuzzle($options);
				$apiClient  = new Connector($httpClient);
				$foundConfig = false;

				try
				{
					$apiClient->information();
					$foundConfig = true;
				}
				catch (Exception)
				{
					// Nothing
				}

				if (!$foundConfig)
				{
					try
					{
						$apiClient->autodetect();
					}
					catch (Throwable)
					{
						continue;
					}
				}

				$newEndpointConfiguration = (object) $httpClient->getOptions()->toArray();

				if (isset($newEndpointConfiguration->capath))
				{
					unset($newEndpointConfiguration->capath);
				}

				if (isset($newEndpointConfiguration->logger))
				{
					unset($newEndpointConfiguration->logger);
				}

				break;
			}

			if ($newEndpointConfiguration === null && $throw)
			{
				$throwThis ??= new AkeebaBackupNoEndpoint();
			}

			$oldEndpointConfiguration = $config->get('akeebabackup.endpoint');

			if ($oldEndpointConfiguration != $newEndpointConfiguration)
			{
				$config->set('akeebabackup.endpoint', $newEndpointConfiguration);

				$dirtyFlag = true;
			}

			if (method_exists($this, 'updateDebugInfoInSession'))
			{
				$this->updateDebugInfoInSession(
					$response ?? null, $bodyContent, $throwThis, 'testconnection.akeebabackup.'
				);
			}
		}

		// Commit any detected changes to the site object
		if ($dirtyFlag)
		{
			$this->setFieldValue('config', $config->toString());
		}

		if ($throw && !empty($throwThis))
		{
			throw $throwThis;
		}

		return $dirtyFlag;
	}

	/**
	 * Is the Akeeba Backup package or component installed on this site?
	 *
	 * @return  bool
	 * @since   1.0.0
	 */
	public function hasAkeebaBackup(bool $onlyProfessional = false): bool
	{
		if ($this->cmsType() === CMSType::JOOMLA)
		{
			// Joomla 3 doesn't support Download Keys, so we test the package name instead.
			if (version_compare($this->getConfig()->get('core.current.version', '4.0.0'), '3.99999.99999', 'le'))
			{
				return array_reduce(
					(array) $this->getConfig()->get('extensions.list'),
					fn(bool $carry, object $item) => $carry ||
					                                 (
						                                 $item->type === 'package'
						                                 && in_array($item->element, ['pkg_akeebabackup', 'pkg_akeeba', 'com_akeebabackup', 'com_akeeba'])
						                                 && (!$onlyProfessional || str_contains(strtolower($item->description), 'professional'))
					                                 ),
					false
				);
			}

			// Joomla 4 and later, we just check if the package supports download keys.
			return array_reduce(
				(array) $this->getConfig()->get('extensions.list'),
				fn(bool $carry, object $item) => $carry ||
				                                 (
					                                 $item->type === 'package'
					                                 && in_array($item->element, ['pkg_akeebabackup', 'pkg_akeeba'])
					                                 && (!$onlyProfessional || $item->downloadkey?->supported)
				                                 ),
				false
			);
		}

		if ($this->cmsType() === CMSType::WORDPRESS)
		{
			return array_reduce(
				(array) $this->getConfig()->get('extensions.list'),
				fn(bool $carry, object $item) => $carry ||
				                                 (
					                                 $item->type === 'plugin'
					                                 && $item->element === 'akeebabackupwp.php'
					                                 && (!$onlyProfessional || str_contains(strtolower($item->name), 'professional'))
				                                 ),
				false
			);
		}

		return false;
	}

	/**
	 * Get the information of the remote Akeeba Backup installation for debugging purposes.
	 *
	 * @return  array
	 * @since   1.0.6
	 */
	public function akeebaBackupGetInfoForDebug(): array
	{
		$logger    = new MemoryLogger();
		$connector = $this->getAkeebaBackupAPIConnector($logger);

		try
		{
			return (array) $connector->information();
		}
		catch (Exception $e)
		{
			return [
				'exception' => $e,
				'log'       => $logger->getItems(),
			];
		}
	}

	/**
	 * Get a list of backup records.
	 *
	 * Each returned object has the following keys:
	 * - id
	 * - description
	 * - comment
	 * - backupstart
	 * - backupend
	 * - status
	 * - origin
	 * - type
	 * - profile_id
	 * - archivename
	 * - absolute_path
	 * - multipart
	 * - tag
	 * - backupid
	 * - filesexist
	 * - remote_filename
	 * - total_size
	 * - frozen
	 * - instep
	 * - meta
	 * - hasRemoteFiles
	 *
	 * @param   bool  $cache  Should I use a cache to speed things up?
	 * @param   int   $from   Skip this many records
	 * @param   int   $limit  Maximum number of records to display
	 *
	 * @return  object[]
	 * @throws  CacheException
	 * @throws  InvalidArgumentException
	 * @since   1.0.0
	 */
	public function akeebaBackupGetBackups(bool $cache = true, int $from = 0, int $limit = 200, bool $skipConnectionCheck = false): array
	{
		if (!$skipConnectionCheck)
		{
			$this->ensureAkeebaBackupConnectionOptions();
		}

		return $this->getAkeebaBackupCacheController()->get(
			fn(Connector $connector, $from, $limit): array => $connector->getBackups($from, $limit),
			[
				$this->getAkeebaBackupAPIConnector(),
				$from,
				$limit,
			],
			sprintf('backupList-%d-%d-%d', $this->id, $from, $limit),
			$cache ? null : 0
		);
	}

	/**
	 * Retrieve a list of backup profiles
	 *
	 * @param   bool  $cache  Should I use a cache to speed things up?
	 *
	 * @return  array
	 * @throws  CacheException
	 * @throws  InvalidArgumentException
	 * @since   1.0.0
	 */
	public function akeebaBackupGetProfiles(bool $cache = true): array
	{
		$this->ensureAkeebaBackupConnectionOptions();

		return $this->getAkeebaBackupCacheController()->get(
			fn(Connector $connector): array => $connector->getProfiles(),
			[
				$this->getAkeebaBackupAPIConnector(),
			],
			sprintf(sprintf('profilesList-%d', $this->id)),
			$cache ? null : 0
		);
	}

	/**
	 * Starts taking a new backup.
	 *
	 * @param   int          $profile      The profile ID to use
	 * @param   string|null  $description  Backup description
	 * @param   string|null  $comment      Backup comment
	 *
	 * @return  object
	 * @throws  Throwable
	 * @since   1.0.0
	 */
	public function akeebaBackupStartBackup(
		int $profile = 1,
		?string $description = null,
		?string $comment = null,
		?LoggerInterface $logger = null
	): object
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$params = [
			'profile'     => (int) $profile,
			'description' => $description ?: 'Remote backup',
			'comment'     => $comment,
		];

		$httpClient = $this->getAkeebaBackupAPIClient($logger, verbose: $logger !== null);

		if ($logger !== null)
		{
			$logger->debug(
				'Akeeba Backup API request',
				[
					'task'         => 'startBackup',
					'request_url'  => $this->sanitizeAkeebaBackupUrl($httpClient->makeURL('startBackup', $params)),
					'request_body' => $params,
				]
			);
		}

		$data = $httpClient->doQuery('startBackup', $params);

		$info = $this->akeebaBackupHandleAPIResponse($data);

		$info->data = $data;

		return $info;
	}

	/**
	 * Continues taking a backup.
	 *
	 * @param   string|null  $backupId  The backup ID to continue stepping through.
	 *
	 * @return  object
	 * @throws  Throwable
	 * @since   1.0.0
	 */
	public function akeebaBackupStepBackup(?string $backupId, ?LoggerInterface $logger = null): object
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$httpClient = $this->getAkeebaBackupAPIClient($logger, verbose: $logger !== null);
		$parameters = [];

		if (!empty($backupId))
		{
			$parameters['backupid'] = $backupId;
		}

		if ($logger !== null)
		{
			$logger->debug(
				'Akeeba Backup API request',
				[
					'task'         => 'stepBackup',
					'request_url'  => $this->sanitizeAkeebaBackupUrl($httpClient->makeURL('stepBackup', $parameters)),
					'request_body' => $parameters,
				]
			);
		}

		$data = $httpClient->doQuery('stepBackup', $parameters);
		$info = $this->akeebaBackupHandleAPIResponse($data);

		$info->data = $data;

		return $info;
	}

	/**
	 * Delete a backup record.
	 *
	 * @param   int  $id  The backup record to delete
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function akeebaBackupDelete(int $id): void
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$connector = $this->getAkeebaBackupAPIConnector();

		$connector->delete($id);
	}

	/**
	 * Delete a backup record's files from the web server.
	 *
	 * @param   int  $id  The backup record whose files will be deleted
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function akeebaBackupDeleteFiles(int $id): void
	{
		$this->ensureAkeebaBackupConnectionOptions();

		$connector = $this->getAkeebaBackupAPIConnector();

		$connector->deleteFiles($id);
	}

	public function akeebaBackupGetAllScheduledTasks(): Collection
	{
		return $this->getSiteSpecificTasks('akeebabackup');
	}

	public function akeebaBackupGetEnqueuedTasks(): Collection
	{
		return $this->getSiteSpecificTasks('akeebabackup')
			->filter(
				function (Task $task) {
					$params = $task->getParams();

					// Mast not be running, or waiting to run
					if (in_array(
						$task->last_exit_code, [
							Status::INITIAL_SCHEDULE->value,
							Status::WILL_RESUME->value,
							Status::RUNNING->value,
						]
					))
					{
						return false;
					}

					// Must be a run-once task
					if (empty($params->get('run_once')))
					{
						return false;
					}

					// Must be a generated task, not a user-defined backup schedule
					if (empty($params->get('enqueued_backup')))
					{
						return false;
					}

					// Its next execution date must be empty or in the past
					if (empty($task->last_execution))
					{
						return true;
					}

					try
					{
						$date = $this->container->dateFactory($task->last_execution, 'UTC');
					}
					catch (Throwable)
					{
						return true;
					}

					$now  = $this->container->dateFactory();

					return ($date < $now);
				}
			);
	}

	/**
	 * Enqueue a new backup
	 *
	 * @param   int          $profile
	 * @param   string|null  $description
	 * @param   string|null  $comment
	 *
	 * @return  void
	 */
	public function akeebaBackupEnqueue(
		int $profile = 1, ?string $description = null, ?string $comment = null, ?User $user = null
	): void
	{
		// Try to find an akeebabackup task object which is run once, not running / initial schedule, and matches the specifics
		$tasks = $this->akeebaBackupGetEnqueuedTasks();

		if ($tasks->count())
		{
			$task = $tasks->first();
		}
		else
		{
			$task = Task::getTmpInstance('', 'Task', $this->container);
		}

		try
		{
			$tz = $this->container->appConfig->get('timezone', 'UTC');

			// Do not remove. This tests the validity of the configured timezone.
			new DateTimeZone($tz);
		}
		catch (Exception)
		{
			$tz = 'UTC';
		}

		$runDateTime = $this->container->dateFactory('now', $tz);
		$runDateTime->add(new \DateInterval('PT2S'));

		$task->save(
			[
				'site_id'         => $this->getId(),
				'type'            => 'akeebabackup',
				'params'          => json_encode(
					[
						'run_once'        => 'disable',
						'enqueued_backup' => 1,
						'profile_id'      => $profile,
						'description'     => $description,
						'comment'         => $comment ?? '',
						'initiatingUser'  => $user?->getId(),
					]
				),
				'cron_expression' => $runDateTime->minute . ' ' . $runDateTime->hour . ' ' . $runDateTime->day . ' ' .
				                     $runDateTime->month . ' ' . $runDateTime->dayofweek,
				'enabled'         => 1,
				'last_exit_code'  => Status::INITIAL_SCHEDULE->value,
				'last_execution'  => (clone $runDateTime)->sub(new \DateInterval('PT1M'))->toSql(),
				'last_run_end'    => null,
				'next_execution'  => $runDateTime->toSql(),
				'locked'          => null,
				'priority'        => 1,
			]
		);
	}

	/**
	 * Ensures that we have valid Akeeba Backup Endpoint options
	 *
	 * @return  void
	 * @since   1.0.0
	 */
	public function ensureAkeebaBackupConnectionOptions(): void
	{
		$config          = $this->getConfig();
		$info            = $config->get('akeebabackup.info');
		$endpointOptions = $config->get('akeebabackup.endpoint');

		if (empty($info) || (!empty($info?->api) && empty($endpointOptions)))
		{
			$this->getDbo()->lockTable('#__sites');
			$this->find($this->getId());

			try
			{
				$this->saveSite(
					$this,
					function (Site $model): void
					{
						$dirty = $model->testAkeebaBackupConnection(true);

						if (!$dirty)
						{
							// This short-circuits saveSite(), telling it to save nothing.
							throw new \RuntimeException('Nothing to save');
						}
					},
					function (Throwable $e): void
					{
						if (!$e instanceof \RuntimeException || $e->getMessage() !== 'Nothing to save')
						{
							throw $e;
						}
					}
				);

				$info            = $config->get('akeebabackup.info');
				$endpointOptions = $config->get('akeebabackup.endpoint');
			}
			catch (GuzzleException $e)
			{
				throw new AkeebaBackupNoInfoException(previous: $e);
			}
			finally
			{
				$this->getDbo()->unlockTables();
			}
		}

		if (empty($info) || ($info?->api ?? null) === null)
		{
			throw new AkeebaBackupNoInfoException();
		}

		if (empty($info?->api))
		{
			throw new AkeebaBackupIsNotPro();
		}

		if (empty($endpointOptions))
		{
			throw new AkeebaBackupCannotConnectException();
		}
	}

	/**
	 * Get the credentials we can present to this site's Akeeba Backup JSON API.
	 *
	 * There are two of them, and either is enough on its own.
	 *
	 * The Secret Word is the only thing the v1 and v2 APIs understand, and the only thing Akeeba Backup for
	 * WordPress and Akeeba Solo understand at all. It is deprecated — removal is planned for October 2027 — but it
	 * cannot be dropped on our side for exactly those reasons, plus one more: token authentication on the v3 API was
	 * unusable before Akeeba Backup 10.4.0.
	 *
	 * The v3 API also accepts a Joomla! API token, and that is what it prefers. A token identifies a Joomla! user
	 * account whose privileges Akeeba Backup 10.4.0 and later enforce per API method, so a site can hand us a
	 * least-privilege account; the Secret Word, by contrast, is an unscoped grant over the whole component.
	 *
	 * We do not ask the operator for that token, because the site has already given us one: the Joomla! API token
	 * Panopticon authenticates to the Panopticon connector with. WordPress sites are excluded — their API key is a
	 * Panopticon-specific token, not a Joomla! API token, and there is no v3 API on WordPress to present it to.
	 *
	 * @param   object|null  $info  The Akeeba Backup information reported by the Panopticon connector.
	 *
	 * @return  array{secret: string, token: string}
	 * @since   2.4.0
	 */
	private function getAkeebaBackupCredentials(?object $info): array
	{
		$secret = trim((string) ($info?->secret ?? ''));
		$token  = '';

		if ($this->cmsType() === CMSType::JOOMLA)
		{
			$token = trim((string) ($this->getConfig()->get('config.apiKey', '') ?? ''));
		}

		return [
			'secret' => $secret,
			'token'  => $token,
		];
	}

	/**
	 * Get the ways of connecting to this site's Akeeba Backup JSON API, in the order we will try them.
	 *
	 * Each entry is a set of overrides for the JSON API client's options, describing one way to connect. The newest
	 * API version comes first: v3 is the only one which is not deprecated, and the only one which can authenticate
	 * with anything other than the Secret Word.
	 *
	 * @param   object|null  $info  The Akeeba Backup information reported by the Panopticon connector.
	 *
	 * @return  array[]
	 * @since   2.4.0
	 */
	private function getAkeebaBackupConnectionCandidates(?object $info): array
	{
		$candidates = [];
		$v3Options  = $this->getAkeebaBackupV3Options($info);

		if ($v3Options !== null)
		{
			$candidates[] = $v3Options;
		}

		foreach ([2, 1] as $apiVersion)
		{
			foreach ((array) ($info?->endpoints?->{'v' . $apiVersion} ?? []) as $endpoint)
			{
				$endpoint = trim((string) $endpoint);

				if ($endpoint === '')
				{
					continue;
				}

				/**
				 * Pin the API version. Left to guess, the client assumes the newest version the endpoint could
				 * possibly speak, which is v3 for anything that is not obviously Akeeba Solo or WordPress — and that
				 * includes the v2 route the connector reports inside Joomla's API application, which carries no `view`
				 * parameter to give the game away.
				 *
				 * The token goes with it. Neither legacy version has any idea what a Joomla! API token is, so carrying
				 * one into these options would only store a credential which can never be presented.
				 */
				$candidates[] = [
					'host'       => $endpoint,
					'apiVersion' => $apiVersion,
					'token'      => '',
				];
			}
		}

		return $candidates;
	}

	/**
	 * Get the JSON API client options describing this site's Akeeba Backup JSON API v3 endpoint.
	 *
	 * @param   object|null  $info  The Akeeba Backup information reported by the Panopticon connector.
	 *
	 * @return  array|null  NULL when this site cannot speak the v3 API at all.
	 * @since   2.4.0
	 */
	private function getAkeebaBackupV3Options(?object $info): ?array
	{
		// The v3 API is a route in Joomla's API application. It does not exist anywhere else.
		if ($this->cmsType() !== CMSType::JOOMLA)
		{
			return null;
		}

		/**
		 * Does this site speak the v3 API at all?
		 *
		 * Connectors which know about the v3 API list an endpoint for it. Older ones do not, but they still report the
		 * Akeeba Backup API version they found, and 3 means the same thing.
		 */
		$speaksV3 = !empty((array) ($info?->endpoints?->v3 ?? [])) || ((int) ($info?->api ?? 0)) >= 3;

		if (!$speaksV3)
		{
			return null;
		}

		/**
		 * Build the endpoint from the site's own URL rather than from the one the connector reports.
		 *
		 * The v3 API is a route in the very API application we just asked for this information, so the two are the same
		 * place and taking the site's word for it gains us nothing. It costs us something, though: the site URL is
		 * checked against the operator's forbidden IP ranges every time the site is saved, and a URL parsed out of a
		 * response body is not. A compromised site naming somebody else's host would otherwise have us deliver a
		 * Joomla! API token there.
		 *
		 * The two options are split the way the client expects them: a bare host, and the path to Joomla's API
		 * application relative to it. The client joins them back together with the API route itself, so handing it the
		 * whole URL as the host would have it ask the site for the API application's path twice over.
		 */
		$uri         = new Uri(rtrim($this->getAPIEndpointURL(), '/') . '/index.php');
		$host        = $uri->toString(['scheme', 'user', 'pass', 'host', 'port']);
		$apiEndpoint = trim($uri->getPath() ?? '', '/');

		if (empty($host) || empty($apiEndpoint))
		{
			return null;
		}

		return [
			'host'        => $host,
			'apiEndpoint' => $apiEndpoint,
			'apiVersion'  => 3,
		];
	}

	/**
	 * Get the cache controller for requests to Akeeba Backup
	 *
	 * @return  CallbackController
	 * @since   1.0.0
	 */
	private function getAkeebaBackupCacheController(): CallbackController
	{
		if (empty($this->callbackControllerForAkeebaBackup))
		{
			/** @var Container $container */
			$container = $this->container;
			$pool      = $container->cacheFactory->pool('akeebabackup');

			$this->callbackControllerForAkeebaBackup = new CallbackController($container, $pool);
		}

		return $this->callbackControllerForAkeebaBackup;
	}

	/**
	 * Get the Akeeba Backup JSON API Connector object
	 *
	 * @return  Connector
	 * @since   1.0.0
	 */
	private function getAkeebaBackupAPIConnector(?LoggerInterface $logger = null): Connector
	{
		return new Connector($this->getAkeebaBackupAPIClient($logger));
	}

	/**
	 * Get the Akeeba Backup JSON API HTTP client
	 *
	 * @return  HttpClientInterface
	 */
	private function getAkeebaBackupAPIClient(?LoggerInterface $logger = null, bool $verbose = false): HttpClientInterface
	{
		$config            = $this->getConfig();
		$connectionOptions = (array) $config->get('akeebabackup.endpoint', null);

		if (empty($connectionOptions))
		{
			// This should never happen; we've already run ensureAkeebaBackupConnectionOptions to prevent this problem.
			throw new AkeebaBackupCannotConnectException();
		}

		$connectionOptions['capath'] = defined('AKEEBA_CACERT_PEM') ? AKEEBA_CACERT_PEM : null;

		/**
		 * Refresh the Joomla! API token.
		 *
		 * The token stored alongside the connection options is a copy of the site's own API token, taken when the
		 * connection was last auto-detected. Rotating the site's token would otherwise leave the backup integration
		 * presenting a dead credential until something triggers auto-detection again.
		 *
		 * We only refresh a token which is already there. Auto-detection blanks it when it settled on the Secret Word
		 * instead, and putting one back would flip every request to an authentication mode we already know this site
		 * rejects — the server ignores the Secret Word whenever a token accompanies it.
		 */
		if (!empty($connectionOptions['token'] ?? null) && $this->cmsType() === CMSType::JOOMLA)
		{
			$connectionOptions['token'] = $config->get('config.apiKey', '') ?: $connectionOptions['token'];
		}

		if ($logger)
		{
			$connectionOptions['logger']  = $logger;
			$connectionOptions['verbose'] = $verbose;
		}

		$options = new JsonApiOptions($connectionOptions);

		return new HttpClientGuzzle($options);
	}

	/**
	 * Returns the given URL with the _akeebaAuth query parameter value redacted.
	 */
	private function sanitizeAkeebaBackupUrl(string $url): string
	{
		return preg_replace('/([?&]_akeebaAuth=)[^&]+/', '$1[REDACTED]', $url);
	}

	private function akeebaBackupHandleAPIResponse(object $data): object
	{
		$backupID       = null;
		$backupRecordID = 0;
		$archive        = '';

		if ($data->body?->status != 200)
		{
			throw new RemoteError('Error ' . $data->body->status . ": " . $data->body->data);
		}

		if (isset($data->body->data->BackupID))
		{
			$backupRecordID = $data->body->data->BackupID;
		}

		if (isset($data->body->data->backupid))
		{
			$backupID = $data->body->data->backupid;
		}

		if (isset($data->body->data->Archive))
		{
			$archive = $data->body->data->Archive;
		}

		return (object) [
			'backupID'       => $backupID,
			'backupRecordID' => $backupRecordID,
			'archive'        => $archive,
		];
	}
}
