import axios from 'axios';
import { API_BASE } from '../config';

// Deliberately module-private. Unlike localStorage/sessionStorage, this value
// disappears on reload and cannot be copied out by a persisted XSS payload.
let accessToken = null;
let refreshPromise = null;

export const getAccessToken = () => accessToken;
export const setAccessToken = (token) => {
    accessToken = typeof token === 'string' && token.trim() !== '' ? token : null;
};
export const clearAccessToken = () => {
    accessToken = null;
};

/** Header helper retained for document downloads and multipart requests. */
export const authHeaders = () => (accessToken ? { Authorization: `Bearer ${accessToken}` } : {});

const isApiRequest = (config = {}) => {
    const url = String(config.url || '');
    return config._tilApi === true
        || config.baseURL === API_BASE
        || url.startsWith(`${API_BASE}/`)
        || url === API_BASE;
};

const isCredentialBootstrapRequest = (config = {}) => /\/auth\/(?:login|refresh|register-client|register-delivery)(?:[/?#]|$)/.test(String(config.url || ''));

const notifySessionExpired = () => {
    clearAccessToken();
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new Event('auth:unauthorized'));
    }
};

/**
 * Uses the HttpOnly refresh cookie to obtain a fresh short-lived token. The
 * single shared promise prevents a burst of 401 responses from rotating the
 * refresh token more than once.
 */
export const refreshSession = async () => {
    if (!refreshPromise) {
        const rotate = async () => {
            let response;
            for (let attempt = 0; attempt < 4; attempt++) {
                try {
                    response = await axios.post(`${API_BASE}/auth/refresh`, {}, {
                        _tilApi: true,
                        _skipAuthRefresh: true,
                        withCredentials: true,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    break;
                } catch (error) {
                    if (error.response?.status !== 409 || attempt === 3) throw error;
                    await new Promise(resolve => setTimeout(resolve, 150 * (attempt + 1)));
                }
            }
            const session = response.data?.data;
            if (!session?.access_token || !session?.user) {
                throw new Error('Refresh response did not include a session.');
            }
            setAccessToken(session.access_token);
            return session;
        };
        // Cookie rotations across tabs share one origin-scoped lock. Browsers
        // without Web Locks use the bounded server conflict/retry path above.
        const rotation = typeof navigator !== 'undefined' && navigator.locks
            ? navigator.locks.request(`til.refresh.${API_BASE}`, rotate)
            : rotate();
        refreshPromise = rotation.finally(() => {
            refreshPromise = null;
        });
    }
    return refreshPromise;
};

/** Pre-configured Axios instance for application API requests. */
export const apiClient = axios.create({
    baseURL: API_BASE,
    timeout: 15000,
    withCredentials: true,
    headers: { 'Content-Type': 'application/json' },
});

const attachRequestAuthentication = (config) => {
    if (!isApiRequest(config)) return config;

    if (!config.timeout) config.timeout = 15000;
    config.withCredentials = true;
    if (!isCredentialBootstrapRequest(config) && accessToken) {
        config.headers = config.headers || {};
        const provided = config.headers.Authorization || config.headers.authorization;
        if (!provided || provided === 'Bearer null' || provided === 'Bearer undefined') {
            config.headers.Authorization = `Bearer ${accessToken}`;
        }
    }
    return config;
};

const retryAfterRefresh = (client) => async (error) => {
    const config = error.config || {};
    const canRefresh = error.response?.status === 401
        && isApiRequest(config)
        && !config._retry
        && !config._skipAuthRefresh
        && !isCredentialBootstrapRequest(config)
        && Boolean(accessToken);

    if (!canRefresh) return Promise.reject(error);

    config._retry = true;
    try {
        const session = await refreshSession();
        config.headers = config.headers || {};
        config.headers.Authorization = `Bearer ${session.access_token}`;
        return client(config);
    } catch (refreshError) {
        if (refreshError.response?.status === 401) notifySessionExpired();
        return Promise.reject(error);
    }
};

// A handful of older components still import default Axios. Applying the same
// in-memory interception keeps those calls safe while they are migrated to
// apiClient. No token is ever read from persistent browser storage.
axios.interceptors.request.use(attachRequestAuthentication, (error) => Promise.reject(error));
axios.interceptors.response.use((response) => response, retryAfterRefresh(axios));
apiClient.interceptors.request.use(attachRequestAuthentication, (error) => Promise.reject(error));
apiClient.interceptors.response.use((response) => response, retryAfterRefresh(apiClient));

export const apiGet = async (path, options = {}) => (await apiClient.get(path, options)).data;
export const apiPost = async (path, data = {}, options = {}) => (await apiClient.post(path, data, options)).data;
export const apiPut = async (path, data = {}, options = {}) => (await apiClient.put(path, data, options)).data;
export const apiPatch = async (path, data = {}, options = {}) => (await apiClient.patch(path, data, options)).data;
export const apiDelete = async (path, options = {}) => (await apiClient.delete(path, options)).data;

export default apiClient;
