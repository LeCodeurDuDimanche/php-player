<?php
    namespace lecodeurdudimanche\PHPPlayer;

    use lecodeurdudimanche\Processes\Command;

    class Song implements \JsonSerializable {
        const AVAILABLE_TYPES = ['local', 'youtube', 'url'];

        private $uri, $type, $name, $path;
        private $ready, $loading, $loadCommand;
        private $length;
        private $loadingStart;

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
            $this->length = 0;
            $this->loadingStart = -1;
        }

        public static function fromArray(array $data) : Song
        {
            $s = new Song($data["type"], $data["uri"]);
            foreach($data as $attr => $val)
                $s->$attr = $val;
            return $s;
        }

        public function jsonSerialize() : array
        {
            $data = [];
            foreach(["type", "uri", "ready", "loading", "name", "length"] as $attr)
                $data[$attr] = $this->$attr;
            return $data;
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

        public function __toString() : string
        {
            $status = $this->ready ? "ready" : ($this->loading ? "loading" : "not ready");
            return "Song '$this->name' of type $this->type (uri : $this->uri). Status : $status. Length : $this->length s";
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

        public function getLoadingStart() : float
        {
            return $this->loadingStart;
        }

        public function load(string $dir, string $format="wav") : bool
        {
            if ($this->isReady()) return true;
            if ($this->isLoading()) return false;

            $escapedURI = str_replace("'", "", $this->uri);
            // Hash for sipmlicity sake : no need to sanitize file name
            //$outputFileWithoutExt = "$dir/" . sanitize($this->getName());
            $outputFileWithoutExt = "$dir/". md5($this->uri);
            $this->path = "$outputFileWithoutExt.$format";

            if (file_exists($this->path))
            {
                $this->extractMetadata();
                $this->ready = true;
                return true;
            }

            $this->loading = true;
            $this->loadingStart = microtime(true);
            //TODO: Should I let them in their origin format ?
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
                    $this->loadCommand = new Command("youtube-dl '$escapedURI' --no-cache-dir --write-info-json --no-playlist -x --restrict-filenames --audio-format $format -o \"$outputFileWithoutExt.%(ext)s\"");
                    break;
            }

            $this->loadCommand->launch();

            return false;
        }

        private function extractMetadata() : void
        {
            if ($this->type == "youtube")
            {
                $info = pathinfo($this->path);
                $pathWithoutExt = "$info[dirname]/$info[filename]";
                if (file_exists("$pathWithoutExt.info.json")) {
                    $data = json_decode(file_get_contents("$pathWithoutExt.info.json"), true);
                    $this->name = $data["fulltitle"];
                    $this->length = intval($data["duration"]);
                }
            }
            if (! $this->length)
                $this->length = intval((new Command("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 $this->path"))->execute()["out"]);
        }

        private function updateStatus() : void
        {
            if (! $this->loading) return;

            if (! $this->loadCommand->isRunning())
            {
                if ($errors = $this->loadCommand->getNextErrorLine()) {
                    echo "Erreur chargement :\n $errors\n";
                    throw new LoadingException("Impossible de charger la musique : $errors");
                }

                $this->extractMetadata();

                $this->loading = false;
                $this->ready = true;

                echo "Succesfully loaded $this->name !\n";
            }
        }
    }
