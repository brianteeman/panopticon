<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Exception\AkeebaBackup;

defined('AKEEBA') || die;

/**
 * The site gave us nothing we can authenticate to its Akeeba Backup JSON API with.
 *
 * Neither an Akeeba Backup Secret Word nor a Joomla! API token is available. Extends AkeebaBackupNoEndpoint so that
 * anything which already handles “we could not find a way in” keeps handling this, too.
 *
 * @since 2.4.0
 */
class AkeebaBackupNoCredentials extends AkeebaBackupNoEndpoint
{

}
