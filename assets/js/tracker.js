/* Post Geo Reporter – JS beacon (fallback for cached pages) */
(function () {
    if (typeof pgr_data === 'undefined') return;

    var d     = pgr_data;
    var debug = d.debug == 1;

    var payload = new URLSearchParams({
        action:     d.action,
        token:      d.token,
        post_id:    d.post_id,
        post_type:  d.post_type,
        page_label: d.page_label
    });

    if (debug) {
        console.log('[PGR] Debug mode ON');
        console.log('[PGR] ajax_url:', d.ajax_url);
        console.log('[PGR] post_id:', d.post_id, '| post_type:', d.post_type);
        console.log('[PGR] page_label (b64):', d.page_label);
        console.log('[PGR] token present:', !!d.token);

        fetch(d.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (j) { console.log('[PGR] server response:', JSON.stringify(j)); })
        .catch(function (e) { console.error('[PGR] beacon error:', e); });

    } else {
        // Production: sendBeacon (fire-and-forget)
        var blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded' });
        var sent = navigator.sendBeacon(d.ajax_url, blob);
        if (!sent) {
            // sendBeacon failed (browser limit), fall back to fetch
            fetch(d.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                keepalive: true
            }).catch(function () {});
        }
    }
})();
