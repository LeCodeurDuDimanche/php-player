<?php
    namespace lecodeurdudimanche\PHPPlayer;

    class PlaybackStatus {

        private $playing;

        public function __construct()
        {
            $this->playing = false;
        }

        public function play() : void
        {
            $this->playing = true;
        }

        public function pause() : void
        {
            $this->playing = false;
        }

        public function isPlaying() : bool
        {
            return $this->playing;
        }

    }
