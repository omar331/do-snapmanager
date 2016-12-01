<?php
$loader = require_once __DIR__ . '/vendor/autoload.php';

include_once('./src/DigitalOcean/SnapManager.php');

include_once('config.php');

$snapmanager = new DigitalOcean\SnapManager( $config );

$snapmanager->run();



