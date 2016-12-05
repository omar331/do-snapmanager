# DO-SNAPMANAGER

Manage a set of snapshots of Digital Ocean's Droplets. 

This script can be used as a backup mechanism, allowing it to set a rotative 
set of snapshots. For example, you can run this script once a week to
get a new snapshot of each droplet and keep the last most new snapshots.
 
Additionally you can copy each snapshot to other Digital Ocean's data centers. For instance,
if you run the droplets at **NYC1**, it's possible to generate a set of snapshots and immediately
sent each of them to **SFO2**.


### Installation

* Download and extract the latest version of **do-snapmanager** in Github.

* Extract it into some folder. Chdir to that folder and run

```composer install```

Copy the file ```config.php.SAMPLE``` as ```config.php```, edit the file and setup your environment.









