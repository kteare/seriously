# Seriously — RSS Aggregator

A self-hosted RSS aggregator with a beautiful reader UI, full-text search, social sharing, and an admin CMS. Deploy anywhere with PHP, or run locally with Python.

**Live examples:**
- [Seriously Photography](https://seriouslyphotography.com) — 90 photography & filmmaking feeds
- [Seriously VC](https://seriouslyvc.com) — 120 venture capital & startup feeds

## Features

- **Reader** — sidebar layout with single-column feed, dark/light theme toggle
- **Search** — full-text search across titles, summaries, authors, tags
- **Filters** — by publication, author, date range, and tags
- **Social sharing** — X, Facebook, LinkedIn, Instagram (copy link), Email
- **RSS output** — Atom feed at `/feed` for subscriber syndication
- **Admin CMS** — login-protected site settings, feed management, OPML import
- **Auto-refresh** — batched incremental refresh with conditional GET (ETag/Last-Modified)
- **Image extraction** — 9-source chain including og:image fallback and YouTube thumbnails
- **Audio/video** — inline players for podcast and video enclosures
- **PostgreSQL** — optional, with full-text search vectors and automatic triggers
- **Legacy URL support** — 301 redirects for WordPress `/category/`, `/author/`, `/YYYY/MM/DD/` patterns

## Quick Start

```bash
git clone https://github.com/kteare/seriously.git mysite
cd mysite
./setup.sh
```

The setup wizard will ask for your site name, admin password, and optional PostgreSQL credentials.

## Local Development

```bash
pip3 install feedparser
python3 seriously.py
```

Opens `http://localhost:8420`. Go to the admin (click + icon in sidebar), add some feeds, and hit Refresh.

## Deploy to a Web Server

Upload these files to your web root (`public_html/`):

| File | Purpose |
|------|---------|
| `aggregated_feed.html` | Reader page (served as index) |
| `admin.html` | Admin CMS |
| `api.php` | PHP API backend |
| `cron_refresh.php` | Cron job script |
| `.htaccess` | URL routing + security |
| `.env` | Credentials (blocked from web) |
| `site_config.json` | Site settings |
| `team_feeds.json` | Feed list |

Set file permissions for PHP to write:
```bash
chmod 666 site_config.json team_feeds.json feed_data.json
```

### SFTP Deployment

Generate a deploy script for your server:
```bash
./make-deploy.sh
./deploy.sh --all
```

### Cron Job

Set up a cron to refresh feeds every 30-60 minutes:
```
/usr/local/bin/php /path/to/public_html/cron_refresh.php
```

Uses conditional GET to skip unchanged feeds — most runs take seconds.

## PostgreSQL (Optional)

Without PostgreSQL, data is stored in JSON files. With PostgreSQL:

- Full-text search with weighted ranking (title > summary/tags > author)
- Atomic writes — no data corruption from timeouts
- Concurrent read/write safety
- Automatic search vector triggers

Setup:
```bash
# During ./setup.sh, answer "y" to PostgreSQL
# Or manually:
php db_setup.php
```

## Configuration

All settings are editable in `/admin`:

| Setting | Description |
|---------|-------------|
| Title / Accent | Site name (e.g. "Seriously" + "Photography") |
| Tagline | Subtitle shown in sidebar |
| GA4 Measurement ID | Google Analytics tracking |
| Auto-refresh interval | Minutes between cron refreshes |
| RSS Feed URL | Path to Atom feed (shows subscribe icon) |

## Architecture

```
Reader (aggregated_feed.html)
  ├── Sidebar: logo, search, filters, tags, share
  └── Main: single-column article cards

Admin (admin.html)
  ├── Site Settings (title, tagline, GA, refresh interval)
  └── Feed Manager (add, remove, OPML import, refresh)

API (api.php)
  ├── /api.php?action=data          → article feed (JSON)
  ├── /api.php?action=config        → site config
  ├── /api.php?action=feeds         → feed list
  ├── /api.php?action=add-feed      → add feed (admin)
  ├── /api.php?action=remove-feed   → remove feed (admin)
  ├── /api.php?action=refresh-batch → incremental refresh
  ├── /api.php?action=auth-login    → admin login
  └── /feed                         → Atom feed output

Storage
  ├── PostgreSQL (if configured)
  └── JSON files (fallback)
```

## License

MIT
