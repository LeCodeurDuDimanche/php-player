<?php
namespace lecodeurdudimanche\PHPPlayer;

use lecodeurdudimanche\UnixStream\IOException;
use lecodeurdudimanche\UnixStream\{Message, UnixStream, UnixStreamServer};
use lecodeurdudimanche\Processes\Command;

class MusicDaemon {
    const LAST_POS = -1;

    private $listeningSocket, $streams;
    private $status;
    private $player;
    private $shouldPlay;
    private $cacheDir;
    private $config;

    public function __construct(?string $cacheDir = null)
    {
        $this->checkExistingDaemon();

        $this->listeningSocket = new UnixStreamServer(self::getSocketFile());
        $this->streams = [];
        $this->status = new PlaybackStatus;
        $this->player = null;
        $this->shouldPlay = true;
        $this->cacheDir = $cacheDir ?? getenv("HOME") . "/player-music";
        $this->config = [];

        $this->createLibraryDirectory();
        $this->loadDefaultConfig();
    }

    private function checkExistingDaemon() : void
    {
        if (($pid = Manager::fetchDaemonPID()) != getmypid() && $pid)
            throw new \Exception("Daemon is already running ($pid)");
        if (file_exists(self::getSocketFile()))
        {
            try {
                $stream = new UnixStream(self::getSocketFile());
                $stream->write(new Message(MessageType::KILL, ""));
                $stream->close();
                usleep(50 * 1000);
            } catch(IOException $e)
            {}
        }
    }

    private function createLibraryDirectory() : void
    {
        if (! file_exists($this->getLibraryDirectory()))
            mkdir($this->getLibraryDirectory(), 0777, true);
    }

    private function loadDefaultConfig() : void
    {
        $config = [
                'format' => 'wav',
        ];
    }


    public function getLibraryDirectory() : string
    {
        return $this->cacheDir;
    }

    public static function getSocketFile() : string
    {
        return "/tmp/php-player/socket";
    }

    public function run() : void
    {


        $continue = true;

        //Do stream connection and reconnection
        while ($continue)
        {
            $this->addNewConnections();

            $this->managePlayback();

            //TODO: sanity checks on inputs
            while ($messagePair = $this->getNextCommand())
            {
                $message = $messagePair['message'];
                echo "new command : " . $message->getType() . "\n";
                $data = $message->getData();
                switch($message->getType())
                {
                case MessageType::KILL:
                    $continue = false;
                    echo "Kill recevied\n";
                    continue 2;
                case MessageType::QUEUE_MUSIC:
                    $this->addSong(new Song($data["type"], $data["uri"]), $data['position']);
                    break;
                case MessageType::PLAYBACK_COMMAND:
                    $this->playbackControl($data);
                    break;
                case MessageType::QUERY:
                    $this->checkSongStatus();
                    $messagePair['stream']->write(new Message(MessageType::PLAYBACK_DATA, $this->status));
                    break;
                case MessageType::CONF_SET:
                    $this->set($data["key"], $data["value"]);
                    break;

                }
            }

            usleep(50000);
        }

        $this->stop();

        foreach ($this->streams as $stream)
            $stream->close();
        $this->listeningSocket->close();

        unlink(self::getSocketFile());
    }

    private function set(string $key, string $value) : void
    {
        //TODO: sanity check on inputs
        $config[$key] = $value;
    }

    // Inter process communciation
    private function addNewConnections() : void
    {
        while ($stream = $this->listeningSocket->accept(false))
        {
            //echo "New connection !\n";
            $this->streams[] = $stream;
        }
    }

    private function getNextCommand() : ?array
    {
        foreach($this->streams as $key => $stream)
        {
            try {
                $message = $stream->readNext([MessageType::PLAYBACK_COMMAND, MessageType::QUEUE_MUSIC, MessageType::KILL, MessageType::QUERY], false);
                if ($message)
                    return compact("message", "stream");

            } catch(IOException $e)
            {
                //echo "Dropped connection\n";
                $stream->close();
                unset($this->streams[$key]);
            }
        }
        return null;
    }


    private function addSong(Song $song, int $pos) : void
    {
        // Load song, if needed
        $song->load($this->getLibraryDirectory(), $this->config["format"]);

        $this->status->addSong($song, $pos);
    }

    private function managePlayback() : void
    {
        // Si on ne veut pas jouer, alosrs on ne relance rien
        if (!$this->shouldPlay)
         return;

        if (! $this->status->isPlaying())
        {
            // On a ajoute une musique apres avoir tout lu
            if($this->status->getIndex() + 1 < $this->status->getQueueLength())
                $this->doChange(+1);
        }
        else if (! $this->player->isRunning()) // On est arrive a la fin de la musique
        {
            echo "Player out : " . $this->player->getNextLine() . "\n";
            echo "Player err  : " . $this->player->getNextErrorLine() . "\n\n";
            if ($err = $this->player->getNextErrorLine())
                $this->sendError("player", $err);
            $this->doChange(+1);
        }
    }

    private function sendError(string $type, string $message)
    {
        $data = new Message(MessageType::FEEDBACK_DATA, ['type' => 'error', "message" => "[$type] $message"]);
        echo "Error : [$type] $message\n";
        //TODO: Should we really broadcast that ? I think not but anyways...
        foreach ($this->streams as $stream)
            $stream->write($data);
    }


    private function checkSongStatus() : void
    {
        for ($i = 0; $i < $this->status->getQueueLength(); $i++)
        {
            $song = $this->status->getSong($i);
            try {
                $song->isReady();
            } catch (LoadingException $e) {
                $this->status->removeSong($i);
            }
        }
    }

    private function doChange(int $offset) : void
    {
        $this->stop();

        $next = $this->status->getIndex() + $offset;
        $this->doPlay($next);
        $this->shouldPlay = true;
    }

    private function doPlay(int $index) : void
    {
        $song = $this->status->setCurrentSong($index);
        if ($song == null)
            return;

        echo "Will play $index {$song->getName()}\n";
        //TODO : find better solution
        try {
            while (! $song->isReady()) usleep(50 * 1000);
        } catch (LoadingException $e)
        {
            $this->sendError("loading", $e->getMessage());
            return;
        }

        $this->player = new Command("ffplay -vn -nodisp -autoexit -loglevel fatal '{$song->getPath()}'");
        $this->player->launch();

        $this->status->play();
    }

    private function doResume() : void
    {
        if (! $this->status->isPlaying() && $this->player) {
            $this->status->play();
            $this->player->resume();
            $this->shouldPlay = true;
        }
    }

    private function doPause() : void
    {
        if ($this->status->isPlaying() && $this->player) {
            $this->status->pause();
            $this->player->pause();
            $this->shouldPlay = false;
        }
    }

    private function stop() : void
    {
        if ($this->player != null)
        {
            $this->player->terminate();
            $this->player = null;
            $this->status->pause();
            $this->shouldPlay = false;
        }
    }

    private function playbackControl(String $command) : void
    {
        switch($command)
        {
            case 'play':
                $this->doResume();
                break;
            case 'pause':
                $this->doPause();
                break;
            case 'next':
                $this->doChange(+1);
                break;
            case 'prev':
                $this->doChange(-1);
                break;
        }
    }
}
