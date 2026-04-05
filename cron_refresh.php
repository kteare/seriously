<?php
/**
 * Cron-triggered feed refresh for Seriously Photography (PostgreSQL).
 *
 * Command for SiteGround:
 *   /usr/local/bin/php /home/customer/www/seriouslyphotography.com/public_html/cron_refresh.php
 */

set_time_limit(300);
$_GET['action'] = '__cron__';
$_SESSION = [];

$DIR = __DIR__;
require_once "$DIR/api.php";

echo date('Y-m-d H:i:s') . " Starting cron refresh...\n";

$feeds = load_feeds();
$total = count($feeds);
$urls = array_keys($feeds);
$batch_size = 10;
$offset = 0;
$total_new = 0;
$total_skipped = 0;
$total_fetched = 0;

while ($offset < $total) {
    $batch = array_slice($urls, $offset, $batch_size);
    foreach ($batch as $url) {
        $info = $feeds[$url];
        $status = check_feed_changed($url);
        if ($status === 'unchanged') { $total_skipped++; continue; }
        $entries = parse_feed_articles($url, $info);
        save_articles($entries);
        $total_new += count($entries);
        $total_fetched++;
    }
    $offset += $batch_size;
    echo "  Processed $offset/$total (fetched: $total_fetched, skipped: $total_skipped, new: $total_new)\n";
}

$articles = load_all_articles();
echo date('Y-m-d H:i:s') . " Done. Total articles: " . count($articles) . ", feeds: $total, fetched: $total_fetched, skipped: $total_skipped\n";
