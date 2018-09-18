<?php
/**
 * Simple Bookmark Tool 1.0
 * Robert Gerlach 2018
 * https://github.com/combatwombat/sbt
 *
 * Install:
 * - Have PHP, MySQL and SSL
 * - Create database
 * - Edit your data in the config array or an external config.php
 * - Upload sbt.php
 * - Click on hamburger menu and add bookmarklet
 */

if (file_exists('config.php')) {
    include('config.php');
} else {
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
        ),
        'app' => array(
            'timezone' => 'Europe/Berlin'
        )
    );
}

$scriptURL = "http".(!empty($_SERVER['HTTPS'])?"s":"")."://".$_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_NAME'];
$messages = array();

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
  `url` text,
  `title` text,
  `description` text,
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
if (isset($_GET['api'])) {

    $success = false;

    // Add URL
    if (isset($_GET['add'])) {
        $res = addBookmarkFromGET();
        if ($res == 'success') {
            echo "alert('Added to Simple Bookmark Tool');";
        } else if ($res == 'duplicate') {
            echo "alert('Site is already bookmarked in Simple Bookmark Tool.');";
        } else if ($res == 'error') {
            echo "alert('Error adding to Simple Bookmark Tool');";
        }

    // Delete URL
    } else if (isset($_GET['del'])) {
        if (isset($_POST['id'])) {

            $stmt = $db->prepare("DELETE FROM bookmarks WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $deleted = $stmt->rowCount();

            echo $deleted == 1 ? "1" : "";
        }
    }

    die();

/*
 * Website
 */
} else {

    // added with fallback method for pages with Content Security Policy?
    if (isset($_GET['add'])) {
        addBookmarkFromGET();
        if (isset($_GET['goback'])) {
            header("Location: " . $_GET['url']);
            die();
        }
    }

    // import something?
    if (isset($_POST['import']) && $_POST['import'] == "1") {
        $success = true;

        if (isset($_FILES['netscape_html']) && !empty($_FILES['netscape_html'])) {
            $html = file_get_contents($_FILES['netscape_html']['tmp_name']);

            $dom = new DOMDocument;
            $dom->loadHTML($html, LIBXML_PARSEHUGE);
            $links = $dom->getElementsByTagName('a');
            $linksCount = 0;
            foreach ($links as $link) {
                $url = $link->getAttribute('href');
                $title = $link->nodeValue;
                $description = $link->getAttribute('tags');
                $createdAt = gmdate("Y-m-d H:i:s", $link->getAttribute('add_date'));

                $res = addBookmark($url, $title, $description, $createdAt);
                if ($res == 'error') {
                    $messages[] = array('type' => 'error', 'text' => 'Error importing ' . $url);
                    $success = false;
                } else if ($res == 'duplicate') {
                    $messages[] = array('type' => 'error', 'text' => "Didn't import duplicate " . $url);
                    $success = false;
                }
                $linksCount++;
            }
        }

        if ($success) {
            $messages[] = array('type' => 'success', 'text' => 'Imported ' . $linksCount . ' bookmarks.');
        } else {
            $messages[] = array('type' => 'error', 'text' => 'Error importing all bookmarks.');
        }

    }

    // are our tables missing? create them
    $res = $db->query("SHOW TABLES");
    $tables = $res->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array("bookmarks", $tables)) {
        echo "table <b>bookmarks</b> missing. creating...<br>";
        $res = $db->exec($dbSchema);
        if ($res !== false) {
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

function addBookmarkFromGET() {
    if (isset($_GET['url']) && strlen($_GET['url']) > 0 && filter_var($_GET['url'], FILTER_VALIDATE_URL))
    {
        $url = $_GET['url'];
        $title = isset($_GET['title']) && strlen($_GET['title']) > 0 ? $_GET['title'] : '';
        $description = isset($_GET['description']) && strlen($_GET['description']) > 0 ? $_GET['description'] : '';
        return addBookmark($url, $title, $description);
    }
    return false;
}
/**
 * add a bookmark
 * @param $url the URL
 * @param $title the title
 * @param description the description
 * @param $createdAt created-at datetime
 * @return string "success"|"duplicate"|"error"
 */
function addBookmark($url, $title, $description, $createdAt = null) {
    global $db;
    if (strlen($url) > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
        if ($createdAt == null) {
            $createdAt = gmdate("Y-m-d H:i:s");
        }

        // bookmark with url already exists?
        $stmt = $db->prepare('SELECT COUNT(*) as c FROM bookmarks WHERE url = ?');
        $res = $stmt->execute([$url]);
        if ($res) {
            $row = $stmt->fetch();
            if (isset($row['c']) && $row['c'] > 0) {
                return 'duplicate';
            }
        }

        $stmt = $db->prepare("INSERT INTO bookmarks SET url = ?, title = ?, description = ?, created_at = ?");
        $stmt->execute([$url, $title, $description, $createdAt]);
        $inserted = $stmt->rowCount();

        return ($inserted == 1) ? 'success' : 'erorr';
    }
    return 'error';
}

function routeIndex() {
    global $scriptURL, $db, $messages;

    $res = $db->query('SELECT * FROM bookmarks ORDER BY created_at DESC');
    $items = array();
    if ($res) {
        foreach ($res as $item) {
            $items[] = $item;
        }
    }
    $itemCount = count($items);

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Simple Bookmark Tool</title>
        <link rel="shortcut icon" type="image/png" href="data:image/png;base64,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">

        <style media="screen">
            body {
                font-family: sans-serif;
                font-size: 0.8em;
                color: #000;
                background: #fff;
            }
            .site {
                margin: 0 auto;
                padding: 0 10px;
                width: 100%;
                max-width: 800px;
                box-sizing: border-box;
            }
            h1 a {
                color: #000;
            }
            .items {
                list-style-type: none;
                padding-left: 0;
            }
            .items li {
                margin-bottom: 2em;
            }
            a {
                text-decoration: none;
                color: #004df9;
            }
            .items h2,
            .items .description,
            .items .url {
                margin: 0 0 5px 0;
                word-break: break-word;
            }
            .items .meta time {
                color: #bbb;
            }
            .items .url {
                display: block;
                color: #228822;
            }
            .items .meta .delete {
                color: #ca0000;
            }
            .bookmarklet {
                display: inline-block;
                color: #000;
                background: #ccc;
                padding: 5px 10px;
                border-radius: 3px;
            }
            .messages {
                padding-left: 0;
                list-style-type: none;
            }
            .messages .message {
                padding-bottom: 5px;
            }
            .messages .message.success {
                color: #00ca00;
            }
            .messages .message.error {
                color: #ca0000;
            }
            #extra {
                padding-bottom: 1em;
                margin-bottom: 2em;
                border-bottom: 1px solid #ccc;
            }

            #extra input {
                width: 200px;
            }

            @media (max-width: 340px) {
                h1 {
                    font-size: 1.9em;
                }
            }
        </style>

        <script type="text/javascript">
            function ready(fn) {
                if (document.attachEvent ? document.readyState === "complete" : document.readyState !== "loading"){
                    fn();
                } else {
                    document.addEventListener('DOMContentLoaded', fn);
                }
            }
            ready(function() {

                // delete items
                var elements = document.querySelectorAll('a.delete');
                Array.prototype.forEach.call(elements, function(el, i){

                    el.addEventListener('click', function(ev) {

                        var id = el.getAttribute('data-id');

                        if (id) {

                            if (confirm("Really?")) {

                                var request = new XMLHttpRequest();
                                request.open('POST', '<?php echo $scriptURL;?>?api&del', true);
                                request.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

                                request.onload = function() {
                                    if (request.status >= 200 && request.status < 400) {
                                        var res = request.responseText;
                                        if (res == "1") {

                                            // remove from html
                                            var item = document.querySelectorAll('.items li[data-id="'+id+'"]')[0];
                                            item.parentNode.removeChild(item);

                                            // all empty? show message
                                            var items = document.querySelectorAll('.items li');
                                            if (items.length == 0) {
                                                document.getElementById('empty').style.display = 'block';
                                            }

                                        }
                                    } else {
                                        alert("Error deleting.");
                                    }
                                };
                                request.onerror = function() {
                                    alert("Connection error.");
                                };
                                request.send("id="+id);
                            }
                        }
                        ev.preventDefault();
                    });
                });

                // show extra content
                var menuButton = document.getElementById('menu');
                var extraContent = document.getElementById('extra');
                if (menuButton) {
                    menuButton.addEventListener('click', function(ev) {
                        if (extraContent.style.display == 'none') {
                            extraContent.style.display = 'block';
                        } else {
                            extraContent.style.display = 'none';
                        }
                        ev.preventDefault();
                    });
                }
            });
        </script>
    </head>
    <body>
        <div class="site">
            <h1><a href="<?php echo $scriptURL;?>">Simple Bookmark Tool</a> <a href="#" id="menu">🍔</a></h1>
            <?php if (empty($_SERVER['HTTPS'])) { ?>
                <p style="color: #ca0000;">
                    This should run on http<b style="color: #f00;">s</b> to work.
                </p>
            <?php } ?>

            <?php if (!empty($messages)) { ?>
            <ul class="messages">
                <?php foreach ($messages as $message) { ?>
                <li class="message <?php echo $message['type'];?>">
                    <?php echo $message['text']; ?>
                </li>
                <?php }?>
            </ul>
            <?php } ?>

            <div id="extra" style="display: none;">
                <p>
                    Bookmarklet: <a class="bookmarklet" href="<?php echo bookmarklet();?>">bookmark!</a>
                </p>
                <p> 
                    Bookmarklet URL: <input type="text" value="<?php echo bookmarklet();?>">
                </p>
                <p>
                    Bookmarks: <?php echo $itemCount;?>
                </p>
                <p>
                    Netscape Bookmark HTML Import:
                    <form action="" method="post" enctype="multipart/form-data">
                        <input type="file" name="netscape_html">
                        <input type="submit" value="Import">
                        <input type="hidden" name="import" value="1">
                    </form>
                </p>
            </div>

            <?php if ($itemCount > 0) { ?>
                <ul class="items">
                    <?php foreach ($items as $item) { ?>
                        <li data-id="<?php echo $item['id'];?>">
                            <h2>
                                <a class="title" href="<?php echo $item['url'];?>">
                                    <?php echo strlen($item['title']) > 0 ? htmlspecialchars($item['title']) : 'no title';?>
                                </a>
                            </h2>
                            <a class="url" href="<?php echo $item['url'];?>">
                                <?php echo htmlspecialchars($item['url']);?>
                            </a>
                            <?php if (strlen($item['description']) > 0) { ?>
                                <p class="description">
                                    <?php echo htmlspecialchars(textShorten($item['description'], 1000)); ?>
                                </p>
                            <?php } ?>
                            <div class="meta">
                                <time>
                                    <?php echo htmlspecialchars(localDateTime($item['created_at'], 'd. M Y H:i:s')); ?>
                                </time>
                                &middot;
                                <a class="delete" data-id="<?php echo $item['id'];?>" href="#">delete</a>
                            </div>
                        </li>
                    <?php } ?>
                </ul>
            <?php } ?>

            <em id="empty" <?php echo $itemCount > 0 ? 'style="display: none;"' : '';?>>empty</em>
        </div>
    </body>
    </html>
<?php }

function bookmarklet() {
    global $scriptURL;
    $js = <<<EOD
(function() {
    var desc = '';
    var selectedText = '';
    if (window.getSelection) {
        selectedText = window.getSelection().toString();
    } else if (document.selection && document.selection.type != "Control") {
        selectedText = document.selection.createRange().text;
    };
    if (selectedText != '') { desc = selectedText; } else {
        var descMeta = document.querySelectorAll('meta[name="description"]');
        if (descMeta.length) { desc = descMeta[0].getAttribute('content') };
    };
    var apiURL='{$scriptURL}?api&add&url=' + encodeURIComponent(document.URL) + '&title=' + encodeURIComponent(document.title) + '&description=' + encodeURIComponent(desc);
    var webURL='{$scriptURL}?add&goback&url=' + encodeURIComponent(document.URL) + '&title=' + encodeURIComponent(document.title) + '&description=' + encodeURIComponent(desc);
    var el=document.createElement('script');
    el.src=apiURL;
    el.onerror=function() {
        if (confirm("Add to Simple Bookmark Tool?")) {
            window.location.href = webURL;
        }
    };    
    document.body.appendChild(el); 
})();
EOD;
    return "javascript:" . rawurlencode(str_replace("  ", " ", str_replace("\n", " ", $js)));
}

/// Helpers

function textShorten($str, $textlength = 500) {
    if (strlen($str) > $textlength) {
        $str = substr($str, 0, $textlength); // might cut off the last word
        $strArr = explode(" ", $str);
        if (count($strArr) > 1) {
            array_pop($strArr); // remove last element, the cut off word
            $str = implode(" ", $strArr);
            $str .= " [...]";

        } else {
            $str .= "...";
        }
        $str = trim($str);
    }
    return $str;
}

function localDateTime($utcDateTime, $format='Y-m-d H:i:s') {
    global $config;
    $local = new DateTimeZone($config['app']['timezone']);
    $utc = new DateTimeZone("UTC");
    $date = new DateTime($utcDateTime, $utc);
    $date->setTimezone($local);
    return $date->format($format);
}