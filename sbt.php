<?php
/**
 * Simple Bookmark Tool
 * Robert Gerlach 2018
 */

$config = array(
    'auth' => array(
        'user'          => 'sbt',
        'password'      => 'sbt'
    ),
    'db' => array(
        'name'          => 'sbt',
        'user'          => 'root',
        'password'      => 'root',
        'host'          => '127.0.0.1',
    )
);

// Basic HTTP auth
if (!isset($_SERVER['PHP_AUTH_USER'])) {
    header('WWW-Authenticate: Basic realm="Simple Bookmark Tool"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Not authorized.';
    die();
} else {
    if ($_SERVER['PHP_AUTH_USER'] !== $config['auth']['user'] || $_SERVER['PHP_AUTH_PW'] !== $config['auth']['password']) {
        header('HTTP/1.0 401 Unauthorized');
        echo 'Wrong user or password.';
        die();
    }
}


$dbSchema = <<<EOD
CREATE TABLE `bookmarks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` mediumtext,
  `title` mediumtext,
  `description` mediumtext,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
EOD;

$dsn = "mysql:host=".$config['db']['host'].";dbname=".$config['db']['name'].";charset=utf8mb4";
$opt = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$db = new PDO($dsn, $config['db']['user'], $config['db']['password'], $opt);


/**
 * API
 */
if ($_GET['api']) {




/*
 * Website
 */
} else {

    // are our tables missing? create them
    $res = $db->query("SHOW TABLES");
    $tables = $res->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array("bookmarks", $tables)) {
        echo "table <b>bookmarks</b> missing. creating...<br>";
        $res = $db->exec($dbSchema);
        if ($res) {
            header("Refresh: 0");
            die();
        } else {
            echo "error creating tables";
            die();
        }

    } else {
        routeIndex();
    }

}

function routeIndex() {
    global $db;
    $res = $db->query('SELECT * FROM bookmarks');
    $items = array();
    if ($res) {
        foreach ($res as $item) {
            $items[] = $item;
        }
    }

    print_r($items);
}

