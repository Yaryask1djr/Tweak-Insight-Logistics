// Only established application routes can be used after authentication.
export const safeReturnPath = (value) => {
    if (typeof value !== 'string' || value.length > 2048 || !value.startsWith('/') || value.startsWith('//')) return null;
    // eslint-disable-next-line no-control-regex -- Navigation input must reject control characters.
    if (/[\\\u0000-\u0020\u007f]/.test(value)) return null;
    try {
        const url = new URL(value, 'https://application.invalid');
        if (url.origin !== 'https://application.invalid') return null;
        if (!/^\/(?:dashboard|client|delivery|admin)(?:\/[a-zA-Z0-9_-]+)*\/?$/.test(url.pathname) && url.pathname !== '/payment/return') return null;
        // Encoded path separators and dot segments must not get normalized into a new destination.
        if (value.split(/[?#]/, 1)[0] !== url.pathname || url.pathname.includes('%')) return null;
        return url.pathname + url.search + url.hash;
    } catch {
        return null;
    }
};
