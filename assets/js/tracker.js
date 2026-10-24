(function () {
    if (typeof PGR_Data === 'undefined') {
        return;
    }

    var debug = PGR_Data.debug === '1';

    if (debug) {
        console.group('[PGR] Post Geo Reporter — beacon debug');
        console.log('ajax_url :', PGR_Data.ajax_url);
        console.log('post_id  :', PGR_Data.post_id);
        console.log('post_type:', PGR_Data.post_type);
        console.log('token    :', PGR_Data.token ? '(present, ' + PGR_Data.token.length + ' chars)' : '(MISSING — token not injected)');
        console.log('page_label (b64):', PGR_Data.page_label || '(empty)');
        console.groupEnd();
    }

    var payload = new URLSearchParams();
    payload.append('action',     'pgr_track');
    payload.append('token',      PGR_Data.token);
    payload.append('post_id',    PGR_Data.post_id);
    payload.append('post_type',  PGR_Data.post_type);
    payload.append('page_label', PGR_Data.page_label || '');

    var send = function () {
        if (debug && navigator.sendBeacon) {
            // In debug mode use fetch so we can read the response.
            fetch(PGR_Data.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                keepalive: true
            }).then(function (r) {
                return r.json();
            }).then(function (d) {
                console.log('[PGR] server response:', d);
            }).catch(function (e) {
                console.warn('[PGR] fetch error (possible CORS or network block):', e);
            });
            return;
        }

        if (navigator.sendBeacon) {
            var blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded' });
            navigator.sendBeacon(PGR_Data.ajax_url, blob);
        } else {
            fetch(PGR_Data.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: payload.toString(),
                keepalive: true
            }).catch(function () {});
        }
    };

    if (document.readyState === 'complete') {
        send();
    } else {
        window.addEventListener('load', send);
    }
})();
