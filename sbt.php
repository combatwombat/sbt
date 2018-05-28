<?php
/**
 * Simple Bookmark Tool 1.0
 * Robert Gerlach 2018
 *
 * Install:
 * - Enable SSL
 * - Create database
 * - Edit you data in the config array below
 * - Upload sbt.php
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
    ),
    'app' => array(
        'timezone' => 'Europe/Berlin'
    )
);

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

    // Add url
    if (isset($_GET['add'])) {

        if (addBookmarkFromGET()) {
            echo "alert('Added to Simple Bookmark Tool');";
        } else {
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
            //libxml_use_internal_errors(true);
            $dom->loadHTML($html, LIBXML_PARSEHUGE);
            $links = $dom->getElementsByTagName('a');
            $linksCount = 0;
            $successLinksCount = 0;
            foreach ($links as $link) {
                $url = $link->getAttribute('href');
                $title = $link->nodeValue;
                $description = $link->getAttribute('tags');
                $createdAt = gmdate("Y-m-d H:i:s", $link->getAttribute('add_date'));


                if (addBookmark($url, $title, $description, $createdAt)) {
                    $successLinksCount++;
                } else {
                    $messages[] = array('type' => 'error', 'text' => 'Error importing ' . $url);
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

function addBookmark($url, $title, $description, $createdAt = null) {
    global $db;
    if (strlen($url) > 0 && filter_var($url, FILTER_VALIDATE_URL)) {
        if ($createdAt == null) {
            $createdAt = gmdate("Y-m-d H:i:s");
        }

        $stmt = $db->prepare("INSERT INTO bookmarks SET url = ?, title = ?, description = ?, created_at = ?");
        $stmt->execute([$url, $title, $description, $createdAt]);
        $inserted = $stmt->rowCount();

        return $inserted == 1;
    }
    return false;
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

    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <title>Simple Bookmark Tool</title>

        <style media="screen">
            body {
                font-family: sans-serif;
                font-size: 0.8em;
                color: #000;
                background: #fff;
            }
            .site {
                margin: 0 auto;
                padding: 0 20px;
                width: 100%;
                max-width: 800px;
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

            }
            .items .meta time {
                color: #bbb;
            }
            .items .url {
                display: block;
                color: #228822;
                word-break: break-word;
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
            <h1><a href="">Simple Bookmark Tool</a> <a href="#" id="menu">🍔</a></h1>
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
                    Bookmarks: <?php echo count($items);?>
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

            <?php if (count($items) > 0) { ?>
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

            <em id="empty" <?php echo count($items) > 0 ? 'style="display: none;"' : '';?>>empty</em>
        </div>
    </body>
    </html>
<?php }

function bookmarklet() {
    global $scriptURL;
    $js = <<<EOD
(function() {
    var descMeta = document.querySelectorAll('meta[name="description"]');
    var desc = '';
    if (descMeta.length) { desc = descMeta[0].getAttribute('content') };
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


/// Helper

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