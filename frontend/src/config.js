// Central API base configuration for frontend
// During development React runs on :3000 and talks to the PHP API on :8000.
// Once compiled and served by PHP, use the same-origin API path instead.
export const API_BASE = process.env.REACT_APP_API_BASE_URL
    || (process.env.NODE_ENV === 'production' ? '/api' : 'http://localhost:8000');
