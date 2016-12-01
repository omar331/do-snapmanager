<?php
namespace DigitalOcean;

use DigitalOceanV2\Api\Droplet;
use DigitalOceanV2\Entity\Image;
use DigitalOceanV2\Exception\HttpException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


use DigitalOceanV2\Adapter\BuzzAdapter;
use DigitalOceanV2\DigitalOceanV2;


class SnapManager {
    const SNAP_PREFIX_BASE = 'snp-';

    const DO_SNAPSET_TYPE_DROPLET = 'droplet';
    const DO_SNAPSET_TYPE_VOLUME = 'volume';

    /** @var Logger $logger */
    protected $logger;

    /** @var DigitalOceanV2 $digitalocean */
    protected $digitalocean;

    protected $config;



    public function __construct( $config ) {

        $this->config = $config;

        // create a log channel
        $this->logger = new Logger('name');
        $this->logger->pushHandler(new StreamHandler( $config['logfile'], Logger::INFO));

        //
        $adapter = new BuzzAdapter( $this->config['do_api_token'] );

        // create a digital ocean object with the previous adapter
        $this->digitalocean = new DigitalOceanV2($adapter);
    }



    public function run() {
        $this->logger->info('Starting snap manager');

        /*
         * ---> Trigger snapshot creation
         */
        $this->logger->info('Starting snapshots creation...');
        $createSnapActions = $this->startSnapshotCreation( $this->config['droplets'] );


        /*
         * ---> track creation actions.
         *      After finishing each one of them,
         *      trigger copy to other regions
         */
        $this->trackCreateSnapActions($createSnapActions);
    }


    /**
     * Track snapshot creation actions.
     * When each one is over, trigger copy to the specified regions
     * @param $createSnapActions
     */
    public function trackCreateSnapActions( $createSnapActions ) {

        $pendingActions = sizeof($createSnapActions);

        while ( $pendingActions > 0 ) {
            for( $i = 0; $i < sizeof($createSnapActions); $i++ ) {
                $createAction = $createSnapActions[$i];

                $action = $this->digitalocean->action()->getById( $createAction['actionId'] );

                echo sprintf('ActionId = %d   status = %s', $action->id, $action->status );
                echo "\n\n\n";

                // action completed
                if ( $action->status == 'completed' ) {
                    $pendingActions--;

                    $this->logger->info( sprintf('Droplet snaphot (%s) creation completed.', $createAction['droplet']->name ) );

                    // copy snapshot to specified regions
                    $this->startCopyToRegions(
                            $createAction['droplet'],
                            $createAction['snapshotName'],
                            $createAction['snapConfig']['copy-to-regions']
                    );

                    array_splice($createSnapActions, $i, 1);
                }

            }

            sleep(20);
        }
    }




    /**
     * Starts copying to other regions
     *
     * @param \DigitalOceanV2\Entity\Droplet $droplet
     * @param $snapshotName
     * @param array $copyToRegions
     */
    public function startCopyToRegions( \DigitalOceanV2\Entity\Droplet $droplet, $snapshotName, Array $copyToRegions ) {

        // Get snapshot
        $managedSnapshots = $this->getManagedDropletSnapshots( $droplet );

        /** @var Image $selectedSnapshot */
        $selectedSnapshot = null;

        /** @var Image $snapshot */
        foreach ( $managedSnapshots as $snapshot ) {
            if ( $snapshot->name != $snapshotName ) continue;
            $selectedSnapshot = $snapshot;
        }


        if ( $selectedSnapshot ) {
            foreach( $copyToRegions as $region ) {
                $this->logger->info('Starting tranfer to region', $region);

                try {
                    $action = $this->digitalocean->image()
                        ->transfer( $selectedSnapshot->id, $region );
                } catch ( \RuntimeException $e ) {
                    $this->logger->err('Failed to start copy to region', $region);
                }
            }
        }
    }





    /**
     * Starts snapshot creations and return an array with
     * corresponding DO actions
     *
     * @param array $configs
     * @return actions
     */
    public function startSnapshotCreation( Array $configs ) {
        /** @var  $trackActions actions to be tracked */
        $trackActions = [];

        foreach ( $configs as $dropletSnapConfig ) {
            $droplet = $this->getDoObject( $dropletSnapConfig['name'] );

            try {
                // invoke snapshoot creation and store corresponding action
                $actionInfo = $this->createSnapshot( $droplet, $dropletSnapConfig );

                $trackActions[] = $actionInfo;
            } catch ( HttpException  $e ) {
                $this->logger->err( sprintf("Can't start snapshot creation. Message: %s", $e->getMessage()),
                    $dropletSnapConfig
                );
            }
        }

        return $trackActions;
    }



    /**
     * Creates a new managed snapshot for a droplet
     *
     * @param \DigitalOceanV2\Entity\Droplet $droplet
     * @param Array $snapConfig   configurações do snapshot a ser criado
     *
     * @return Array
     */
    public function createSnapshot( \DigitalOceanV2\Entity\Droplet $droplet, $snapConfig = [] ) {
        $manager = $this->getDoObjectManager();
        $snapshotName = $this->getNewSnapshotName( $droplet->name );

        $action =  $manager->snapshot( $droplet->id, $snapshotName );

        $actionInfo = [
            'actionId' => $action->id,
            'droplet' => $droplet,
            'snapConfig' => $snapConfig,
            'snapshotName' => $snapshotName
        ];

        $this->logger->info(
            sprintf('Creating snapshot for droplet %s(id: %d) snapshot name: %s. Action ID = %d',
                        $droplet->name,
                        $droplet->id,
                        $snapshotName,
                        $action->id
            )
        );

        return $actionInfo;
    }




    /**
     * Create a new for a new droplet
     * @param $dropletName
     * @return string
     */
    private function getNewSnapshotName( $dropletName ) {
        return sprintf('%s-%04d-%02d-%02d-%02-%02d-%02d',
                $this->getSnapshotPrefix( $dropletName ),
                Date('Y'),
                Date('m'),
                Date('d'),
                Date('H'),
                Date('i'),
                Date('s')
            );
    }


    /**
     * Get prefix for managed snapshoots
     * @return string
     * @internal param $dropletName
     */
    private function getSnapshotPrefix( $dropletName ) {
        return sprintf('%s%s',  static::SNAP_PREFIX_BASE, $dropletName);
    }


    /**
     * Get Digital Ocean Object by name
     *
     * @param $name
     * @return \DigitalOceanV2\Entity\Droplet|null
     */
    private function getDoObject( $name ) {
        $ret = null;

        $manager = $this->getDoObjectManager();

        foreach( $manager->getAll() as $object ) {
            if ( $object->name != $name  ) continue;
            $ret = $object;
        }

        return $ret;
    }


    /**
     * Get a list of managed droplets
     *
     * @param \DigitalOceanV2\Entity\Droplet $droplet
     * @return array<DigitalOceanV2\Entity\Image>
     */
    protected function getManagedDropletSnapshots( \DigitalOceanV2\Entity\Droplet $droplet ) {
        // prefixes to managed snapshoot`s name
        $snapPrefix = $this->getSnapshotPrefix( $droplet->name );

        $managedOnes = [];
        foreach( $this->getDropletsSnapshots($droplet) as $droplet ) {
            if ( ! strpos($droplet->name, $snapPrefix)  ) continue;
            $managedOnes[] = $droplet;
        }

        return $managedOnes;
    }





    /**
     * Get existent snapshots within a droplet
     *
     * @param \DigitalOceanV2\Entity\Droplet $droplet
     * @return array<DigitalOceanV2\Entity\Image>
     */
    protected function getDropletsSnapshots( \DigitalOceanV2\Entity\Droplet $droplet ) {
        $snapshotManager = $this->digitalocean->image();

        $snaphots = [];
        foreach ( $droplet->snapshotIds as $id ) {
            $snaphots[] = $snapshotManager->getById( $id );
        }

        usort(
            $snaphots,
            function( \DigitalOceanV2\Entity\Image $a, \DigitalOceanV2\Entity\Image $b) {
                return($a->createdAt < $b->createdAt );
            }
        );


        return $snaphots;
    }



    public function getDoObjectManager(  ) {
        return $this->digitalocean->droplet();
    }


}