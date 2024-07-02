<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2024 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

$lang = \Akeeba\Panopticon\Factory::getContainer()->language;
$appConfig   = \Akeeba\Panopticon\Factory::getContainer()->appConfig;
$extend      = (bool) $appConfig->get('login_lockout_extend', 0);

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?= $lang->text('PANOPTICON_SYSERROR_FORBIDDEN_TITLE') ?></title>

	<style>
        html {
            background: #c81d23;
            background-image: radial-gradient(ellipse at center, #e86367 0, #e2363c 60%, #c81d23 100%);
            background-attachment: fixed;
            background-repeat: no-repeat;
            color: #373637;
            font-family: sans-serif;
        }

        body {
            background-color: transparent;
        }

        body {
            margin: 0;
            padding: 0;
        }

        .container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            width: 100vw;
            margin: 0 auto;
            overflow: hidden;
            position: relative;
        }

        .alert-main {
            background: #fcfcfc;
            border: 1px solid #373637;
            border-radius: .5rem;
            box-shadow: 0 0 1rem rgba(55, 54, 55, .6);
            display: block;
            margin: 0 20px;
            padding: 20px 60px;
            position: relative;
        }

        h1, p {
            text-rendering: optimizeLegibility;
            text-align: center;
        }

        h1 {
            font-size: 1.5rem;
            font-weight: 400;
            margin: 0.25rem 0 1.5rem;
            padding: 0;
            color: #c81d23;
        }

        p {
            font-size: 1rem;
            font-weight: 300;
            margin: 1rem 0 .25rem;
        }
	</style>
</head>
<body>
<div class="container">
	<div class="container-main">
		<div class="alert-main">
			<h1 id="headerText">
				<span aria-hidden="true">⛔</span>
				<?= $lang->text('PANOPTICON_SYSERROR_FORBIDDEN_HEAD') ?>
			</h1>
			<p>
				<?= $lang->sprintf('PANOPTICON_SYSERROR_FORBIDDEN_INFO', \Awf\Utils\Ip::getUserIP()) ?>
			</p>
			<?php if ($extend): ?>
			<p>
				<span aria-hidden="true">⚠️</span>
				<?= $lang->text('PANOPTICON_SYSERROR_FORBIDDEN_EXTENDING') ?>
			</p>
			<?php endif ?>
		</div>
	</div>
</div>


</body>
</html>