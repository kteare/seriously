#!/usr/bin/env python3
"""
Seriously Photography — local server.

Usage:
    python3 seriously.py

Opens http://localhost:8420 in your browser.
Serves the feed page with live refresh and add-feed capabilities.
"""

import json, os, re, html as H, sys, secrets, hashlib, http.cookies
from datetime import datetime, timezone
from time import mktime
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import parse_qs
import concurrent.futures
import webbrowser
import threading

import feedparser

DIR = os.path.dirname(os.path.abspath(__file__))
FEEDS_FILE = os.path.join(DIR, 'team_feeds.json')
DATA_FILE = os.path.join(DIR, 'feed_data.json')
HTML_FILE = os.path.join(DIR, 'aggregated_feed.html')
ADMIN_HTML = os.path.join(DIR, 'admin.html')
CONFIG_FILE = os.path.join(DIR, 'site_config.json')
ENV_FILE = os.path.join(DIR, '.env')
PORT = 8420

# ── Auth ──
def load_env():
    env = {}
    if os.path.exists(ENV_FILE):
        with open(ENV_FILE) as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    env[k.strip()] = v.strip()
    return env

_env = load_env()
ADMIN_USER = _env.get('SP_ADMIN_USER', 'admin')
ADMIN_PASS = _env.get('SP_ADMIN_PASS', 'seriously2026')

# Active session tokens (in-memory, cleared on restart)
_sessions = set()


def load_config():
    if os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE) as f:
            return json.load(f)
    return {'title': 'Seriously', 'title_accent': 'Photography',
            'tagline': 'Curated daily.', 'year': '2026'}

def save_config(cfg):
    with open(CONFIG_FILE, 'w') as f:
        json.dump(cfg, f, indent=2)


def load_feeds():
    with open(FEEDS_FILE) as f:
        return json.load(f)


def save_feeds(feeds):
    with open(FEEDS_FILE, 'w') as f:
        json.dump(feeds, f, indent=2)


def fetch_one(url, info):
    try:
        feed = feedparser.parse(url)
        entries = []
        for entry in feed.entries:
            published = None
            for attr in ('published_parsed', 'updated_parsed'):
                t = getattr(entry, attr, None)
                if t:
                    try:
                        published = datetime.fromtimestamp(mktime(t), tz=timezone.utc)
                    except Exception:
                        pass
                    break
            if not published:
                for attr in ('published', 'updated'):
                    raw = getattr(entry, attr, None)
                    if raw:
                        try:
                            published = datetime.fromisoformat(raw.replace('Z', '+00:00'))
                        except Exception:
                            pass
                        break
            if not published or published.year != 2026:
                continue
            author = getattr(entry, 'author', None) or ''
            link = getattr(entry, 'link', '') or ''
            title = H.unescape(getattr(entry, 'title', 'Untitled') or 'Untitled')
            tags = []
            for t in getattr(entry, 'tags', []):
                term = t.get('term', '').strip()
                if term and len(term) < 60:
                    tags.append(term)
            seen_t = set()
            clean_tags = []
            for tag in tags:
                k = tag.lower()
                if k not in seen_t:
                    seen_t.add(k)
                    clean_tags.append(tag)
            summary = ''
            for attr in ('summary', 'description'):
                s = getattr(entry, attr, None)
                if s:
                    summary = s
                    break
            if not summary:
                content = getattr(entry, 'content', None)
                if content and len(content) > 0:
                    summary = content[0].get('value', '')
            summary_text = re.sub(r'<[^>]+>', ' ', summary)
            summary_text = H.unescape(summary_text)
            summary_text = re.sub(r'\s+', ' ', summary_text).strip()
            if len(summary_text) > 300:
                summary_text = summary_text[:297] + '...'
            image = ''
            media_thumbs = getattr(entry, 'media_thumbnail', None)
            if media_thumbs and len(media_thumbs) > 0:
                image = media_thumbs[0].get('url', '')
            if not image:
                media_content = getattr(entry, 'media_content', None)
                if media_content:
                    for mc in media_content:
                        if mc.get('medium') == 'image' or mc.get('type', '').startswith('image'):
                            image = mc.get('url', '')
                            break
            if not image:
                for enc in getattr(entry, 'enclosures', []):
                    if enc.get('type', '').startswith('image'):
                        image = enc.get('href', '') or enc.get('url', '')
                        break
            if not image:
                img_match = re.search(r'<img[^>]+src=["\']([^"\' ]+)["\']', summary)
                if img_match:
                    image = img_match.group(1)
            if not image:
                for c in getattr(entry, 'content', []):
                    img_match = re.search(r'<img[^>]+src=["\']([^"\' ]+)["\']', c.get('value', ''))
                    if img_match:
                        image = img_match.group(1)
                        break
            entries.append({
                'title': title, 'link': link, 'author': author,
                'publication': info['title'], 'pub_url': info.get('htmlUrl', ''),
                'date': published.isoformat(),
                'date_str': published.strftime('%B %d, %Y'),
                'summary': summary_text, 'image': image, 'tags': clean_tags,
            })
        return entries
    except Exception:
        return []


def fetch_all_feeds(feeds=None):
    if feeds is None:
        feeds = load_feeds()
    all_entries = []
    with concurrent.futures.ThreadPoolExecutor(max_workers=15) as ex:
        futs = {ex.submit(fetch_one, url, info): info['title']
                for url, info in feeds.items()}
        for fut in concurrent.futures.as_completed(futs):
            all_entries.extend(fut.result())
    seen = set()
    deduped = []
    for e in all_entries:
        if e['link'] not in seen:
            seen.add(e['link'])
            deduped.append(e)
    deduped.sort(key=lambda x: x['date'], reverse=True)
    all_authors = sorted(set(e['author'] for e in deduped if e['author']))
    all_pubs = sorted(set(e['publication'] for e in deduped))
    tag_counts = {}
    tag_canonical = {}
    for e in deduped:
        for t in e['tags']:
            k = t.lower()
            tag_counts[k] = tag_counts.get(k, 0) + 1
            if k not in tag_canonical:
                tag_canonical[k] = t
    top_tags = sorted(tag_counts, key=lambda k: -tag_counts[k])[:80]
    all_tags = [tag_canonical[k] for k in top_tags]
    data = {
        'articles': deduped,
        'authors': all_authors,
        'publications': all_pubs,
        'tags': all_tags,
        'feeds': {url: info for url, info in feeds.items()},
    }
    with open(DATA_FILE, 'w') as f:
        json.dump(data, f, ensure_ascii=False)
    return data


def add_feed(url):
    """Add a feed by URL: parse it to discover title/htmlUrl, save to feeds file."""
    feed = feedparser.parse(url)
    title = getattr(feed.feed, 'title', None) or url
    html_url = getattr(feed.feed, 'link', '') or ''
    feeds = load_feeds()
    feeds[url] = {
        'title': title,
        'htmlUrl': html_url,
        'feedId': f'feed/{url}',
    }
    save_feeds(feeds)
    return {'title': title, 'htmlUrl': html_url, 'url': url}


def remove_feed(url):
    feeds = load_feeds()
    if url in feeds:
        name = feeds[url]['title']
        del feeds[url]
        save_feeds(feeds)
        return {'removed': True, 'title': name}
    return {'removed': False}


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass  # quiet

    def _json(self, data, status=200):
        body = json.dumps(data).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', len(body))
        self.end_headers()
        self.wfile.write(body)

    def _get_session(self):
        """Return session token from cookie, or None."""
        cookie_header = self.headers.get('Cookie', '')
        c = http.cookies.SimpleCookie()
        try:
            c.load(cookie_header)
        except Exception:
            return None
        morsel = c.get('sp_session')
        return morsel.value if morsel else None

    def _is_admin(self):
        token = self._get_session()
        return bool(token and token in _sessions)

    def _require_admin(self):
        if not self._is_admin():
            self._json({'error': 'Unauthorized', 'login_required': True}, 401)
            return False
        return True

    def _serve_html(self, filepath):
        """Serve an HTML file, injecting the current GA measurement ID."""
        with open(filepath, 'rb') as f:
            body = f.read()
        cfg = load_config()
        ga_id = cfg.get('ga_measurement_id', '')
        if ga_id:
            body = body.replace(b'G-W5RM4HCM41', ga_id.encode())
        self.send_response(200)
        self.send_header('Content-Type', 'text/html; charset=utf-8')
        self.send_header('Content-Length', len(body))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == '/' or self.path == '/index.html':
            self._serve_html(HTML_FILE)
        elif self.path == '/api/data':
            if os.path.exists(DATA_FILE):
                with open(DATA_FILE) as f:
                    data = json.load(f)
            else:
                data = fetch_all_feeds()
            self._json(data)
        elif self.path == '/api/config':
            self._json(load_config())
        elif self.path == '/api/feeds':
            feeds = load_feeds()
            # Return as list with URLs for easier rendering
            feed_list = [{'url': url, **info} for url, info in feeds.items()]
            feed_list.sort(key=lambda x: x.get('title', '').lower())
            self._json(feed_list)
        elif self.path == '/admin':
            self._serve_html(ADMIN_HTML)
        elif self.path == '/api/auth/check':
            self._json({'authenticated': self._is_admin()})
        else:
            self.send_error(404)

    def do_POST(self):
        length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(length) if length else b''

        # ── Public: login ──
        if self.path == '/api/auth/login':
            payload = json.loads(body)
            user = payload.get('username', '')
            pwd = payload.get('password', '')
            if user == ADMIN_USER and pwd == ADMIN_PASS:
                token = secrets.token_hex(32)
                _sessions.add(token)
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                cookie = f'sp_session={token}; Path=/; HttpOnly; SameSite=Strict; Max-Age=86400'
                self.send_header('Set-Cookie', cookie)
                resp = json.dumps({'ok': True}).encode()
                self.send_header('Content-Length', len(resp))
                self.end_headers()
                self.wfile.write(resp)
                print(f'  Admin login: {user}')
            else:
                self._json({'ok': False, 'error': 'Invalid credentials'}, 401)
            return

        if self.path == '/api/auth/logout':
            token = self._get_session()
            if token:
                _sessions.discard(token)
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Set-Cookie', 'sp_session=; Path=/; Max-Age=0')
            resp = json.dumps({'ok': True}).encode()
            self.send_header('Content-Length', len(resp))
            self.end_headers()
            self.wfile.write(resp)
            return

        # ── Protected: admin endpoints ──
        if self.path == '/api/refresh':
            if not self._require_admin():
                return
            print('  Refreshing all feeds...')
            data = fetch_all_feeds()
            print(f'  Done — {len(data["articles"])} articles from {len(data["feeds"])} sources')
            self._json({
                'ok': True,
                'articles': len(data['articles']),
                'sources': len(data['feeds']),
            })
        elif self.path == '/api/add-feed':
            if not self._require_admin():
                return
            payload = json.loads(body)
            url = payload.get('url', '').strip()
            if not url:
                self._json({'error': 'URL required'}, 400)
                return
            print(f'  Adding feed: {url}')
            try:
                info = add_feed(url)
                print(f'  Added: {info["title"]}')
                self._json({'ok': True, **info})
            except Exception as e:
                self._json({'error': str(e)}, 500)
        elif self.path == '/api/remove-feed':
            if not self._require_admin():
                return
            payload = json.loads(body)
            url = payload.get('url', '').strip()
            result = remove_feed(url)
            self._json(result)
        elif self.path == '/api/config':
            if not self._require_admin():
                return
            payload = json.loads(body)
            cfg = load_config()
            for key in ('title', 'title_accent', 'tagline', 'year', 'ga_measurement_id'):
                if key in payload:
                    cfg[key] = payload[key]
            save_config(cfg)
            print(f'  Config updated: {cfg["title"]} {cfg["title_accent"]}')
            self._json({'ok': True, **cfg})
        else:
            self.send_error(404)


def main():
    # Initial fetch if no data yet
    if not os.path.exists(DATA_FILE):
        print('First run — fetching all feeds...')
        data = fetch_all_feeds()
        print(f'Fetched {len(data["articles"])} articles from {len(data["feeds"])} sources')

    server = HTTPServer(('127.0.0.1', PORT), Handler)
    server.allow_reuse_address = True
    url = f'http://localhost:{PORT}'
    print(f'\n  Seriously Photography')
    print(f'  Running at {url}\n')
    threading.Timer(0.5, lambda: webbrowser.open(url)).start()
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\n  Shutting down.')
        server.shutdown()


if __name__ == '__main__':
    main()
