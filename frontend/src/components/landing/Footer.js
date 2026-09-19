import React from 'react';
import { Link } from 'react-router-dom';
import logoWebp from '../../assets/logo.webp';
import logoPng from '../../assets/logo.png';

const Footer = () => {
    return (
        <footer className="site-footer">
            <div className="page-container">
                <div className="row g-4 justify-content-between align-items-start">
                    {/* Brand / Logo Column */}
                    <div className="col-lg-5 col-md-12 mb-3 mb-lg-0">
                        <div className="mb-3">
                            <picture>
                                <source srcSet={logoWebp} type="image/webp" />
                                <img
                                    src={logoPng}
                                    alt="Tweak Insight Logistics"
                                    loading="lazy"
                                    decoding="async"
                                    style={{ height: '52px', width: 'auto', objectFit: 'contain', filter: 'brightness(1.15)' }}
                                />
                            </picture>
                        </div>
                        <p className="footer-brand-text mb-3" style={{ maxWidth: '340px' }}>
                            Reliable logistics and delivery services across Kano State.
                        </p>
                        <div className="d-flex gap-2 flex-wrap">
                            <span className="footer-badge">📍 Kano Central HQ</span>
                            <span className="footer-badge">⚡ 24/7 Dispatch</span>
                        </div>
                    </div>

                    {/* Navigation Columns Container */}
                    <div className="col-lg-7 col-md-12">
                        <div className="footer-links-grid">
                            {/* COMPANY Column */}
                            <div>
                                <h6 className="footer-heading">Company</h6>
                                <ul className="footer-link-list">
                                    <li><Link to="/about" className="footer-link">About</Link></li>
                                    <li><Link to="/contact" className="footer-link">Contact</Link></li>
                                    <li><Link to="/careers" className="footer-link">Careers</Link></li>
                                </ul>
                            </div>

                            {/* SUPPORT Column */}
                            <div>
                                <h6 className="footer-heading">Support</h6>
                                <ul className="footer-link-list">
                                    <li><Link to="/coverage" className="footer-link">Service Coverage</Link></li>
                                    <li><Link to="/track" className="footer-link">Track Delivery</Link></li>
                                    <li><Link to="/contact" className="footer-link">Help Center</Link></li>
                                </ul>
                            </div>

                            {/* LEGAL Column */}
                            <div>
                                <h6 className="footer-heading">Legal</h6>
                                <ul className="footer-link-list">
                                    <li><Link to="/terms" className="footer-link">Terms of Service</Link></li>
                                    <li><Link to="/terms" className="footer-link">Privacy Policy</Link></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Bottom Copyright Area with Horizontal Divider */}
                <div className="footer-bottom justify-content-center text-center mt-5 pt-4">
                    <div className="footer-copyright">
                        &copy; 2026 Tweak Insight Logistics. All Rights Reserved.
                    </div>
                </div>
            </div>
        </footer>
    );
};

export default Footer;
