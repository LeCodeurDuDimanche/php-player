<?php
    namespace lecodeurdudimanche\PHPPlayer;

    class PlaybackStatus implements \JsonSerializable {

        private $playing;
        private $queue;
        private $index;
        private $lastStart;
        private $lastPause;

        public function __construct(?array $data = null)
        {
            $this->playing = $data ? $data['playing'] : false;
            $this->queue = [];
            if ($data)
            {
                foreach($data["queue"] as $song)
                    $this->addSong(Song::fromArray($song), -1);
            }
            $this->index = $data ? $data['index'] :  -1;
            $this->lastStart = $data ? $data['lastStart'] :  -1;
            $this->lastPause = $data ? $data['lastPause'] : -1;
        }

        public static function fromArray(array $data) : PlaybackStatus
        {
            return new PlaybackStatus($data);
        }

        public function jsonSerialize() : array
        {
            return get_object_vars($this);
        }

        public function addSong(Song $song, int $pos) : void
        {
            if ($pos >= 0 && $pos < count($this->queue))
                array_splice($this->queue, $pos, 0, [$song]);
            else {
                array_push($this->queue, $song);
            }
        }

        public function play() : void
        {
            if (!$this->playing)
            {
                if ($this->lastStart == -1) // Lancement de cette musique
                    $this->lastStart = time();
                else                        // On relance une musique deja lancee
                    $this->lastStart += (time() - $this->lastPause);
            }
            $this->playing = true;
        }

        public function pause() : void
        {
            if ($this->playing)
                $this->lastPause = time();
            $this->playing = false;
        }

        public function getPlayingTime() : int
        {
            if ($this->lastStart == -1)
                return -1;
            else if (! $this->playing)
                return $this->lastPause - $this->lastStart;
            else
                return time() - $this->lastStart;
        }

        public function isPlaying() : bool
        {
            return $this->playing;
        }

        public function getQueueLength() : int
        {
            return count($this->queue);
        }

        public function setCurrentSong(int $index) : ?Song
        {
            if ($index >= 0 && $index < $this->getQueueLength())
            {
                if ($this->index != $index)
                    $this->lastPause = $this->lastStart = -1;
                $this->index = $index;
                return $this->getSong($this->index);
            }
            return null;
        }

        public function getCurrentSong() : ?Song
        {
            return $this->getSong($this->getIndex());
        }

        public function getSong(int $index) : ?Song
        {
            if ($index < 0 || $index >= $this->getQueueLength())
                return null;
            return $this->queue[$index];
        }

        public function removeSong(int $index) : bool
        {
            if ($index < 0 || $index >= $this->getQueueLength())
                return false;

            //TODO : on pourrait traiter ça mieux
            if ($this->index == $index)
                return false;

            array_splice($this->queue, $index, 1);
            if ($index < $this->index)
                $this->index--;

            return true;
        }

        public function getIndex() : int
        {
            return $this->index;
        }

        public function __toString() : string
        {
            $str = "Status : " . ($this->playing ? "en cours" : "en pause");
            $str .= ", temps de lecture : " . $this->getPlayingTime() . "s\n";
            $str .= "Chanson actuelle : " . $this->index . " / " . $this->getQueueLength() . "\n";
            $str .=  "Chansons dans la queue :\n";
            foreach($this->queue as $index => $song)
                $str .= "\t" . ($index + 1) . ". $song\n";
            return $str;
        }

    }
