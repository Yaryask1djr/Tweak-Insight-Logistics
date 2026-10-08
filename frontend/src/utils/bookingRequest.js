// Keep only a request digest and random key in this tab's storage, never contacts,
// addresses or an OTP. A lost HTTP response must not turn retry into a new booking.
const canonical = value => {
    if (Array.isArray(value)) return value.map(canonical);
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
    }
    return value;
};

export async function bookingRequest(userId, payload) {
    const bytes = new TextEncoder().encode(JSON.stringify(canonical(payload)));
    const digest = await window.crypto.subtle.digest('SHA-256', bytes);
    const hash = Array.from(new Uint8Array(digest), n => n.toString(16).padStart(2, '0')).join('');
    const storageKey = `til.booking.v1.${userId}.${hash}`;
    let key = window.sessionStorage.getItem(storageKey);
    if (!key || !/^[a-f0-9]{32}$/.test(key)) {
        key = Array.from(window.crypto.getRandomValues(new Uint8Array(16)), n => n.toString(16).padStart(2, '0')).join('');
        // Fail before sending if persistence is unavailable; silently losing this
        // key would defeat retry safety after a page reload.
        window.sessionStorage.setItem(storageKey, key);
    }
    return { key, confirmed: () => window.sessionStorage.removeItem(storageKey) };
}
