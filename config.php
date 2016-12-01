<?php

$config = [
    // ---> Digital Ocean API Token
    'do_api_token' => 'cf9b479d82e12c28ec18f26ccd85b2b96028636b2c517ff885f4790ed5557fb2',

    'logfile' => __DIR__ . '/log/logfile.log',

    //
    // ---> Droplets to create snapshots from
    //
    'droplets' => [
         [
            'name' => 'prod-mongo-db',
            'keep-snapshots' => 3,
            'copy-to-regions' => ['sfo2']
         ],
     ],

];