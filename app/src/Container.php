<?php
/*
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Panopticon;

defined('AKEEBA') || die;

use Akeeba\Panopticon\Application\Configuration;
use Akeeba\Panopticon\Library\Cache\CacheFactory;
use Akeeba\Panopticon\Library\Http\HttpFactory;
use Akeeba\Panopticon\Library\Logger\LoggerFactoryService;
use Akeeba\Panopticon\Library\Task\Registry as TaskRegistry;
use Awf\Container\Container as AWFContainer;
use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * @property-read Configuration                             $appConfig     The application configuration registry
 * @property-read CacheFactory                              $cacheFactory  The cache pool factory
 * @property-read HttpFactory                               $httpFactory   A factory for Guzzle HTTP client instances
 * @property-read LoggerFactoryService                      $loggerFactory A factory for LoggerInterface instances
 * @property-read LoggerInterface                           $logger        The main application logger
 * @property-read TaskRegistry                              $taskRegistry  The task callback registry
 */
class Container extends AWFContainer
{
	public function __construct(array $values = [])
	{
		$values['application_name']     ??= 'Panopticon';
		$values['applicationNamespace'] ??= 'Akeeba\\Panopticon';
		$values['basePath']             ??= APATH_ROOT;
		$values['session_segment_name'] ??= sha1(__DIR__ . '-' . AKEEBA_PANOPTICON_VERSION . '-' . AKEEBA_PANOPTICON_DATE);

		$values['appConfig'] ??= function (Container $c) {
			return new Configuration($c);
		};

		$values['cacheFactory'] ??= function (Container $c) {
			return new CacheFactory();
		};

		$values['httpFactory'] ??= function (Container $c) {
			return new HttpFactory();
		};

		$values['taskRegistry'] ??= function (Container $c) {
			return new TaskRegistry(container: $c);
		};

		$values['loggerFactory'] ??= function (Container $c) {
			return new LoggerFactoryService($c);
		};

		$values['logger'] ??= fn(Container $c) => $c->loggerFactory->get('panopticon');

		parent::__construct($values);
	}
}