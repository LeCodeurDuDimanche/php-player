<?php
    namespace lecodeurdudimanche\PHPPlayer;

    class Song {
        const AVAILABLE_TYPES = ['local', 'youtube', 'url'];

        private $uri, $type, $name, $path;
        private $ready, $loading, $loadCommand;

        public function __construct(string $type, string $uri)
        {
            if (! in_array($type, self::AVAILABLE_TYPES))
                throw new \Exception("Le type $type est invalide");

            $this->type = $type;
            $this->uri = $uri;
            $this->ready = false;
            $this->loading = false;
            $this->name = $this->guessName();
            $this->loadCommand = null;
            $this->path = null;
        }

        public function isReady() : bool
        {
            $this->updateStatus();
            return $this->ready;
        }

        public function isLoading() : bool
        {
            $this->updateStatus();
            return $this->loading;
        }

        public function getUri() : string
        {
            return $this->uri;
        }

        public function getType() : string
        {
            return $this->type;
        }

        public function getName() : string
        {
            return $this->name;
        }

        public function getPath() : ?string
        {
            return $this->path;
        }

        public function guessName() : string
        {
            switch($this->type)
            {
                case 'local':
                case 'url':
                    return pathinfo($this->uri, PATHINFO_FILENAME);
                case 'youtube':
                    return 'undefined';
            }
        }

        public function load(string $dir) : bool
        {
            if ($this->isReady()) return true;
            if ($this->isLoading()) return false;

            $escapedURI = str_replace("'", "", $this->uri);
            // Hash for sipmlicity sake : no need to sanitize file name
            //$outputFileWithoutExt = "$dir/" . sanitize($this->getName());
            $outputFileWithoutExt = "$dir/". md5($this->uri);
            $this->path = "$outputFileWithoutExt.wav";

            if (file_exists($this->path))
            {
                $this->ready = true;
                return true;
            }

            $this->loading = true;
            switch ($this->type) {
                case 'local':
                    echo "loading local file $escapedURI to " . $this->path . "\n";
                    $this->loadCommand = new Command("ffmpeg -i '$escapedURI' \"" . $this->path . "\" -v error");
                    break;
                case 'url':
                    echo "loading remote file $escapedURI\n";
                    $this->loadCommand = new Command("wget '$escapedURI' -q -O -|ffmpeg -i - \"" . $this->path . "\" -v error");
                    break;
                case 'youtube':
                    echo "loading yt video $escapedURI\n";
                    $titleRegex = "'\\\"fulltitle\\\": \\\"[^\\\"]*\\\"'";
                    $this->loadCommand = new Command("youtube-dl '$escapedURI' --no-playlist -x --audio-format wav -o \"$outputFileWithoutExt.%(ext)s\" && grep -oE $titleRegex \"$outputFileWithoutExt.%(ext)s.info.json\" && rm \"$outputFileWithoutExt.%(ext)s.info.json\"");
                    break;
            }

            $this->loadCommand->launch();

            return false;
        }

        private function updateStatus() : void
        {
            if (! $this->loading) return;

            if (! $this->loadCommand->isRunning())
            {
                if ($errors = $this->loadCommand->getNextErrorLine()) {
                    echo "Erreur chargement :\n $errors\n";
                    throw new \Exception("Impossible de charger la musique : $errors");
                }

                if ($this->type == "youtube")
                    $this->name = substr($this->loadCommand->getNextLine(), strlen("\"filename\": \""), -1);

                $this->loading = false;
                $this->ready = true;

                echo "Succesfully loaded $this->name !\n";
            }
        }
    }
