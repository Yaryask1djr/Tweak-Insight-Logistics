import { useState } from 'react';
import Icon from './Icon';

const maskValue = (value, kind) => {
    const text = String(value || '');
    if (!text) return 'Not provided';

    if (kind === 'email') {
        const [local, domain] = text.split('@');
        if (!domain) return '***';
        return `${local.slice(0, 1)}***@${domain}`;
    }

    if (kind === 'phone') {
        const digits = text.replace(/\D/g, '');
        return digits.length > 2 ? `*** *** ${digits.slice(-2)}` : '***';
    }

    return `${text.slice(0, 1)}***`;
};

const SensitiveValue = ({ value, kind = 'text', label = 'sensitive value' }) => {
    const [revealed, setRevealed] = useState(false);
    const hasValue = Boolean(value);

    if (!hasValue) return <span className="text-muted">Not provided</span>;

    return (
        <span className="sensitive-value">
            <span>{revealed ? value : maskValue(value, kind)}</span>
            <button
                type="button"
                className="sensitive-value__toggle"
                onClick={() => setRevealed(current => !current)}
                aria-label={`${revealed ? 'Hide' : 'Reveal'} ${label}`}
                title={`${revealed ? 'Hide' : 'Reveal'} ${label}`}
            >
                <Icon name={revealed ? 'eye-off' : 'eye'} size={14} />
            </button>
        </span>
    );
};

export default SensitiveValue;