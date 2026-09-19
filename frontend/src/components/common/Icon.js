const paths = {
    menu: 'M4 6h16M4 12h16M4 18h16',
    refresh: 'M20 11a8 8 0 0 0-14.9-3M4 5v4h4M4 13a8 8 0 0 0 14.9 3M20 19v-4h-4',
    rotate: 'M20 11a8 8 0 1 0 2 5.3M20 5v6h-6',
    sun: 'M12 3v2M12 19v2M5.64 5.64l1.42 1.42M16.94 16.94l1.42 1.42M3 12h2M19 12h2M5.64 18.36l1.42-1.42M16.94 7.06l1.42-1.42M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0Z',
    moon: 'M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5Z',
    contrast: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Zm0 0v18',
    eye: 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Zm10 2.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z',
    'eye-off': 'm3 3 18 18M10.6 5.2A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-3.2 3.9M6.2 6.3C3.5 8.1 2 12 2 12s3.5 7 10 7a10.7 10.7 0 0 0 3.2-.5',
    download: 'M12 3v12m0 0 4-4m-4 4-4-4M5 21h14',
};

const Icon = ({ name, size = 18, className = '' }) => (
    <svg
        aria-hidden="true"
        className={`ui-icon ${className}`.trim()}
        fill="none"
        focusable="false"
        height={size}
        viewBox="0 0 24 24"
        width={size}
    >
        <path d={paths[name] || paths.menu} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" />
    </svg>
);

export default Icon;