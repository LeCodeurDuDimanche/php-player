<?php
    namespace lecodeurdudimanche\PHPPlayer;

    use lecodeurdudimanche\UnixStream\{UnixStream, Message};
    use lecodeurdudimanche\Processes\Command;

    class Manager {

        private const PLAYBACK_COMMANDS = ['play', 'pause', 'prev', 'next'];

        protected $daemonPID;
        protected $playbackStatus;
        protected $stream;

        public function __construct()
        {
            $this->playbackStatus = new PlaybackStatus;

            $this->ensureDaemonIsRunning();

            $this->openStream();
        }

        private function ensureDaemonIsRunning() : void
        {
            $daemonFile = __DIR__ . "/daemon.php";
            while (! $this->daemonPID = self::fetchDaemonPID())
            {
                 echo "Starting control daemon...\n";

                if (!is_dir("/tmp/php-player"))
                    mkdir("/tmp/php-player");

                $output = (new Command("nohup php $daemonFile 1> /tmp/php-player/daemon-out 2> /tmp/php-player/daemon-err &"))->execute();
                if ($output['err'])
                    throw new \Exception("Failed to start daemon : $output[err]");
                sleep(1);
            }
            echo "Daemon PID is $this->daemonPID\n";
        }

        private function openStream() : void
        {
            $this->stream = new UnixStream(MusicDaemon::getSocketFile());
        }

        public static function fetchDaemonPID() : int
        {
            $command = new Command("ps ax|grep -E \"php .*php-player/src/daemon.php\"|grep -v 'grep'");
            $result = $command->execute();
            return intval($result["out"]);
        }

        public function killDaemon(bool $force = false) : void
        {
            if ($force)
                (new Command("kill -9 $this->daemonPID"))->execute();
            else
                $this->stream->write(new Message(MessageType::KILL, ""));
        }


        public function queueMusic(string $type, string $uri, int $position = MusicDaemon::LAST_POS) : void
        {
            $data = compact("type", "uri", "position");
            $this->stream->write(new Message(MessageType::QUEUE_MUSIC, $data));
        }

        public function __call($name, $arguments)
        {
            if (in_array($name, self::PLAYBACK_COMMANDS))
                return $this->sendCommand($name);

            throw new \BadMethodCallException("Call to undefined method " . self::class . "::$name");

        }

        public function syncPlaybackStatus() : PlaybackStatus
        {
            $this->stream->write(new Message(MessageType::QUERY, null));

            // Pas ouf vu qu'on ignore des trucs potentiellement important, mais normalement c'est ok
            $message = $this->stream->readNext([MessageType::PLAYBACK_DATA]);

            $this->playbackStatus = PlaybackStatus::fromArray($message->getData());

            return $this->getPlaybackStatus();
        }

        public function getPlaybackStatus() : PlaybackStatus
        {
            return $this->playbackStatus;
        }

        private function sendCommand(string $cmd) : void
        {
            $m = new Message(MessageType::PLAYBACK_COMMAND, $cmd);
            $this->stream->write($m);
        }
    }
