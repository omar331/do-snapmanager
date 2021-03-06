<?php
namespace DigitalOcean;

use DigitalOceanV2\Entity\Image;
use DigitalOceanV2\Exception\HttpException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


use DigitalOceanV2\Adapter\BuzzAdapter;
use DigitalOceanV2\DigitalOceanV2;


class SnapManager {
    const DO_SNAPSET_TYPE_DROPLET = 'droplet';
    const DO_SNAPSET_TYPE_VOLUME = 'volume';

    /** @var Logger $logger */
    protected $logger;

    /** @var DigitalOceanV2 $digitalocean */
    protected $digitalocean;

    protected $config;

    protected $basePrefix = 'snp-';

    // number of snapshots to be kept for each dropdroplet
    protected $keepSnapshots = 3;


    public function __construct( $config ) {

        $this->config = $config;

        // create a log channel
        $this->logger = new Logger('name');
        $this->logger->pushHandler(new StreamHandler( $config['logfile'], Logger::INFO));

        //
        $adapter = new BuzzAdapter( $this->config['do_api_token'] );

        // create a digital ocean object with the previous adapter
        $this->digitalocean = new DigitalOceanV2($adapter);

        if ( array_key_exists('keep-snapshots', $config ) ) {
            $this->setKeepSnapshots( $config['keep-snapshots'] );
        }
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


        /**
         * Prune older snapshots
         */
        $this->pruneOlderSnapshots( $this->config['droplets'] );
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

                // action completed
                if ( $action->status == 'completed' ) {
                    $pendingActions--;

                    $this->logger->info( sprintf('Droplet snapshot (%s) creation completed.', $createAction['droplet']->name ) );

                    // copy snapshot to specified regions
                    $this-> startCopyToRegions(
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
        $this->logger->info( sprintf('Started copying snapshot %s to other regions.', $snapshotName ), $copyToRegions );

        $selectedSnapshot = $this->getSnapshotByName( $droplet, $snapshotName );

        if ( $selectedSnapshot ) {
            foreach( $copyToRegions as $region ) {
                $this->logger->info('Starting tranfer to region', [$region]);

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
     * Reload droplet information
     *
     * @param \DigitalOceanV2\Entity\Droplet $droplet
     * @return \DigitalOceanV2\Entity\Droplet|null
     */
    public function refreshDroplet( \DigitalOceanV2\Entity\Droplet $droplet ) {
        return $this->getDoObject( $droplet->name );
    }





    /**
     * Get a snapshot from a droplet by its name
     *
     * @param \DigitalOceanV2\Entity\Droplet $droplet
     * @param $snapshotName
     * @return Image
     */
    public function getSnapshotByName( \DigitalOceanV2\Entity\Droplet $droplet, $snapshotName ) {
        /** @var Image $selectedSnapshot */
        $selectedSnapshot = null;

        $attempts = 0;
        while ( ( $selectedSnapshot === null  ) and ( $attempts < 10 ) ) {
            $attempts++;

            $droplet = $this->refreshDroplet( $droplet );

            // Get snapshot
            try {
                $this->logger->info( sprintf('Getting managed snapshots of droplet %s', $droplet->name) );

                $managedSnapshots = $this->getManagedDropletSnapshots( $droplet );
            } catch ( \RuntimeException $e ) {
                $this->logger->warn( sprintf('Failed to get managed snapshots of droplet %s', $droplet->name) );
                continue;
            }

            echo " GOT MANAGED SNAPSHOTS   **** \n";

            /** @var Image $snapshot */
            foreach ( $managedSnapshots as $snapshot ) {
                if ( $snapshot->name != $snapshotName ) continue;
                $selectedSnapshot = $snapshot;
            }

            sleep(30);
        }

        return $selectedSnapshot;
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
        return sprintf('%s-%s',  $this->getBasePrefix(), $dropletName);
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
        foreach( $this->getDropletsSnapshots($droplet) as $snapshot ) {
            if ( strpos($snapshot->name, $snapPrefix) === FALSE  ) continue;
            $managedOnes[] = $snapshot;
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


    /**
     * Prune older snapshots
     * @param $configs
     * @return actions
     */
    public function pruneOlderSnapshots( $configs ) {
        /** @var  $trackActions actions to be tracked */
        $trackActions = [];

        $this->logger->info('Pruning older managed snapshots', $configs);

        foreach ( $configs as $dropletSnapConfig ) {
            // snapshots to be kept for current droplet
            $keepSnapshots = $this->getKeepSnapshots();

            $droplet = $this->getDoObject( $dropletSnapConfig['name'] );
            $managedSnapshots = $this->getManagedDropletSnapshots( $droplet );

            $this->logger->info( sprintf('Removing snapshots from droplet %s', $droplet->name ) );

            $n = 0;
            /** @var Image $snapshot */
            foreach( $managedSnapshots as $snapshot ) {
                $n++;

                // this one must be kept
                if ( $n <= $keepSnapshots ) continue;

                // otherwise, it's older and have to be pruned
                $this->logger->info( sprintf('Removing snapshots %s', $snapshot->name ) );

                try {
                    $action = $this->digitalocean->image()
                        ->delete($snapshot->id);
                    $trackActions[] = $trackActions;
                } catch ( \RuntimeException $e ) {
                    $this->logger->error( sprintf('Couldn\'t remove snapshot %s. Got exception %s', $snapshot->name, $e->getMessage() ) );
                }
            }
        }

        return $trackActions;
    }




    public function getDoObjectManager() {
        return $this->digitalocean->droplet();
    }



    /**
     * @return string
     */
    public function getBasePrefix()
    {
        return $this->basePrefix;
    }

    /**
     * @param string $basePrefix
     */
    public function setBasePrefix($basePrefix)
    {
        $this->basePrefix = $basePrefix;
    }

    /**
     * @return int
     */
    public function getKeepSnapshots()
    {
        return $this->keepSnapshots;
    }

    /**
     * @param int $keepSnapshots
     */
    public function setKeepSnapshots($keepSnapshots)
    {
        $this->keepSnapshots = $keepSnapshots;
    }

}