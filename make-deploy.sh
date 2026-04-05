#!/bin/bash
#
# Generate a deploy.sh for SFTP-based deployment.
#
# Usage:
#   ./make-deploy.sh
#

set -e

echo ""
echo "  Generate deploy script"
echo "  ──────────────────────"
echo ""

read -p "  Site name (e.g. My Photography Site): " SITE_NAME
read -p "  SFTP host: " SFTP_HOST
read -p "  SFTP port [22]: " SFTP_PORT
SFTP_PORT=${SFTP_PORT:-22}
read -p "  SFTP user: " SFTP_USER
read -p "  SSH key path: " SSH_KEY
read -p "  Remote webroot path: " REMOTE_PATH
read -p "  Site URL (e.g. https://mysite.com): " SITE_URL

cat > deploy.sh << EOF
#!/bin/bash
# Deploy $SITE_NAME
# Usage: ./deploy.sh [--all]

KEY="$SSH_KEY"
USER="$SFTP_USER"
HOST="$SFTP_HOST"
PORT="$SFTP_PORT"
REMOTE="$REMOTE_PATH"
LOCAL="\$(dirname "\$0")"

echo "  Deploying $SITE_NAME..."
echo ""

if [ "\$1" = "--all" ]; then
  echo "  Mode: full deploy (all files)"
  sftp -o IdentitiesOnly=yes -P \$PORT -i "\$KEY" \${USER}@\${HOST} << SFTP
cd \$REMOTE
put \$LOCAL/.htaccess
put \$LOCAL/.env
put \$LOCAL/aggregated_feed.html
put \$LOCAL/admin.html
put \$LOCAL/api.php
put \$LOCAL/cron_refresh.php
put \$LOCAL/site_config.json
put \$LOCAL/team_feeds.json
put \$LOCAL/feed_data.json
chmod 666 site_config.json
chmod 666 team_feeds.json
chmod 666 feed_data.json
chmod 666 feed_etags.json
SFTP
else
  echo "  Mode: code deploy (HTML, PHP, config)"
  sftp -o IdentitiesOnly=yes -P \$PORT -i "\$KEY" \${USER}@\${HOST} << SFTP
cd \$REMOTE
put \$LOCAL/.htaccess
put \$LOCAL/aggregated_feed.html
put \$LOCAL/admin.html
put \$LOCAL/api.php
put \$LOCAL/cron_refresh.php
put \$LOCAL/site_config.json
chmod 666 site_config.json
chmod 666 team_feeds.json
chmod 666 feed_data.json
chmod 666 feed_etags.json
SFTP
fi

echo ""
echo "  Done → $SITE_URL"
EOF

chmod +x deploy.sh
echo ""
echo "  ✓ deploy.sh created"
echo "  Run: ./deploy.sh --all"
echo ""
