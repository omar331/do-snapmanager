<?php
$loader = require_once __DIR__ . '/vendor/autoload.php';

include_once('./src/DigitalOcean/SnapManager.php');

/**
 * Default options
 */
include_once('config.php');

$snapmanager = new DigitalOcean\SnapManager( $config );


/*
 * Parse command line options
 */
$args = getopt(null, ['prefix:', 'help']);

if ( array_key_exists('help', $args) ) {
    show_help();
    exit;
}

// prefix to snapshot names
if ( array_key_exists('prefix', $args) ) {
    $snapmanager->setBasePrefix($args['prefix']);
}

// number of snapshots to be kept for each droplet
if ( array_key_exists('keep-snapshots', $args) ) {
    $snapmanager->setKeepSnapshots($args['keep-snapshots']);
}

$snapmanager->run();






function show_help() {
    echo "Digital Ocean snapshot manager\n";
    echo "Options\n\n";

    echo "--prefix     prefix prepended to snapshot's name\n";
    echo "--keep-snapshots     number of snapshots to be kept for each droplet\n";

    echo "\n\n";
}