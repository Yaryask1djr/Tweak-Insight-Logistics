import React from 'react';

const HowItWorksSection = () => {
    const steps = [
        {
            icon: '📝',
            title: 'Create Request',
            description: 'Share your pickup, destination, and package details.'
        },
        {
            icon: '📡',
            title: 'Review',
            description: 'Our operations team reviews and coordinates your request.'
        },
        {
            icon: '🛵',
            title: 'Driver & Tracking',
            description: 'A verified driver is assigned and shipment progress is visible.'
        },
        {
            icon: '🔐',
            title: 'Confirmation',
            description: 'Digital proof of delivery keeps the outcome on record.'
        }
    ];

    return (
        <section id="how-it-works" className="section-spacing bg-white border-top border-bottom">
            <div className="page-container">
                {/* Section Header */}
                <div className="section-header text-center">
                    <h2 className="section-heading">How Tweak Insight Logistics Works</h2>
                    <p className="section-lead">
                        A simple, accountable delivery lifecycle managed locally in Kano.
                    </p>
                </div>

                {/* 4-Step Operational Grid */}
                <div className="row g-4 workflow-grid">
                    {steps.map((step, idx) => (
                        <div key={idx} className="col-lg-3 col-md-6">
                            <div className="glass-card step-card glass-card-hover">
                                <div className="workflow-number">0{idx + 1}</div>
                                <div className="mb-3">
                                    <span className="fs-3">{step.icon}</span>
                                </div>
                                <h3 className="step-card-title">{step.title}</h3>
                                <p className="step-card-desc">{step.description}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
};

export default HowItWorksSection;
