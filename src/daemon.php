<?php
    namespace lecodeurdudimanche\PHPPlayer;


    $autoloadLocations = ["/../vendor/autoload.php", "vendor/autoload.php", "../../../autoload.php"];
    foreach($autoloadLocations as $potentialAutoload)
    {
        $potentialAutoload = __DIR__ . "/" . $potentialAutoload;
        if (\file_exists($potentialAutoload))
        {
            include($potentialAutoload);
            break;
        }
    }
    (new MusicDaemon($argv[1] ?? null))->run();
