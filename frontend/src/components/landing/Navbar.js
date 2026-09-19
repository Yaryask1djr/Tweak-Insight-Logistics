import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import logoWebp from '../../assets/logo.webp';
import logoPng from '../../assets/logo.png';
import ThemeToggle from '../common/ThemeToggle';

const Navbar = ({ onRequestDelivery }) => {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    const closeMobileMenu = () => setMobileMenuOpen(false);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') closeMobileMenu();
        };
        if (mobileMenuOpen) {
            document.body.classList.add('mobile-menu-open');
            window.addEventListener('keydown', onKeyDown);
        }
        return () => {
            document.body.classList.remove('mobile-menu-open');
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [mobileMenuOpen]);

    return (
        <div className="glass-nav-wrapper">
            <a className="skip-link" href="#main-content">Skip to main content</a>
            <nav className="glass-nav">
                {/* Brand Logo — left */}
                <Link to="/" className="brand-logo-link">
                    <picture>
                        <source srcSet={logoWebp} type="image/webp" />
                        <img src={logoPng} alt="Tweak Insight Logistics" className="brand-logo-image" decoding="async" />
                    </picture>
                </Link>

                {/* Navigation Links — perfectly centered via CSS grid */}
                <ul className="nav-links-center">
                    <li><a href="/#services" className="nav-link-item">Services</a></li>
                    <li><a href="/#how-it-works" className="nav-link-item">How It Works</a></li>
                    <li><Link to="/track" className="nav-link-item">Track</Link></li>
                    <li><Link to="/coverage" className="nav-link-item">Coverage</Link></li>
                </ul>

                {/* Right — theme toggle + sign in + mobile hamburger */}
                <div className="nav-actions-right">
                    <ThemeToggle compact />
                    <Link to="/login" className="btn-nav-signin">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10 17 15 12 10 7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                        <span>Sign in</span>
                    </Link>
                    <button
                        type="button"
                        className="mobile-menu-btn"
                        onClick={() => setMobileMenuOpen(open => !open)}
                        aria-label={mobileMenuOpen ? 'Close navigation menu' : 'Open navigation menu'}
                        aria-controls="mobile-site-navigation"
                        aria-expanded={mobileMenuOpen}
                    >
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            {mobileMenuOpen ? (
                                <>
                                    <line x1="18" y1="6" x2="6" y2="18"></line>
                                    <line x1="6" y1="6" x2="18" y2="18"></line>
                                    <line x1="3" y1="18" x2="21" y2="18"></line>
                                </>
                            ) : (
                                <>
                                    <line x1="3" y1="12" x2="21" y2="12"></line>
                                    <line x1="3" y1="6" x2="21" y2="6"></line>
                                    <line x1="3" y1="18" x2="21" y2="18"></line>
                                </>
                            )}
                        </svg>
                    </button>
                </div>
            </nav>

            {/* Mobile Drawer — nav links only */}
            {mobileMenuOpen && (
                <div className="mobile-nav-drawer open" id="mobile-site-navigation">
                    <div className="d-flex flex-column gap-3">
                        <a href="/#services" onClick={closeMobileMenu} className="nav-link-item mobile-nav-link">Services</a>
                        <a href="/#how-it-works" onClick={closeMobileMenu} className="nav-link-item mobile-nav-link">How It Works</a>
                        <Link to="/track" onClick={closeMobileMenu} className="nav-link-item mobile-nav-link">Track</Link>
                        <Link to="/coverage" onClick={closeMobileMenu} className="nav-link-item mobile-nav-link">Coverage</Link>
                        <hr className="my-1 opacity-25" />
                        <Link to="/login" onClick={closeMobileMenu} className="btn-nav-signin mobile-nav-link text-center justify-content-center py-2">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="me-1">
                                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                                <polyline points="10 17 15 12 10 7"></polyline>
                                <line x1="15" y1="12" x2="3" y2="12"></line>
                            </svg>
                            <span>Sign In to Portal</span>
                        </Link>
                    </div>
                </div>
            )}
        </div>
    );
};

export default Navbar;
