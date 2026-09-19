import { API_BASE } from './config';
import { authHeaders, clearAccessToken, getAccessToken, setAccessToken } from './api/client';

describe('Frontend API Configuration & Auth', () => {
    test('configures a valid API base endpoint (absolute URL or relative path)', () => {
        expect(typeof API_BASE).toBe('string');
        expect(API_BASE.trim().length).toBeGreaterThan(0);
        // Matches either absolute http(s) URL or relative API path like /api
        expect(API_BASE).toMatch(/^(https?:\/\/|\/)/);
    });

    beforeEach(() => {
        clearAccessToken();
    });

    test('authHeaders does not send a bearer credential when logged out', () => {
        expect(authHeaders()).toEqual({});
    });

    test('authHeaders reads the in-memory access token only', () => {
        setAccessToken('valid-test-jwt-token-12345');
        expect(getAccessToken()).toBe('valid-test-jwt-token-12345');
        expect(authHeaders()).toEqual({
            Authorization: 'Bearer valid-test-jwt-token-12345',
        });
        clearAccessToken();
        expect(authHeaders()).toEqual({});
    });
});
