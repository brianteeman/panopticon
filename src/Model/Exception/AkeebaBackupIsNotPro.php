<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Model\Exception;


use JetBrains\PhpStorm\Pure;
use Throwable;

defined('AKEEBA') || die;

class AkeebaBackupIsNotPro extends AkeebaBackupException
{
	#[Pure] public function __construct(string $message = "", int $code = 500, ?Throwable $previous = null)
	{
		$message = $message ?: "You do not seem to have Akeeba Backup Professional installed.";

		parent::__construct($message, $code, $previous);
	}

}