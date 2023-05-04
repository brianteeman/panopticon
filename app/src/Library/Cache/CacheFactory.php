<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Panopticon\Library\Cache;


use Cache\Adapter\Filesystem\FilesystemCachePool;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

defined('AKEEBA') || die;

class CacheFactory
{
	private static $instances = [];

	public function pool($poolName = 'system'): CacheItemPoolInterface|CacheItemInterface
	{
		if (!$this->isValid($poolName))
		{
			throw new \InvalidArgumentException('Invalid cache pool name', 500);
		}

		if (isset(self::$instances[$poolName]))
		{
			return self::$instances[$poolName];
		}

		$filesystemAdapter = new Local(APATH_CACHE);
		$filesystem        = new Filesystem($filesystemAdapter);

		self::$instances[$poolName] = new FilesystemCachePool($filesystem, $poolName);

		return self::$instances[$poolName];
	}

	private function isValid(mixed $poolName)
	{
		// Anything starting with a dot; catches ., .., and *nix hidden files/folders including .htaccess and .gitignore
		if (str_starts_with($poolName, '.'))
		{
			return false;
		}

		// Anything outside the cross-platform safe filename characters. Note that **only** latin-1 alphas are allowed.
		if (!preg_match('/[a-zA-Z0-9\-_!@#$%^&()\[\]{};\',.]/', $poolName))
		{
			return false;
		}

		// Windows does not allow filenames ending with a space or dot, so let's prevent their use
		if (str_ends_with($poolName, " ") || str_ends_with($poolName, "."))
		{
			return false;
		}

		// Reserved filenames across Windows and *nix systems, plus web.config (shipped with Panopticon)
		if (
			array_reduce(
				[
					'CON', 'PRN', 'AUX', 'NUL', 'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
					'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9', 'STDIN', 'STDOUT',
					'WEB.CONFIG'
				],
				fn(bool $carry, string $item) => $carry || str_starts_with(strtoupper($poolName), $item),
				false
			)
		)
		{
			return false;
		}

		return true;
	}
}