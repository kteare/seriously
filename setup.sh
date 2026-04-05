#!/bin/bash
#
# Seriously — RSS Aggregator Setup
#
# Usage:
#   git clone https://github.com/kteare/seriously.git mysite
#   cd mysite
#   ./setup.sh
#

set -e

BOLD="\033[1m"
DIM="\033[2m"
GOLD="\033[33m"
GREEN="\033[32m"
RED="\033[31m"
RESET="\033[0m"

echo ""
echo -e "${BOLD}  Seriously${GOLD} — RSS Aggregator Setup${RESET}"
echo -e "${DIM}  ─────────────────────────────────────${RESET}"
echo ""

# ── 1. Site identity ──
read -p "  Site title (e.g. Seriously): " TITLE
TITLE=${TITLE:-Seriously}

read -p "  Accent word (e.g. Photography, VC, News): " ACCENT
ACCENT=${ACCENT:-News}

read -p "  Tagline: " TAGLINE
TAGLINE=${TAGLINE:-The best content, curated daily.}

YEAR=$(date +%Y)

# ── 2. Admin credentials ──
echo ""
read -p "  Admin username [admin]: " ADMIN_USER
ADMIN_USER=${ADMIN_USER:-admin}

while true; do
  read -sp "  Admin password: " ADMIN_PASS
  echo ""
  if [ -n "$ADMIN_PASS" ]; then break; fi
  echo -e "  ${RED}Password cannot be empty${RESET}"
done

# ── 3. Google Analytics (optional) ──
echo ""
read -p "  GA4 Measurement ID (leave blank to skip): " GA_ID

# ── 4. PostgreSQL (optional) ──
echo ""
echo -e "  ${DIM}PostgreSQL is optional — without it, data is stored in JSON files.${RESET}"
read -p "  Use PostgreSQL? [y/N]: " USE_DB

DB_HOST="" DB_NAME="" DB_USER="" DB_PASS=""
if [[ "$USE_DB" =~ ^[Yy] ]]; then
  read -p "  DB host [localhost]: " DB_HOST
  DB_HOST=${DB_HOST:-localhost}
  read -p "  DB name: " DB_NAME
  read -p "  DB user: " DB_USER
  read -sp "  DB password: " DB_PASS
  echo ""
fi

# ── 5. Write config files ──
echo ""
echo -e "  ${BOLD}Writing configuration...${RESET}"

cat > site_config.json << EOF
{
  "title": "$TITLE",
  "title_accent": "$ACCENT",
  "tagline": "$TAGLINE",
  "year": "$YEAR",
  "ga_measurement_id": "$GA_ID",
  "auto_refresh_minutes": 60,
  "feed_url": "/feed"
}
EOF

cat > .env << EOF
SP_ADMIN_USER=$ADMIN_USER
SP_ADMIN_PASS=$ADMIN_PASS
EOF

if [ -n "$DB_HOST" ]; then
  cat >> .env << EOF

# PostgreSQL
DB_HOST=$DB_HOST
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF
fi

echo "  ✓ site_config.json"
echo "  ✓ .env"

# ── 6. Initialize empty data ──
if [ ! -f feed_data.json ]; then
  echo '{"articles":[],"authors":[],"publications":[],"tags":[],"feeds":{}}' > feed_data.json
  echo "  ✓ feed_data.json (empty)"
fi

if [ ! -f team_feeds.json ] || [ "$(cat team_feeds.json)" = "{}" ]; then
  echo '{}' > team_feeds.json
  echo "  ✓ team_feeds.json (empty)"
fi

# ── 7. PostgreSQL setup ──
if [ -n "$DB_HOST" ]; then
  echo ""
  echo -e "  ${BOLD}Setting up PostgreSQL...${RESET}"

  # Check if psql is available
  if command -v psql &>/dev/null; then
    PGPASSWORD=$DB_PASS psql -h $DB_HOST -U $DB_USER -d $DB_NAME -c "
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
      \$\$ LANGUAGE plpgsql;
      DROP TRIGGER IF EXISTS trig_articles_search ON articles;
      CREATE TRIGGER trig_articles_search BEFORE INSERT OR UPDATE ON articles
        FOR EACH ROW EXECUTE FUNCTION articles_search_trigger();
    " 2>&1 && echo "  ✓ Database tables created" || echo -e "  ${RED}✗ Database setup failed — you can run db_setup.php later${RESET}"
  else
    echo -e "  ${DIM}psql not found — tables will be created on first PHP run via /admin${RESET}"
    echo -e "  ${DIM}Or visit: https://yoursite.com/api.php?action=db-setup (after login)${RESET}"
  fi

  # Seed config into DB
  if command -v psql &>/dev/null; then
    PGPASSWORD=$DB_PASS psql -h $DB_HOST -U $DB_USER -d $DB_NAME -c "
      INSERT INTO config (key, value) VALUES
        ('title', '$TITLE'), ('title_accent', '$ACCENT'),
        ('tagline', '$TAGLINE'), ('year', '$YEAR'),
        ('ga_measurement_id', '$GA_ID'),
        ('auto_refresh_minutes', '60'), ('feed_url', '/feed')
      ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value;
    " 2>/dev/null && echo "  ✓ Config seeded to database"
  fi
fi

# ── 8. Check Python + feedparser ──
echo ""
echo -e "  ${BOLD}Checking dependencies...${RESET}"

if command -v python3 &>/dev/null; then
  echo "  ✓ Python3 found"
  python3 -c "import feedparser" 2>/dev/null && echo "  ✓ feedparser installed" || {
    echo -e "  ${DIM}Installing feedparser...${RESET}"
    pip3 install feedparser 2>/dev/null && echo "  ✓ feedparser installed" || echo -e "  ${RED}✗ pip3 install feedparser failed — install manually${RESET}"
  }
else
  echo -e "  ${DIM}Python3 not found — local server (seriously.py) won't work${RESET}"
  echo -e "  ${DIM}PHP deployment still works fine${RESET}"
fi

# ── 9. Deployment options ──
echo ""
echo -e "  ${BOLD}Deployment options:${RESET}"
echo ""
echo -e "  ${GREEN}Local development:${RESET}"
echo "    python3 seriously.py"
echo "    → Opens http://localhost:8420"
echo ""
echo -e "  ${GREEN}PHP web server (Apache/Nginx/LiteSpeed):${RESET}"
echo "    Upload all files to your web root (public_html/)"
echo "    Required files: aggregated_feed.html, admin.html, api.php,"
echo "    cron_refresh.php, .htaccess, .env, site_config.json, team_feeds.json"
echo ""
echo -e "  ${GREEN}Cron job (recommended):${RESET}"
echo "    /usr/local/bin/php /path/to/public_html/cron_refresh.php"
echo "    Schedule: every 30-60 minutes"
echo ""
echo -e "  ${GREEN}First steps:${RESET}"
echo "    1. Go to /admin and log in"
echo "    2. Import an OPML file or add feeds one by one"
echo "    3. Click Refresh to fetch articles"
echo ""
echo -e "  ${BOLD}${GOLD}$TITLE $ACCENT${RESET} is ready!"
echo ""
