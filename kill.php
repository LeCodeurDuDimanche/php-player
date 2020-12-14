<?php
    use lecodeurdudimanche\PHPPlayer\Manager;
    require("vendor/autoload.php");

    if (! Manager::fetchDaemonPID())
    {
        echo "No daemon running\n";
        exit;
    }

    $m = new Manager();

    echo "Trying to shut down the daemon\n";
    $maxTries = 3;
    for ($i = 1; $i <= $maxTries && Manager::fetchDaemonPID(); $i++)
    {
        echo "\rSending kill command, try $i/$maxTries...   ";
        $m->killDaemon();
        sleep(1);
    }
    echo "\n";

    if (Manager::fetchDaemonPID()) {
        echo "Force killing daemon...\n";
        $m->killDaemon(true);
    }
    echo "Done\n";
