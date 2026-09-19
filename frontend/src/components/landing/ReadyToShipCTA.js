import React from 'react';
import { Link } from 'react-router-dom';

const ReadyToShipCTA = ({ onRequestDelivery }) => {
    return (
        <section className="ready-to-ship-section">
            <div className="page-container ready-to-ship-container">
                <div className="cta-glass-card text-center">
                    <div className="section-eyebrow section-eyebrow-dark mb-3">
                        <span>Get Started</span>
                    </div>
                    <h2 className="fw-bold text-white mb-3" style={{ fontSize: 'clamp(2rem, 3.8vw, 2.8rem)', letterSpacing: '-0.03em' }}>
                        Ready to Move Your Goods?
                    </h2>
                    <p className="lead text-light opacity-75 col-lg-8 mx-auto mb-4" style={{ fontSize: '1.05rem', lineHeight: '1.6' }}>
                        Create a delivery request in minutes and let our operations team handle the rest.
                    </p>

                    <div className="cta-actions">
                        <button onClick={onRequestDelivery} className="btn-brand-primary px-4 py-3 fw-bold">
                            Request Delivery →
                        </button>
                        <Link to="/register-client" className="btn btn-outline-light px-4 py-3 fw-semibold cta-secondary-action">
                            Open Business Account →
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
};

export default ReadyToShipCTA;
