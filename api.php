<?php
/**
 * Seriously — PHP API backend with PostgreSQL storage.
 * All API calls route through: /api.php?action=xxx
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (php_sapi_name() !== 'cli') {
    session_start();
    header('Content-Type: application/json');
    set_exception_handler(function($e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
        exit;
    });
    set_error_handler(function($errno, $msg, $file, $line) {
        throw new ErrorException($msg, 0, $errno, $file, $line);
    });
}

$DIR = __DIR__;
$ENV_FILE = "$DIR/.env";

// Fallback JSON paths (used if DB unavailable)
$CONFIG_FILE = "$DIR/site_config.json";
$FEEDS_FILE  = "$DIR/team_feeds.json";
$DATA_FILE   = "$DIR/feed_data.json";
$ETAG_FILE   = "$DIR/feed_etags.json";

// ── Load .env ──
function load_env() {
    global $ENV_FILE;
    $env = [];
    if (file_exists($ENV_FILE)) {
        foreach (file($ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line && $line[0] !== '#' && strpos($line, '=') !== false) {
                list($k, $v) = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
    }
    return $env;
}

$env = load_env();
$ADMIN_USER = $env['SP_ADMIN_USER'] ?? 'admin';
$ADMIN_PASS = $env['SP_ADMIN_PASS'] ?? 'seriously2026';

// ── Database ──
$_db = null;
function get_db() {
    global $_db, $env;
    if ($_db) return $_db;
    $host = $env['DB_HOST'] ?? null;
    $name = $env['DB_NAME'] ?? null;
    $user = $env['DB_USER'] ?? null;
    $pass = $env['DB_PASS'] ?? null;
    if (!$host || !$name || !$user) return null;
    try {
        $_db = new PDO("pgsql:host=$host;dbname=$name", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $_db;
    } catch (Exception $e) {
        return null;
    }
}

// ── Helpers ──
function json_out($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_admin() {
    return isset($_SESSION['sp_admin']) && $_SESSION['sp_admin'] === true;
}

function require_admin() {
    if (!is_admin()) {
        json_out(['error' => 'Unauthorized', 'login_required' => true], 401);
    }
}

// ── Config (DB with JSON fallback) ──
function load_config() {
    global $CONFIG_FILE;
    $db = get_db();
    if ($db) {
        $rows = $db->query("SELECT key, value FROM config")->fetchAll();
        $cfg = [];
        foreach ($rows as $r) $cfg[$r['key']] = $r['value'];
        if (!empty($cfg)) return $cfg;
    }
    if (file_exists($CONFIG_FILE)) {
        return json_decode(file_get_contents($CONFIG_FILE), true) ?: [];
    }
    return ['title'=>'Seriously','title_accent'=>'Photography','tagline'=>'Curated daily.',
            'year'=>'2026','ga_measurement_id'=>'','auto_refresh_minutes'=>'60','feed_url'=>'/feed'];
}

function save_config($cfg) {
    global $CONFIG_FILE;
    $db = get_db();
    if ($db) {
        $stmt = $db->prepare("INSERT INTO config (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value");
        foreach ($cfg as $k => $v) $stmt->execute([$k, (string)$v]);
    }
    file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Feeds (DB with JSON fallback) ──
function load_feeds() {
    global $FEEDS_FILE;
    $db = get_db();
    if ($db) {
        try {
            $rows = $db->query("SELECT url, title, html_url FROM feeds ORDER BY title")->fetchAll();
            if (!empty($rows)) {
                $feeds = [];
                foreach ($rows as $r) {
                    $feeds[$r['url']] = ['title' => $r['title'], 'htmlUrl' => $r['html_url'], 'feedId' => 'feed/'.$r['url']];
                }
                return $feeds;
            }
        } catch (Exception $e) {}
    }
    if (file_exists($FEEDS_FILE)) {
        return json_decode(file_get_contents($FEEDS_FILE), true) ?: [];
    }
    return [];
}

function save_feed($url, $title, $html_url) {
    global $FEEDS_FILE;
    $db = get_db();
    if ($db) {
        $stmt = $db->prepare("INSERT INTO feeds (url, title, html_url) VALUES (?, ?, ?) ON CONFLICT (url) DO UPDATE SET title=EXCLUDED.title, html_url=EXCLUDED.html_url");
        $stmt->execute([$url, $title, $html_url]);
    }
    // Also update JSON as fallback
    $feeds = [];
    if (file_exists($FEEDS_FILE)) $feeds = json_decode(file_get_contents($FEEDS_FILE), true) ?: [];
    $feeds[$url] = ['title' => $title, 'htmlUrl' => $html_url, 'feedId' => "feed/$url"];
    file_put_contents($FEEDS_FILE, json_encode($feeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function remove_feed_entry($url) {
    global $FEEDS_FILE;
    $db = get_db();
    $title = '';
    if ($db) {
        $stmt = $db->prepare("SELECT title FROM feeds WHERE url=?");
        $stmt->execute([$url]);
        $row = $stmt->fetch();
        if ($row) $title = $row['title'];
        $db->prepare("DELETE FROM feeds WHERE url=?")->execute([$url]);
        $db->prepare("DELETE FROM articles WHERE pub_url IN (SELECT html_url FROM feeds WHERE url=?) OR publication IN (SELECT title FROM feeds WHERE url=?)")->execute([$url, $url]);
    }
    if (file_exists($FEEDS_FILE)) {
        $feeds = json_decode(file_get_contents($FEEDS_FILE), true) ?: [];
        if (isset($feeds[$url])) {
            if (!$title) $title = $feeds[$url]['title'];
            unset($feeds[$url]);
            file_put_contents($FEEDS_FILE, json_encode($feeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
    return $title;
}

// ── ETag cache (DB) ──
function get_feed_etag($url) {
    $db = get_db();
    if (!$db) return null;
    $stmt = $db->prepare("SELECT etag, last_modified FROM feeds WHERE url=?");
    $stmt->execute([$url]);
    return $stmt->fetch() ?: null;
}

function save_feed_etag($url, $etag, $last_modified) {
    $db = get_db();
    if (!$db) return;
    $stmt = $db->prepare("UPDATE feeds SET etag=?, last_modified=?, last_checked=NOW() WHERE url=?");
    $stmt->execute([$etag, $last_modified, $url]);
}

// ── RSS parser ──
$LAST_RESPONSE_HEADERS = [];

function fetch_rss($url) {
    global $LAST_RESPONSE_HEADERS;
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'user_agent' => 'Seriously/1.0', 'follow_location' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $xml_str = @file_get_contents($url, false, $ctx);
    $LAST_RESPONSE_HEADERS = $http_response_header ?? [];
    if (!$xml_str) return false;
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($xml_str, 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) return false;
    return $xml;
}

function check_feed_changed($url) {
    $cached = get_feed_etag($url);
    if (!$cached || (!$cached['etag'] && !$cached['last_modified'])) return 'unknown';

    $headers = [];
    if ($cached['etag']) $headers[] = 'If-None-Match: ' . $cached['etag'];
    if ($cached['last_modified']) $headers[] = 'If-Modified-Since: ' . $cached['last_modified'];

    $ctx = stream_context_create([
        'http' => ['method'=>'GET','timeout'=>8,'user_agent'=>'Seriously/1.0','follow_location'=>true,'header'=>implode("\r\n",$headers)],
        'ssl' => ['verify_peer'=>false,'verify_peer_name'=>false],
    ]);
    @file_get_contents($url, false, $ctx);
    if (isset($http_response_header)) {
        if (strpos($http_response_header[0] ?? '', '304') !== false) return 'unchanged';
    }
    return 'changed';
}

function capture_etag($url) {
    global $LAST_RESPONSE_HEADERS;
    $etag = ''; $lm = '';
    foreach ($LAST_RESPONSE_HEADERS as $h) {
        if (stripos($h, 'etag:') === 0) $etag = trim(substr($h, 5));
        elseif (stripos($h, 'last-modified:') === 0) $lm = trim(substr($h, 14));
    }
    if ($etag || $lm) save_feed_etag($url, $etag, $lm);
}

function parse_feed_meta($url) {
    $xml = fetch_rss($url);
    if (!$xml) return null;
    if (isset($xml->channel)) {
        return ['title'=>(string)($xml->channel->title?:$url),'htmlUrl'=>(string)($xml->channel->link?:'')];
    }
    if (isset($xml->title)) {
        $link = '';
        foreach ($xml->link as $l) { if ((string)$l['rel']==='alternate'||!(string)$l['rel']) { $link=(string)$l['href']; break; } }
        return ['title'=>(string)$xml->title,'htmlUrl'=>$link];
    }
    return null;
}

function strip_html($s) {
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES|ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $s));
}

function parse_feed_articles($url, $info) {
    $xml = fetch_rss($url);
    if (!$xml) return [];
    capture_etag($url);

    $items = [];
    if (isset($xml->channel->item)) $items = $xml->channel->item;
    elseif (isset($xml->entry)) $items = $xml->entry;

    $feed_image = '';
    $itunes = $xml->channel ? $xml->channel->children('http://www.itunes.com/dtds/podcast-1.0.dtd') : null;
    if ($itunes && isset($itunes->image)) $feed_image = (string)$itunes->image->attributes()['href'];
    if (!$feed_image && isset($xml->channel->image->url)) {
        $w = (int)($xml->channel->image->width ?? 0);
        if ($w === 0 || $w > 100) $feed_image = (string)$xml->channel->image->url;
    }

    $entries = [];
    $current_year = (int)date('Y');

    foreach ($items as $item) {
        $date_str = (string)($item->pubDate ?? $item->published ?? $item->updated ?? '');
        if (!$date_str) continue;
        $ts = strtotime($date_str);
        if (!$ts || (int)date('Y', $ts) != $current_year) continue;

        $title = strip_html((string)($item->title ?? 'Untitled'));
        $link = '';
        if (isset($item->link)) { $link = (string)$item->link; if (!$link && isset($item->link['href'])) $link = (string)$item->link['href']; }

        $author = '';
        if (isset($item->author)) $author = strip_html((string)$item->author);
        $dc = $item->children('http://purl.org/dc/elements/1.1/');
        if (!$author && isset($dc->creator)) $author = strip_html((string)$dc->creator);

        $summary = '';
        foreach (['description','summary'] as $f) { if (isset($item->$f) && (string)$item->$f) { $summary = strip_html((string)$item->$f); break; } }
        if (!$summary) { $cn = $item->children('http://purl.org/rss/1.0/modules/content/'); if (isset($cn->encoded)) $summary = strip_html((string)$cn->encoded); }
        if (strlen($summary) > 300) $summary = substr($summary, 0, 297) . '...';

        $tags = [];
        if (isset($item->category)) { foreach ($item->category as $cat) { $t = trim((string)$cat); if ($t && strlen($t)<60) $tags[] = $t; } }
        $tags = array_values(array_unique($tags));

        // Image extraction chain
        $image = '';
        $media = $item->children('http://search.yahoo.com/mrss/');
        if (isset($media->thumbnail)) $image = (string)$media->thumbnail['url'];
        if (!$image && isset($media->content)) { foreach ($media->content as $mc) { if ((string)$mc['medium']==='image'||strpos((string)$mc['type'],'image')===0) { $image=(string)$mc['url']; break; } } }
        if (!$image) { $rd=(string)($item->description??$item->summary??''); if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/',$rd,$m)) $image=$m[1]; }
        if (!$image) { $cn=$item->children('http://purl.org/rss/1.0/modules/content/'); if (isset($cn->encoded) && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/',(string)$cn->encoded,$m)) $image=$m[1]; }
        if (!$image && isset($item->content)) { if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/',(string)$item->content,$m)) $image=$m[1]; }
        // YouTube thumbnails
        if (!$image) {
            $ac = (string)($item->description??'').(string)($item->summary??'');
            $cn=$item->children('http://purl.org/rss/1.0/modules/content/'); if (isset($cn->encoded)) $ac.=(string)$cn->encoded;
            if (isset($item->content)) $ac.=(string)$item->content;
            if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/',$ac,$m)) $image='https://img.youtube.com/vi/'.$m[1].'/hqdefault.jpg';
            elseif (preg_match('/youtube\.com\/watch\?v=([a-zA-Z0-9_-]{11})/',$ac,$m)) $image='https://img.youtube.com/vi/'.$m[1].'/hqdefault.jpg';
            elseif (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/',$ac,$m)) $image='https://img.youtube.com/vi/'.$m[1].'/hqdefault.jpg';
        }
        // itunes:image per episode
        if (!$image) { $ii=$item->children('http://www.itunes.com/dtds/podcast-1.0.dtd'); if (isset($ii->image)) $image=(string)$ii->image->attributes()['href']; }
        // og:image from article page
        if (!$image && $link) {
            $octx = stream_context_create(['http'=>['timeout'=>5,'user_agent'=>'Seriously/1.0','follow_location'=>true],'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
            $ph = @file_get_contents($link, false, $octx, 0, 15000);
            if ($ph && (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/',$ph,$m) || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/',$ph,$m))) $image=$m[1];
        }
        // Feed-level image fallback
        if (!$image && $feed_image) $image = $feed_image;
        if ($image) $image = html_entity_decode($image, ENT_QUOTES|ENT_HTML5, 'UTF-8');

        // Enclosures
        $enc_url = ''; $enc_type = '';
        if (isset($item->enclosure)) {
            $et = (string)$item->enclosure['type']; $eu = (string)$item->enclosure['url'];
            if (strpos($et,'audio')===0 || strpos($et,'video')===0) { $enc_url=$eu; $enc_type=$et; }
        }

        $entries[] = ['link'=>$link,'title'=>$title,'author'=>$author,'publication'=>$info['title']??'',
            'pub_url'=>$info['htmlUrl']??'','date'=>date('c',$ts),'date_str'=>date('F d, Y',$ts),
            'summary'=>$summary,'image'=>$image,'tags'=>$tags,'enclosure_url'=>$enc_url,'enclosure_type'=>$enc_type];
    }
    return $entries;
}

// ── Article storage (DB) ──
function utf8_clean($s) {
    if (!$s) return $s;
    // Remove invalid UTF-8 sequences
    $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    // Strip null bytes and other control chars (keep newlines/tabs)
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return $s;
}

function save_articles($articles) {
    $db = get_db();
    if (!$db || empty($articles)) return 0;
    // Use INSERT ... ON CONFLICT DO NOTHING first to count truly new, then upsert
    $inserted = 0;
    $stmt = $db->prepare("
        INSERT INTO articles (link, title, author, publication, pub_url, date, date_str, summary, image, tags, enclosure_url, enclosure_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (link) DO UPDATE SET
            title=EXCLUDED.title, author=EXCLUDED.author, summary=EXCLUDED.summary,
            image=EXCLUDED.image, tags=EXCLUDED.tags, enclosure_url=EXCLUDED.enclosure_url,
            enclosure_type=EXCLUDED.enclosure_type
    ");
    // Check which are truly new
    $check = $db->prepare("SELECT 1 FROM articles WHERE link = ?");
    foreach ($articles as $a) {
        $link = utf8_clean($a['link']);
        $check->execute([$link]);
        $exists = $check->fetch();

        $tags_clean = array_map('utf8_clean', $a['tags'] ?? []);
        $tags_pg = '{' . implode(',', array_map(function($t) { return '"'.str_replace('"','\\"',$t).'"'; }, $tags_clean)) . '}';
        try {
            $stmt->execute([
                $link, utf8_clean($a['title']), utf8_clean($a['author']??''),
                utf8_clean($a['publication']??''), utf8_clean($a['pub_url']??''),
                $a['date']??null, utf8_clean($a['date_str']??''),
                utf8_clean($a['summary']??''), utf8_clean($a['image']??''), $tags_pg,
                utf8_clean($a['enclosure_url']??''), utf8_clean($a['enclosure_type']??''),
            ]);
            if (!$exists) $inserted++;
        } catch (Exception $e) {
            continue;
        }
    }
    return $inserted;
}

function load_all_articles() {
    $db = get_db();
    if ($db) {
        try {
            $rows = $db->query("SELECT * FROM articles ORDER BY date DESC")->fetchAll();
            if (!empty($rows)) {
                return array_map(function($r) {
                    $r['tags'] = array_filter(str_getcsv(trim($r['tags'],'{}'),',','"'));
                    return $r;
                }, $rows);
            }
        } catch (Exception $e) {}
    }
    // Fallback to JSON
    global $DATA_FILE;
    if (file_exists($DATA_FILE)) {
        $data = json_decode(file_get_contents($DATA_FILE), true);
        return $data['articles'] ?? [];
    }
    return [];
}

function build_feed_data() {
    $articles = load_all_articles();
    $feeds = load_feeds();

    $authors = []; $pubs = []; $tc = []; $tcan = [];
    foreach ($articles as $a) {
        if (!empty($a['author'])) $authors[$a['author']] = true;
        if (!empty($a['publication'])) $pubs[$a['publication']] = true;
        foreach ($a['tags'] as $t) {
            $k = strtolower($t);
            $tc[$k] = ($tc[$k]??0)+1;
            if (!isset($tcan[$k])) $tcan[$k] = $t;
        }
    }
    arsort($tc);
    $top = array_slice(array_keys($tc), 0, 80);
    $sa = array_keys($authors); sort($sa);
    $sp = array_keys($pubs); sort($sp);

    return [
        'articles' => $articles,
        'authors' => array_values($sa),
        'publications' => array_values($sp),
        'tags' => array_map(function($k) use ($tcan) { return $tcan[$k]; }, $top),
        'feeds' => $feeds,
    ];
}

// ── Refresh ──
function refresh_batch($offset = 0, $batch_size = 10) {
    $feeds = load_feeds();
    $urls = array_keys($feeds);
    $total = count($urls);
    if ($offset >= $total) {
        $articles = load_all_articles();
        return ['ok'=>true,'done'=>true,'articles'=>count($articles),'sources'=>$total,'offset'=>$total,'total'=>$total];
    }
    set_time_limit(60);
    $batch = array_slice($urls, $offset, $batch_size);
    $fetched = 0; $skipped = 0; $new_count = 0;
    foreach ($batch as $url) {
        $info = $feeds[$url];
        $status = check_feed_changed($url);
        if ($status === 'unchanged') { $skipped++; continue; }
        $entries = parse_feed_articles($url, $info);
        $inserted = save_articles($entries);
        $new_count += $inserted;
        $fetched++;
    }
    $articles = load_all_articles();
    $next = $offset + $batch_size;
    return ['ok'=>true,'done'=>$next>=$total,'articles'=>count($articles),'sources'=>$total,
        'offset'=>$next,'total'=>$total,'batch_fetched'=>$fetched,'batch_skipped'=>$skipped,'batch_new'=>$new_count];
}


// ══════════════════════════════════════
// ROUTING
// ══════════════════════════════════════
$action = $_GET['action'] ?? '';

switch ($action) {

    case 'data':
        $data = build_feed_data();
        header('Content-Type: application/json');
        header('X-Feed-Stale: false'); // staleness handled by cron now
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;

    case 'refresh-batch':
        $offset = (int)($_GET['offset'] ?? 0);
        $batch = min((int)($_GET['batch'] ?? 10), 20);
        json_out(refresh_batch($offset, $batch));
        break;

    case 'refresh':
        require_admin();
        set_time_limit(300);
        $feeds = load_feeds();
        foreach ($feeds as $url => $info) {
            $entries = parse_feed_articles($url, $info);
            save_articles($entries);
        }
        $articles = load_all_articles();
        json_out(['ok'=>true,'articles'=>count($articles),'sources'=>count($feeds)]);
        break;

    case 'config':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_admin();
            $payload = json_decode(file_get_contents('php://input'), true);
            $cfg = load_config();
            foreach (['title','title_accent','tagline','year','ga_measurement_id','auto_refresh_minutes','feed_url'] as $k) {
                if (isset($payload[$k])) $cfg[$k] = $payload[$k];
            }
            save_config($cfg);
            json_out(['ok'=>true]+$cfg);
        } else {
            json_out(load_config());
        }
        break;

    case 'feeds':
        $feeds = load_feeds();
        $list = [];
        foreach ($feeds as $url => $info) $list[] = array_merge(['url'=>$url], $info);
        usort($list, function($a,$b) { return strcasecmp($a['title']??'',$b['title']??''); });
        json_out($list);
        break;

    case 'add-feed':
        require_admin();
        $payload = json_decode(file_get_contents('php://input'), true);
        $url = trim($payload['url'] ?? '');
        if (!$url) json_out(['error'=>'URL required'], 400);
        $meta = parse_feed_meta($url);
        if (!$meta) json_out(['error'=>'Could not parse feed'], 400);
        save_feed($url, $meta['title'], $meta['htmlUrl']);
        json_out(['ok'=>true,'title'=>$meta['title'],'htmlUrl'=>$meta['htmlUrl'],'url'=>$url]);
        break;

    case 'remove-feed':
        require_admin();
        $payload = json_decode(file_get_contents('php://input'), true);
        $url = trim($payload['url'] ?? '');
        $title = remove_feed_entry($url);
        json_out($title ? ['removed'=>true,'title'=>$title] : ['removed'=>false]);
        break;

    case 'db-setup':
        require_admin();
        // Redirect to setup script
        include "$DIR/db_setup.php";
        exit;

    case 'auth-check':
        json_out(['authenticated' => is_admin()]);
        break;

    case 'auth-login':
        $payload = json_decode(file_get_contents('php://input'), true);
        global $ADMIN_USER, $ADMIN_PASS;
        if (($payload['username']??'') === $ADMIN_USER && ($payload['password']??'') === $ADMIN_PASS) {
            $_SESSION['sp_admin'] = true;
            json_out(['ok' => true]);
        } else {
            json_out(['ok'=>false,'error'=>'Invalid credentials'], 401);
        }
        break;

    case 'auth-logout':
        $_SESSION['sp_admin'] = false;
        session_destroy();
        json_out(['ok' => true]);
        break;

    case 'feed':
        $cfg = load_config();
        $site_title = ($cfg['title']??'Seriously').' '.($cfg['title_accent']??'');
        $site_tagline = $cfg['tagline'] ?? '';
        $site_url = 'https://' . $_SERVER['HTTP_HOST'];
        $feed_url = $site_url . '/feed';
        $articles = array_slice(load_all_articles(), 0, 50);
        $updated = count($articles) > 0 ? $articles[0]['date'] : date('c');

        header('Content-Type: application/atom+xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        echo '<feed xmlns="http://www.w3.org/2005/Atom">'."\n";
        echo '  <title>'.htmlspecialchars($site_title)."</title>\n";
        echo '  <subtitle>'.htmlspecialchars($site_tagline)."</subtitle>\n";
        echo '  <link href="'.htmlspecialchars($feed_url).'" rel="self" type="application/atom+xml"/>'."\n";
        echo '  <link href="'.htmlspecialchars($site_url).'" rel="alternate" type="text/html"/>'."\n";
        echo '  <id>'.htmlspecialchars($feed_url)."</id>\n";
        echo '  <updated>'.htmlspecialchars($updated)."</updated>\n";
        echo "  <generator>Seriously</generator>\n";
        foreach ($articles as $a) {
            $pub = htmlspecialchars($a['publication']??'');
            $content = '';
            if (!empty($a['image'])) $content .= '<img src="'.htmlspecialchars($a['image']).'" alt="" style="max-width:100%;margin-bottom:1em;"/><br/>';
            $content .= htmlspecialchars($a['summary']??'');
            $content .= '<br/><br/><em>via '.$pub.'</em>';
            echo "  <entry>\n";
            echo '    <title>'.htmlspecialchars($a['title'])."</title>\n";
            echo '    <link href="'.htmlspecialchars($a['link']).'" rel="alternate" type="text/html"/>'."\n";
            echo '    <id>'.htmlspecialchars($a['link'])."</id>\n";
            echo '    <published>'.htmlspecialchars($a['date'])."</published>\n";
            echo '    <updated>'.htmlspecialchars($a['date'])."</updated>\n";
            echo '    <author><name>'.htmlspecialchars($a['author']?:$a['publication'])."</name></author>\n";
            echo '    <category term="'.$pub.'"/>'."\n";
            echo '    <summary type="html"><![CDATA['.$content."]]></summary>\n";
            echo "  </entry>\n";
        }
        echo "</feed>\n";
        exit;

    case '__cron__':
        break;

    default:
        json_out(['error' => 'Unknown action'], 404);
}
