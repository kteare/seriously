<?php
/**
 * Database setup — creates tables and migrates JSON data into PostgreSQL.
 * Run once: /usr/local/bin/php db_setup.php
 * Or visit: /api.php?action=db-setup (requires admin login)
 */

set_time_limit(120);
$_GET['action'] = '__cron__';
$_SESSION = [];

$DIR = __DIR__;
require_once "$DIR/api.php";

echo "Setting up database...\n";

$db = get_db();
if (!$db) {
    echo "ERROR: No database configured. Add DB_HOST, DB_NAME, DB_USER, DB_PASS to .env\n";
    exit(1);
}

$db->exec("
    CREATE TABLE IF NOT EXISTS articles (
        id SERIAL PRIMARY KEY, link TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
        author TEXT DEFAULT '', publication TEXT DEFAULT '', pub_url TEXT DEFAULT '',
        date TIMESTAMPTZ, date_str TEXT DEFAULT '', summary TEXT DEFAULT '',
        image TEXT DEFAULT '', tags TEXT[] DEFAULT '{}',
        enclosure_url TEXT DEFAULT '', enclosure_type TEXT DEFAULT '',
        search_vector tsvector, updated_at TIMESTAMPTZ DEFAULT NOW(),
        created_at TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS feeds (
        id SERIAL PRIMARY KEY, url TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
        html_url TEXT DEFAULT '', etag TEXT DEFAULT '', last_modified TEXT DEFAULT '',
        feed_type TEXT DEFAULT '', description TEXT DEFAULT '', image_url TEXT DEFAULT '',
        active BOOLEAN DEFAULT TRUE, error_count INTEGER DEFAULT 0,
        last_error TEXT DEFAULT '', last_checked TIMESTAMPTZ,
        created_at TIMESTAMPTZ DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT NOT NULL);
    CREATE INDEX IF NOT EXISTS idx_articles_search ON articles USING GIN(search_vector);
    CREATE INDEX IF NOT EXISTS idx_articles_date ON articles(date DESC);
    CREATE INDEX IF NOT EXISTS idx_articles_author ON articles(author);
    CREATE INDEX IF NOT EXISTS idx_articles_publication ON articles(publication);
    CREATE INDEX IF NOT EXISTS idx_articles_tags ON articles USING GIN(tags);
    CREATE INDEX IF NOT EXISTS idx_feeds_active ON feeds(active);
");
echo "Tables created.\n";

// Search trigger
$db->exec("
    CREATE OR REPLACE FUNCTION articles_search_trigger() RETURNS trigger AS \$\$
    BEGIN
        NEW.search_vector :=
            setweight(to_tsvector('english', coalesce(NEW.title, '')), 'A') ||
            setweight(to_tsvector('english', coalesce(NEW.summary, '')), 'B') ||
            setweight(to_tsvector('english', coalesce(NEW.author, '')), 'C') ||
            setweight(to_tsvector('english', coalesce(NEW.publication, '')), 'C') ||
            setweight(to_tsvector('english', coalesce(array_to_string(NEW.tags, ' '), '')), 'B');
        NEW.updated_at := NOW();
        RETURN NEW;
    END;
    \$\$ LANGUAGE plpgsql
");
$db->exec("DROP TRIGGER IF EXISTS trig_articles_search ON articles");
$db->exec("
    CREATE TRIGGER trig_articles_search BEFORE INSERT OR UPDATE ON articles
    FOR EACH ROW EXECUTE FUNCTION articles_search_trigger()
");
echo "Search trigger created.\n";

// Stats view
$db->exec("
    CREATE OR REPLACE VIEW feed_stats AS
    SELECT f.id, f.url, f.title, f.active, f.last_checked, f.error_count,
        COUNT(a.id) AS article_count, MAX(a.date) AS latest_article
    FROM feeds f LEFT JOIN articles a ON a.publication = f.title GROUP BY f.id
");
echo "Stats view created.\n";

// Migrate from JSON if data exists
$FEEDS_FILE = "$DIR/team_feeds.json";
if (file_exists($FEEDS_FILE)) {
    $feeds = json_decode(file_get_contents($FEEDS_FILE), true) ?: [];
    if (!empty($feeds)) {
        $stmt = $db->prepare("INSERT INTO feeds (url, title, html_url) VALUES (?, ?, ?) ON CONFLICT (url) DO UPDATE SET title=EXCLUDED.title, html_url=EXCLUDED.html_url");
        foreach ($feeds as $url => $info) $stmt->execute([$url, $info['title'] ?? '', $info['htmlUrl'] ?? '']);
        echo "Migrated " . count($feeds) . " feeds.\n";
    }
}

$CONFIG_FILE = "$DIR/site_config.json";
if (file_exists($CONFIG_FILE)) {
    $cfg = json_decode(file_get_contents($CONFIG_FILE), true) ?: [];
    $stmt = $db->prepare("INSERT INTO config (key, value) VALUES (?, ?) ON CONFLICT (key) DO UPDATE SET value=EXCLUDED.value");
    foreach ($cfg as $k => $v) $stmt->execute([$k, (string)$v]);
    echo "Migrated config.\n";
}

$DATA_FILE = "$DIR/feed_data.json";
if (file_exists($DATA_FILE)) {
    $data = json_decode(file_get_contents($DATA_FILE), true);
    $articles = $data['articles'] ?? [];
    if (!empty($articles)) {
        $stmt = $db->prepare("
            INSERT INTO articles (link, title, author, publication, pub_url, date, date_str, summary, image, tags, enclosure_url, enclosure_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (link) DO UPDATE SET title=EXCLUDED.title, summary=EXCLUDED.summary, image=EXCLUDED.image
        ");
        $count = 0;
        foreach ($articles as $a) {
            $tags_pg = '{' . implode(',', array_map(function($t) { return '"' . str_replace('"', '\\"', $t) . '"'; }, $a['tags'] ?? [])) . '}';
            try {
                $stmt->execute([
                    $a['link'], $a['title'], $a['author'] ?? '', $a['publication'] ?? '',
                    $a['pub_url'] ?? '', $a['date'] ?? null, $a['date_str'] ?? '',
                    $a['summary'] ?? '', $a['image'] ?? '', $tags_pg,
                    $a['enclosure_url'] ?? '', $a['enclosure_type'] ?? '',
                ]);
                $count++;
            } catch (Exception $e) { continue; }
        }
        echo "Migrated $count articles.\n";
    }
}

echo "Done!\n";
