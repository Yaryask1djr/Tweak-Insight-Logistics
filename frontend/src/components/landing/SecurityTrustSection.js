import React from 'react';

const SecurityTrustSection = () => {
    const securityCards = [
        {
            icon: '🛡️',
            title: 'Verified Delivery Partners',
            description: 'All drivers undergo strict identity verification, driver licence review, and vehicle screening before gaining dispatch access.'
        },
        {
            icon: '📍',
            title: 'Real-Time Tracking',
            description: 'Monitor shipment progress in real time using unique tracking IDs and live milestone status updates.'
        },
        {
            icon: '🔐',
            title: 'Digital Proof of Delivery',
            description: 'Deliveries require recipient confirmation via secure OTP verification, providing indisputable proof of receipt.'
        },
        {
            icon: '💼',
            title: 'Secure Client Accounts',
            description: 'Business clients access centralized dashboards with complete order logs, pricing transparency, and billing records.'
        }
    ];

    return (
        <section className="section-spacing">
            <div className="page-container">
                <div className="section-header text-center">
                    <div className="section-eyebrow"><span>Operational confidence</span></div>
                    <h2 className="section-heading">Trusted Delivery Infrastructure</h2>
                    <p className="section-lead">
                        Client → Operations → Verified Driver → GPS Tracking → Proof of Delivery
                    </p>
                </div>

                <div className="row g-4 infrastructure-flow">
                    {securityCards.map((card, idx) => (
                        <div key={idx} className="col-lg-3 col-md-6">
                            <div className="glass-card trust-security-card glass-card-hover">
                                <div className="trust-security-icon">{card.icon}</div>
                                <h3 className="trust-security-title">{card.title}</h3>
                                <p className="trust-security-desc">{card.description}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
};

export default SecurityTrustSection;
