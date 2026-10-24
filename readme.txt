=== Post Geo Reporter ===
Version: 1.0.0

A lightweight, self-contained WordPress plugin that tracks post/page visits
and reports a breakdown by country and state/region for a chosen date range.

-----------------------------------------------------------------------------
INSTALL
-----------------------------------------------------------------------------
1. WP Admin -> Plugins -> Add New -> Upload Plugin
2. Choose post-geo-reporter.zip, click Install Now, then Activate.
3. A new "Visit Reports" menu appears in the left admin sidebar.

No API key or external account is required. Geo lookups use the free
ip-api.com service (45 requests/minute limit, more than enough for a few
hundred visits/day). Results are cached per IP so the same visitor is only
looked up once.

-----------------------------------------------------------------------------
HOW IT WORKS
-----------------------------------------------------------------------------
- A small JS beacon fires once per page view on singular posts/pages and logs
  the visit via admin-ajax.php.
- The server resolves the visitor's IP to country/state via ip-api.com
  (cached locally afterward).
- Only a salted SHA-256 hash of the IP is stored -- never the raw IP -- used
  solely to de-duplicate repeat views from the same visitor within a 30
  minute window.
- Logged-in users are excluded from tracking by default (Settings page).

-----------------------------------------------------------------------------
REPORT PAGE
-----------------------------------------------------------------------------
Visit Reports -> Reports
  - Filter by date range (defaults to last 28 days) and optionally a single
    post/page ID.
  - "By Country / State" table: visit counts grouped by country, expandable
    to state/region.
  - "By Post / Page" table: visit counts per post/page for the selected
    range.
  - Export CSV button downloads the country/state breakdown for the current
    filter.

Visit Reports -> Settings
  - Toggle whether logged-in users are tracked.
  - Set a data retention window (auto-deletes old visit rows daily via
    WP-Cron). Default is 0 = keep forever.

-----------------------------------------------------------------------------
DATABASE
-----------------------------------------------------------------------------
Two tables are created on activation:
  wp_pgr_visits    - one row per logged visit (post, date/time, geo, ip hash)
  wp_pgr_ip_cache  - IP hash -> resolved geo, to avoid repeat API lookups

Tables are NOT removed on deactivation (your data is safe if you deactivate
to test something). Delete them manually if you ever fully remove the
plugin and want to clear the data:
  DROP TABLE wp_pgr_visits, wp_pgr_ip_cache;

-----------------------------------------------------------------------------
NOTES / LIMITATIONS (MVP scope, fits a small-traffic site)
-----------------------------------------------------------------------------
- Geo lookups happen synchronously inside the AJAX request, which is fine at
  a few hundred visits/day. If traffic grows substantially later, this can
  be moved to a queued/cron-based lookup to avoid hitting ip-api's rate
  limit.
- If the site sits behind Cloudflare or another proxy/CDN, the plugin reads
  CF-Connecting-IP / X-Forwarded-For first, falling back to REMOTE_ADDR.
- No JavaScript console errors should appear if a visitor blocks the beacon
  (e.g. ad blocker) -- it simply means that visit isn't counted.
