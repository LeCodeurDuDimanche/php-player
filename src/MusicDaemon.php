<?php
namespace lecodeurdudimanche\PHPPlayer;

use lecodeurdudimanche\UnixStream\IOException;
use lecodeurdudimanche\UnixStream\{Message, UnixStream, UnixStreamServer};

class MusicDaemon {
    const LAST_POS = -1;

    private $listeningSocket, $streams;
    private $songCollection;
    private $index;
    private $status;
    private $player;

    public function __construct()
    {
        $this->listeningSocket = new UnixStreamServer(self::getSocketFile());
        $this->streams = [];
        $this->index = -1;
        $this->songCollection = [];
        $this->status = new PlaybackStatus;
        $this->player = null;

        $this->createLibraryDirectory();
    }

    private function createLibraryDirectory()
    {
        if (! file_exists(self::getLibraryDirectory()))
            mkdir(self::getLibraryDirectory(), 0777, true);
    }

    public static function getLibraryDirectory() : string
    {
        return getenv("HOME") . "/player-music";
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

            while ($message = $this->getNextCommand())
            {
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
                case MessageType::SET_VOLUME:
                    $this->setVolume(intval($data));
                    break;
                case MessageType::PLAYBACK_COMMAND:
                    $this->playbackControl($data);
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

    // Inter process communciation
    private function addNewConnections() : void
    {
        while ($stream = $this->listeningSocket->accept(false))
        {
            //echo "New connection !\n";
            $this->streams[] = $stream;
        }
    }

    private function getNextCommand() : ?Message
    {
        foreach($this->streams as $key => $stream)
        {
            try {
                $message = $stream->readNext([MessageType::PLAYBACK_COMMAND, MessageType::SET_VOLUME, MessageType::QUEUE_MUSIC, MessageType::KILL], false);
                if ($message)
                    return $message;

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
        $song->load(self::getLibraryDirectory());

        $pos = intval($pos);
        if ($pos >= 0 && $pos < count($this->songCollection))
            array_splice($this->songCollection, $pos, 0, [$song]);
        else {
            echo "Appending song type " . $song->getType() . "\n";
            array_push($this->songCollection, $song);
        }
    }

    private function managePlayback() : void
    {
        if (! $this->status->isPlaying())
        {
            // On a ajoute une musique apres avoir tout lu
            if($this->index + 1 < count($this->songCollection))
                $this->doChange(+1);
        }
        else if (! $this->player->isRunning()) // On est arrive a la fin de la musique
        {
            echo "Player out : " . $this->player->getNextLine() . "\n";
            echo "Player err  : " .$this->player->getNextErrorLine() . "\n\n";
            $this->doChange(+1);
        }
    }

    private function doChange(int $offset) : void
    {
        $this->stop();

        $next = $this->index + $offset;
        $this->doPlay($next);
    }

    private function doPlay(int $index) : void
    {
        if ($index >= 0 && $index < count($this->songCollection))
        {
            $this->index = $index;
            $song = $this->songCollection[$index];

            echo "Will play $index {$song->getName()}\n";
            //TEMP : find proper solution
            while (! $song->isReady()) usleep(50000);

            echo "Song fully loaded, starting player (aplay '{$song->getPath()}')\n";
            $this->player = new Command("aplay '{$song->getPath()}'");
            $this->player->launch();

            $this->status->play();
        }
    }

    private function doResume() : void
    {
        if (! $this->status->isPlaying() && $this->player) {
            $this->status->play();
            $this->player->resume();
        }
    }

    private function doPause() : void
    {
        if ($this->status->isPlaying() && $this->player) {
            $this->status->pause();
            $this->player->pause();
        }
    }

    private function stop() : void
    {
        if ($this->player != null)
        {
            echo "Stopping...\n";
            $this->player->terminate(); //TODO: FIX ca attend et ne tue pas
            $this->player = null;
            $this->status->pause();
            echo "stopped ! \n";
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
