import React from 'react';
import { Link } from 'react-router-dom';

const HeroSection = ({ onRequestDelivery }) => {
    return (
        <section className="hero-wrapper">
            <div className="page-container hero-content text-center d-flex flex-column align-items-center">
                {/* Eyebrow Badge */}
                <div className="section-eyebrow mx-auto" style={{ marginBottom: '20px' }}>
                    <span className="beacon-dot"></span>
                    <span>Kano Logistics Network</span>
                </div>

                {/* Primary Headline */}
                <h1 className="hero-headline text-center">
                    Fast, Reliable Delivery Across{' '}
                    <span className="highlight">Kano State</span>
                </h1>

                {/* Primary Supporting Statement */}
                <p className="hero-lead mx-auto text-center">
                    Same-day and scheduled delivery services for businesses and individuals, managed by a centralized operations team and verified delivery partners.
                </p>
                
                {/* Primary CTAs */}
                <div className="hero-cta-group justify-content-center">
                    <button onClick={onRequestDelivery} className="btn-brand-primary hero-primary-cta">
                        <span aria-hidden="true">🚚</span>
                        Request Delivery →
                    </button>
                    <Link to="/register-client" className="btn-brand-secondary hero-secondary-cta">
                        Open client account
                    </Link>
                </div>

                <p className="hero-signin-prompt text-center">
                    Already shipping with us? <Link to="/login">Sign in to track and manage deliveries</Link>
                </p>

                <p className="hero-positioning text-center">Local expertise. Professional logistics infrastructure.</p>

            </div>
        </section>
    );
};

export default HeroSection;
