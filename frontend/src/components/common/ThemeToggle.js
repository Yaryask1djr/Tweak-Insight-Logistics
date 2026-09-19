import { useTheme } from '../ThemeContext';
import Icon from './Icon';

const ThemeToggle = ({ compact = false }) => {
    const { theme, toggleTheme } = useTheme();
    const changingTo = theme === 'dark' ? 'light' : 'dark';

    return (
        <button
            type="button"
            className={`theme-toggle ${compact ? 'theme-toggle-compact' : ''}`}
            onClick={toggleTheme}
            aria-label={`Switch to ${changingTo} mode`}
            title={`Switch to ${changingTo} mode`}
        >
            <Icon name={theme === 'dark' ? 'sun' : 'moon'} />
            {!compact && <span>{theme === 'dark' ? 'Light mode' : 'Dark mode'}</span>}
        </button>
    );
};

export default ThemeToggle;
