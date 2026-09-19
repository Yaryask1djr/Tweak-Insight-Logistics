import React from 'react';

export const KANO_HUBS = [
    'Tarauni', 'Fagge', 'Sabon Gari', 'Nassarawa', 'Bompai', 'Gwale', 'Dala',
    'Challawa', 'Sharada', 'Zoo Road', 'Kantin Kwari', 'Farm Centre', 'Hotoro',
    'Gyadi Gyadi', 'Kurna', 'Dorayi', 'Kabuga', 'Rijiyar Zaki', 'Gadon Kaya'
];

const OUT_OF_AREA_REGEX = /\b(abuja|lagos|ibadan|kaduna|port harcourt|enugu|benin|ilorin|jos|maiduguri|sokoto|zaria|calabar|owerri|warri|asaba|aba)\b/i;

export const validators = {
    fullName: (value) => value.trim().length >= 2 ? '' : 'Enter your full name.',
    email: (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim()) ? '' : 'Enter a valid email address.',
    phone: (value) => value.replace(/\D/g, '').length >= 7 ? '' : 'Enter a valid phone number.',
    password: (value) => value.length >= 8 ? '' : 'Use at least 8 characters.',
    requiredPassword: (value) => value ? '' : 'Enter your password.',
    kanoAddress: (value, required = false) => {
        const trimmed = (value || '').trim();
        if (!trimmed) return required ? 'Enter your Kano operating address.' : '';
        if (OUT_OF_AREA_REGEX.test(trimmed)) {
            return 'Tweak Insight Logistics operates within Kano State only. Interstate addresses are not supported.';
        }
        if (trimmed.length < 3) {
            return 'Please provide complete street or neighborhood details.';
        }
        return '';
    },
};

export const fieldClassName = (baseClassName, value, touched, error) => {
    if (!touched) return baseClassName;
    return `${baseClassName} ${error ? 'is-invalid' : value ? 'is-valid' : ''}`;
};

export const ValidationFeedback = ({ touched, error, validMessage = 'Looks good.' }) => {
    if (!touched) return null;
    if (error) return <div className="invalid-feedback d-block">{error}</div>;
    return <div className="valid-feedback d-block">✓ {validMessage}</div>;
};

