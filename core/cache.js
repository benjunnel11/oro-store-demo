const OroCache = (function() {
    const PREFIX = 'oro_';

    function _key(name) { return PREFIX + name; }

    function set(name, data, ttlSeconds) {
        try {
            localStorage.setItem(_key(name), JSON.stringify({
                data: data,
                ts: Date.now(),
                ttl: ttlSeconds * 1000
            }));
        } catch(e) {
            if (e.name === 'QuotaExceededError') {
                localStorage.clear();
                try { localStorage.setItem(_key(name), JSON.stringify({ data: data, ts: Date.now(), ttl: ttlSeconds * 1000 })); } catch(e2) {}
            }
        }
    }

    function get(name) {
        try {
            const raw = localStorage.getItem(_key(name));
            if (!raw) return null;
            const entry = JSON.parse(raw);
            if (Date.now() - entry.ts > entry.ttl) {
                localStorage.removeItem(_key(name));
                return null;
            }
            return entry.data;
        } catch(e) { return null; }
    }

    function invalidate(name) {
        localStorage.removeItem(_key(name));
    }

    function invalidatePrefix(prefix) {
        const full = _key(prefix);
        const toRemove = [];
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (k && k.startsWith(full)) toRemove.push(k);
        }
        toRemove.forEach(k => localStorage.removeItem(k));
    }

    function fetchCached(name, url, ttlSeconds, opts) {
        const cached = get(name);
        if (cached) return Promise.resolve({ data: cached, fromCache: true });

        return fetch(url, opts)
            .then(function(r) {
                if (!r.ok) throw new Error('Network error ' + r.status);
                return r.json();
            })
            .then(function(data) {
                set(name, data, ttlSeconds);
                return { data: data, fromCache: false };
            });
    }

    // Stale-while-revalidate: return cache immediately, fetch in background to update
    function fetchSWR(name, url, ttlSeconds, opts) {
        const cached = get(name);
        const fetchPromise = fetch(url, opts)
            .then(function(r) {
                if (!r.ok) throw new Error('Network error ' + r.status);
                return r.json();
            })
            .then(function(data) {
                set(name, data, ttlSeconds);
                return data;
            })
            .catch(function() { return null; });

        if (cached) return { data: cached, fromCache: true, revalidate: fetchPromise };
        return fetchPromise.then(function(data) {
            return { data: data, fromCache: false, revalidate: Promise.resolve(data) };
        });
    }

    return { set: set, get: get, invalidate: invalidate, invalidatePrefix: invalidatePrefix, fetchCached: fetchCached, fetchSWR: fetchSWR };
})();
