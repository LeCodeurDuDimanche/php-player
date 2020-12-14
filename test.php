<?php
    use lecodeurdudimanche\PHPPlayer\Manager;

    require("vendor/autoload.php");

    //Create new Bluetooth manager, set discoverable and pairable to true
    echo "Lauching manager...\n";
    $manager = new Manager();

    echo "Adding songs\n";

    $songs = [
        ['local', '/home/adrien/Musique/ZZ Top - La Grange.mp3'],
        ['url', 'https://file-examples-com.github.io/uploads/2017/11/file_example_MP3_700KB.mp3'],
        ['youtube', 'https://www.youtube.com/watch?v=7wtfhZwyrcc&list=PLQdZueZI45HVWutU0hah5o9pW-iDOqx4g&index=54'],
    ];

    foreach($songs as $s) {
        $manager->queueMusic($s[0], $s[1]);
        sleep(1);
    }

    sleep(5);

    echo "Skipping...\n";
    $manager->next();

    sleep(30);

    echo "Skipping (should just stop)\n";
    $manager->next();
    sleep(1);
    $manager->next();
    $manager->next();
    sleep(2);

    echo "Playing prev song (should play Imagine dragons)\n";
    $manager->prev();
    sleep(10);

    echo "Playing first song\n";
    $manager->prev();
    $manager->prev();
    $manager->prev();
    sleep(4);

    echo "Pause\n";
    $manager->pause();
    sleep(4);

    echo "Play\n";
    $manager->play();
    sleep(4);

    echo "Skipping (should just stop)\n";
    $manager->next();
    $manager->next();
    $manager->next();
    sleep(5);

    echo "Adding song\n";
    $manager->queueMusic("local", "/home/adrien/Vidéos/pub.mp4");
