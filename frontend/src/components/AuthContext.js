import { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { apiClient, clearAccessToken, refreshSession, setAccessToken } from '../api/client';

const AuthContext = createContext(null);

export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [isRestoring, setIsRestoring] = useState(true);

    const clearSession = useCallback(() => {
        clearAccessToken();
        setUser(null);
    }, []);

    const login = useCallback((userData, token) => {
        setAccessToken(token);
        setUser(userData);
    }, []);

    // On a reload, the app can only restore through the opaque HttpOnly cookie.
    // Neither the user profile nor the bearer token is persisted in web storage.
    useEffect(() => {
        let active = true;
        refreshSession()
            .then((session) => {
                if (active) setUser(session.user);
            })
            .catch(() => {
                if (active) clearSession();
            })
            .finally(() => {
                if (active) setIsRestoring(false);
            });
        return () => { active = false; };
    }, [clearSession]);

    /** Server-side logout revokes access JWTs and all refresh sessions. */
    const logout = useCallback(async () => {
        try {
            await apiClient.post('/auth/logout');
        } catch {
            // The server may be unreachable or the access token may be expired;
            // clear this page's in-memory session in either case.
        } finally {
            clearSession();
        }
    }, [clearSession]);

    useEffect(() => {
        const handleUnauthorized = () => clearSession();
        window.addEventListener('auth:unauthorized', handleUnauthorized);
        return () => window.removeEventListener('auth:unauthorized', handleUnauthorized);
    }, [clearSession]);

    return (
        <AuthContext.Provider value={{ user, login, logout, isRestoring }}>
            {children}
        </AuthContext.Provider>
    );
};

export const useAuth = () => useContext(AuthContext);
