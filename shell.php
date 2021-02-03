<?php
    use lecodeurdudimanche\PHPPlayer\Manager;
    use lecodeurdudimanche\Processes\Command;
    use lecodeurdudimanche\PHPPlayer\MusicDaemon;

    require("vendor/autoload.php");

    function cmd_add($m, $args) {
        if (count($args) == 2 || count($args) == 3)
        {
            $m->queueMusic($args[0], $args[1], count($args) == 3 ? intval($args[2]) : MusicDaemon::LAST_POS);
        }
        else
            echo "Usage : add type uri [pos] (where type one of url, local or youtube)\n";
    }

    function cmd_del($m, $args)
    {
        if (count($args) != 1)
            echo "Usage : del index\n";
        else
            $m->removeMusic($args[0]);
    }

    function cmd_play($m, $args) {
        sendCommand($m, $args, 'play');
    }

    function cmd_pause($m, $args) {
        sendCommand($m, $args, 'pause');
    }

    function cmd_next($m, $args) {
        sendCommand($m, $args, 'next');
    }

    function cmd_prev($m, $args) {
        sendCommand($m, $args, 'prev');
    }

    function sendCommand($m, $args, $cmd)
    {
        if (count($args)) echo "Warning : ignoring arguments\n";
        $m->$cmd();
    }

    function cmd_kill(&$m, $args) {
        if (count($args) == 1 && $args[0] == "--force")
            $m->killDaemon(true);
        else if (count($args) == 0)
            $m->killDaemon(false);
        else
            echo "Usage : kill [--force]\n";

        $m = null;
    }

    function cmd_set($m, $args)
    {
        if (count($args) != 2)
            echo "Usage : set key value\n";
        else
            $m->setConfigurationOption($args[0], $args[1]);
    }

    function cmd_get($m, $args)
    {
        if (count($args) > 1) {
            echo "Usage : get [key]\n";
            return;
        }
        if (count($args) == 1)
            $keys = [$args[0]];
        else
            $keys = ["format", "caching_time", "normalize_audio"];

        foreach ($keys as $key)
        {
            echo "$key : ";
            $value = $m->getConfigurationOption($key);
            if ($value !== null)
                echo $value . "\n";
            else
                printFeedBack($m);
        }

    }

    function cmd_help()
    {
        global $commands;
        echo "Available commands : " . implode(" ", $commands) . "\n";
    }

    function cmd_start(&$m)
    {
        $m = new Manager();
    }

    function cmd_query($m)
    {
        $data = $m->syncPlaybackStatus();
        echo $data . "\n";
    }

    function printFeedback($m)
    {
        $messages = $m->getLastServerData();
        if (count($messages))
            echo "Informations du serveur :\n";

        foreach ($messages as $m)
        {
            $data = $m->getData();
            echo "\t$data[type] : $data[message]\n";
        }
    }

    echo "Starting manager...";
    $manager = new Manager();
    echo "\r                   \r";

    $commands = ["add", "del", "play", "pause", "next", "prev", "query", "kill", "start", "set", "get", "help"];

    while (true)
    {
        $command = readline("music > ");

        if (!$command || $command == "exit")
            break;

        $args = preg_split("/['\"][^'\"]*['\"](*SKIP)(*F)|\s+/", $command);
        if ($command[0] == "!")
        {
            $data = (new Command(substr($command, 1)))->execute();
            echo $data['out'];
            fprintf(STDERR, $data['err']);
            readline_add_history($command);
        }
        else if (! in_array($args[0], $commands))
                echo "Invalid command : $args[0]\n";
        else {
            if ($manager == null)
                $manager = new Manager();

            printFeedback($manager);

            $function = "cmd_" . array_shift($args);
            $function($manager, $args);
            readline_add_history($command);
        }

    }
