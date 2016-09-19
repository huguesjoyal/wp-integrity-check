#!/usr/bin/env php
<?php
define('BASE_PATH', __DIR__);
define('DS', DIRECTORY_SEPARATOR);

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;
use Composer\Autoload\ClassLoader;
use WPIntegrityCheck\IntegrityCheckApp;

$loader = new ClassLoader();
$loader->addPsr4('WPIntegrityCheck\\', __DIR__ . '/src');
$loader->register();

$application = new IntegrityCheckApp('Wordpress Integrity Check', '1.0');
$application->run();
