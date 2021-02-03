<?php
    namespace lecodeurdudimanche\PHPPlayer;

    use lecodeurdudimanche\UnixStream\{IOException, UnixStream, Message};
    use lecodeurdudimanche\Processes\Command;

    class Manager {

        private const PLAYBACK_COMMANDS = ['play', 'pause', 'prev', 'next'];

        protected $daemonPID;
        protected $playbackStatus;
        protected $stream;
        protected $cachedDir;

        public function __construct(?string $cacheDir = null)
        {
            $this->playbackStatus = new PlaybackStatus;
            $this->cacheDir = $cacheDir;

            $this->resetConnection();
        }

        private function resetConnection() : void
        {
            $this->ensureDaemonIsRunning();
            $this->openStream();
        }

        private function ensureDaemonIsRunning() : void
        {
            $daemonFile = __DIR__ . "/daemon.php";
            while (! $this->daemonPID = self::fetchDaemonPID())
            {
                 //echo "Starting control daemon...\n";

                if (!is_dir("/tmp/php-player"))
                    mkdir("/tmp/php-player");

                $output = (new Command("nohup php $daemonFile " . ($this->cacheDir ?? "") . " 1> /tmp/php-player/daemon-out 2> /tmp/php-player/daemon-err &"))->execute();
                if ($output['err'])
                    throw new \Exception("Failed to start daemon : $output[err]");
                sleep(1);
            }
            //echo "Daemon PID is $this->daemonPID\n";
        }

        private function openStream() : void
        {
            $this->stream = new UnixStream(MusicDaemon::getSocketFile());
        }

        public static function fetchDaemonPID() : int
        {
            $command = new Command("ps axo pid,cmd|grep -E \"[0-9]{1,} php .*php-player/src/daemon.php\"");
            $result = $command->execute();
            return intval($result["out"]);
        }

        public function killDaemon(bool $force = false) : bool
        {
            if ($force)
                return !(new Command("kill -9 $this->daemonPID"))->execute()['err'];
            else
                return $this->write(new Message(MessageType::KILL, ""));
        }

        public function setConfigurationOption(string $key, string $value) : bool
        {
            return $this->write(new Message(MessageType::CONF_SET, compact("key", "value")));
        }

        public function getConfigurationOption(string $key) : ?string
        {
            if (! $this->write(new Message(MessageType::CONF_GET, compact('key'))))
                return null;

            $value = null;
            do {
                $message = $this->doIO(function($stream) {
                    return $stream->readNext([MessageType::CONF_DATA, MessageType::FEEDBACK_DATA], true, UnixStream::MODE_READ);
                });

                if ($message === false)
                    return null;
                else if ($message->getType() == MessageType::FEEDBACK_DATA)
                {
                    $this->stream->addToBuffer($message);
                    if ($message->getData()['type'] == 'error')
                        return null;
                }
                else if ($message->getData()['key'] === $key)
                    $value = $message->getData()['value'];

            } while ($value === null);
            return $value;
        }

        public function queueMusic(string $type, string $uri, int $position = MusicDaemon::LAST_POS) : bool
        {
            $data = compact("type", "uri", "position");
            return $this->write(new Message(MessageType::QUEUE_MUSIC, $data));
        }

        public function removeMusic(int $position) : bool
        {
            return $this->write(new Message(MessageType::REMOVE_MUSIC, ["index" => $position]));
        }


        public function __call($name, $arguments)
        {
            if (in_array($name, self::PLAYBACK_COMMANDS))
                return $this->sendCommand($name);

            throw new \BadMethodCallException("Call to undefined method " . self::class . "::$name");

        }

        public function getLastServerData() : array
        {
            $array = array();
            while ($mes = $this->doIO(function ($stream) { return $stream->readNext([MessageType::FEEDBACK_DATA], false, UnixStream::MODE_READ); }))
                array_push($array, $mes);
            return $array;
        }

        public function syncPlaybackStatus() : ?PlaybackStatus
        {
            if (! $this->write(new Message(MessageType::QUERY, null)))
                return null;

            //echo "Fetching playback data \n";
            // We wait for the next message, not discarding other messages
            $message = $this->doIO(function($stream) {
                return $stream->readNext([MessageType::PLAYBACK_DATA], true, UnixStream::MODE_READ);
            });
            if ($message === false)
                return null;

            //echo "Syncing playback status completed\n";

            $this->playbackStatus = PlaybackStatus::fromArray($message->getData());

            return $this->getPlaybackStatus();
        }

        public function getPlaybackStatus() : PlaybackStatus
        {
            return $this->playbackStatus;
        }

        public function write(Message $m) : bool
        {
            return $this->doIO(function ($stream) use ($m) {
                $stream->write($m);
                return true;
            }) !== false;
        }

        private function sendCommand(string $cmd) : bool
        {
            $m = new Message(MessageType::PLAYBACK_COMMAND, $cmd);
            return $this->write($m);
        }

        private function doIO(callable $callback)
        {
            try {
                //echo "Executing IO callback\n";
                return $callback($this->stream);
            } catch(IOException $e)
            {
                //TODO: log $e ?
                $this->resetConnection();
                return false;
            }
        }
    }
