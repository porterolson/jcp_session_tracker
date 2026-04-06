(function () {
	if (!window.jcpstTracker) {
		return;
	}

	var config = window.jcpstTracker;
	if (!config.ajaxUrl || !config.enabled) {
		return;
	}

	var currentKey = [window.location.pathname || '/', window.location.search || '', document.title || ''].join('|');
	if (window.sessionStorage) {
		try {
			var existing = window.sessionStorage.getItem('jcpst:last');
			if (existing === currentKey) {
				return;
			}
			window.sessionStorage.setItem('jcpst:last', currentKey);
		} catch (e) {
			// Ignore sessionStorage access errors.
		}
	}

	var payload = new URLSearchParams();
	payload.append('action', 'jcpst_track_pageview');
	payload.append('page_url', window.location.href);
	payload.append('path', window.location.pathname || '/');
	payload.append('query_string', window.location.search ? window.location.search.replace(/^\?/, '') : '');
	payload.append('page_title', document.title || '');
	payload.append('referrer', document.referrer || '');
	payload.append('is_async', '1');
	payload.append('_jcpst_nonce', config.nonce || '');
	payload.append('_jcpst_rand', String(Date.now()));

	var getUrl = config.ajaxUrl
		+ (config.ajaxUrl.indexOf('?') === -1 ? '?' : '&')
		+ payload.toString();

	fetch(config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			'Cache-Control': 'no-store'
		},
		body: payload.toString(),
		keepalive: true,
		cache: 'no-store'
	}).catch(function () {
		return null;
	});

	try {
		var img = new Image();
		img.src = getUrl;
	} catch (e) {
		// Ignore image beacon errors.
	}
})();
