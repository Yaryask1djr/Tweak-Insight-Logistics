import React from 'react';
import toast, { Toaster } from 'react-hot-toast';

export const showToast = {
    success: (msg, options = {}) => toast.success(msg, {
        duration: 4000,
        style: {
            background: '#064e3b',
            color: '#ecfdf5',
            borderRadius: '8px',
            padding: '12px 16px',
            fontSize: '14px',
            fontWeight: '600',
            boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.2)'
        },
        iconTheme: {
            primary: '#10b981',
            secondary: '#ffffff'
        },
        ...options
    }),
    error: (msg, options = {}) => toast.error(msg, {
        duration: 5000,
        style: {
            background: '#7f1d1d',
            color: '#fef2f2',
            borderRadius: '8px',
            padding: '12px 16px',
            fontSize: '14px',
            fontWeight: '600',
            boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.2)'
        },
        iconTheme: {
            primary: '#ef4444',
            secondary: '#ffffff'
        },
        ...options
    }),
    info: (msg, options = {}) => toast(msg, {
        duration: 4000,
        icon: 'ℹ️',
        style: {
            background: '#1e3a8a',
            color: '#eff6ff',
            borderRadius: '8px',
            padding: '12px 16px',
            fontSize: '14px',
            fontWeight: '600',
            boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.2)'
        },
        ...options
    })
};

export const ToastNotification = () => (
    <Toaster 
        position="top-right" 
        reverseOrder={false}
        toastOptions={{
            duration: 4000
        }}
    />
);

export default ToastNotification;
