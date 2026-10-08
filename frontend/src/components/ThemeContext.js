import { createContext, useContext, useEffect, useMemo, useState } from 'react';

const ThemeContext = createContext(null);
const STORAGE_KEY = 'til-theme';

const preferredTheme = () => {
    let saved;
    try { saved = localStorage.getItem(STORAGE_KEY); } catch { /* Storage can be disabled by the browser. */ }
    if (saved === 'light' || saved === 'dark') return saved;
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

export const ThemeProvider = ({ children }) => {
    const [theme, setTheme] = useState(preferredTheme);

    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
        try { localStorage.setItem(STORAGE_KEY, theme); } catch { /* The in-memory theme remains usable. */ }
    }, [theme]);

    const value = useMemo(() => ({
        theme,
        toggleTheme: () => setTheme(current => current === 'dark' ? 'light' : 'dark'),
    }), [theme]);

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
};

export const useTheme = () => {
    const context = useContext(ThemeContext);
    if (!context) throw new Error('useTheme must be used within ThemeProvider.');
    return context;
};
