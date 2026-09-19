/**
 * useFormValidation — Real-time inline form validation hook
 *
 * Usage:
 *   const { errors, touched, validate, touch, validateAll } = useFormValidation(rules);
 *   - rules: { fieldName: [validator, ...] }
 *   - validate(name, value) — call onChange to live-validate
 *   - touch(name, value)   — call onBlur to mark touched & validate
 *   - validateAll(formObj) — call on submit; returns true if all pass
 */

import { useState, useCallback } from 'react';

// ---------- Built-in validators ----------

export const required = (label = 'This field') => (value) =>
    !value || !value.toString().trim()
        ? `${label} is required.`
        : null;

export const minLength = (min, label = 'This field') => (value) =>
    value && value.length < min
        ? `${label} must be at least ${min} characters.`
        : null;

export const maxLength = (max, label = 'This field') => (value) =>
    value && value.length > max
        ? `${label} must be no more than ${max} characters.`
        : null;

export const isEmail = () => (value) => {
    if (!value) return null;
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
        ? null
        : 'Please enter a valid email address.';
};

export const isPhone = () => (value) => {
    if (!value) return null;
    // Accepts Nigerian numbers: +234..., 080..., 7-15 digits
    return /^(\+?[\d\s\-().]{7,15})$/.test(value.replace(/\s/g, ''))
        ? null
        : 'Enter a valid phone number (e.g. +234 800 000 0000).';
};

export const isStrongPassword = () => (value) => {
    if (!value) return null;
    if (value.length < 8) return 'Password must be at least 8 characters.';
    if (!/[A-Z]/.test(value)) return 'Include at least one uppercase letter (A–Z).';
    if (!/[0-9]/.test(value)) return 'Include at least one number (0–9).';
    return null;
};

export const matches = (getOtherValue, label = 'Values') => (value) =>
    value !== getOtherValue()
        ? `${label} do not match.`
        : null;

// ---------- Internal: run all validators ----------

const runValidators = (value, validators = []) => {
    for (const validator of validators) {
        const error = validator(value);
        if (error) return error;
    }
    return null;
};

// ---------- The hook ----------

const useFormValidation = (rules) => {
    const [errors, setErrors]   = useState({});
    const [touched, setTouched] = useState({});

    // Live-validate one field as user types
    const validate = useCallback((name, value) => {
        if (!rules[name]) return;
        const error = runValidators(value, rules[name]);
        setErrors(prev => ({ ...prev, [name]: error }));
    }, [rules]);

    // Mark field touched on blur (also validates)
    const touch = useCallback((name, value) => {
        setTouched(prev => ({ ...prev, [name]: true }));
        if (rules[name]) {
            const error = runValidators(value, rules[name]);
            setErrors(prev => ({ ...prev, [name]: error }));
        }
    }, [rules]);

    // Validate ALL fields at submit time; returns true if form is valid
    const validateAll = useCallback((formValues) => {
        const newErrors  = {};
        const newTouched = {};
        for (const name of Object.keys(rules)) {
            newTouched[name] = true;
            newErrors[name]  = runValidators(formValues[name] || '', rules[name]);
        }
        setErrors(newErrors);
        setTouched(newTouched);
        return Object.values(newErrors).every(e => !e);
    }, [rules]);

    return { errors, touched, validate, touch, validateAll };
};

export default useFormValidation;
