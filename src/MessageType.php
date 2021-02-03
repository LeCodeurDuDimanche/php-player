<?php

namespace lecodeurdudimanche\PHPPlayer;

class MessageType {
    public const PLAYBACK_COMMAND = 1, QUEUE_MUSIC = 2, REMOVE_MUSIC = 3, KILL = 4, QUERY = 5, CONF_SET = 6, CONF_GET = 7,
                 PLAYBACK_DATA = 10, FEEDBACK_DATA = 11, CONF_DATA = 12;
}
