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
set -euo pipefail

KEY="$SSH_KEY"
USER="$SFTP_USER"
HOST="$SFTP_HOST"
PORT="$SFTP_PORT"
REMOTE="$REMOTE_PATH"
LOCAL="\$(cd "\$(dirname "\$0")" && pwd)"

echo "  Deploying $SITE_NAME..."
echo ""

ASKPASS=""
if grep -q '^ssh_pw=' "\$LOCAL/.env" 2>/dev/null; then
  ASKPASS="\$(mktemp /tmp/seriously-siteground-askpass.XXXXXX)"
  export SERIOUSLY_DEPLOY_LOCAL="\$LOCAL"
  cat > "\$ASKPASS" <<'ASKPASS_EOF'
#!/bin/sh
awk -F= '/^ssh_pw=/{print substr(\$0, index(\$0, "=") + 1); exit}' "\$SERIOUSLY_DEPLOY_LOCAL/.env"
ASKPASS_EOF
  chmod 700 "\$ASKPASS"
  export SSH_ASKPASS="\$ASKPASS"
  export SSH_ASKPASS_REQUIRE=force
  export DISPLAY="\${DISPLAY:-:0}"
  trap 'rm -f "\$ASKPASS"' EXIT
fi

if [ "\${1:-}" = "--all" ]; then
  echo "  Mode: full deploy (all files)"
  sftp -o BatchMode=no -o PreferredAuthentications=publickey -o IdentitiesOnly=yes -P \$PORT -i "\$KEY" \${USER}@\${HOST} << SFTP
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
  sftp -o BatchMode=no -o PreferredAuthentications=publickey -o IdentitiesOnly=yes -P \$PORT -i "\$KEY" \${USER}@\${HOST} << SFTP
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
