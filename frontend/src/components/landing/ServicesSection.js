import React from 'react';
import { Link } from 'react-router-dom';

const ServicesSection = ({ onRequestDelivery }) => {
    return (
        <section id="services" className="section-spacing">
            <div className="page-container">
                {/* Section Header */}
                <div className="section-header text-center">
                    <div className="section-eyebrow">
                        <span>Our Services</span>
                    </div>
                    <h2 className="section-heading">Everything You Need to Move Goods</h2>
                    <p className="section-lead">
                        Purpose-built delivery options for Kano businesses, online sellers, and individuals.
                    </p>
                </div>

                {/* 2x2 Services Grid Centered */}
                <div className="row g-4 justify-content-center services-grid">
                    {/* Card 1: Same-Day Delivery */}
                    <div className="col-md-6">
                        <div className="glass-card glass-card-hover p-4 h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div className="service-icon-box">⚡</div>
                                <h3 className="service-card-title">Same-Day Delivery</h3>
                                <p className="service-card-desc">
                                    Fast local deliveries within Kano.
                                </p>
                            </div>
                            <div>
                                <button onClick={onRequestDelivery} className="btn service-link p-0">
                                    Request Delivery →
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Card 2: Business Delivery */}
                    <div className="col-md-6">
                        <div className="glass-card glass-card-hover p-4 h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div className="service-icon-box">🏪</div>
                                <h3 className="service-card-title">Business Delivery</h3>
                                <p className="service-card-desc">
                                    Scheduled and recurring deliveries for businesses.
                                </p>
                            </div>
                            <div>
                                <Link to="/register-client" className="service-link">
                                    Open Business Account →
                                </Link>
                            </div>
                        </div>
                    </div>

                    {/* Card 3: E-commerce Logistics */}
                    <div className="col-md-6">
                        <div className="glass-card glass-card-hover p-4 h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div className="service-icon-box">🚛</div>
                                <h3 className="service-card-title">E-commerce Logistics</h3>
                                <p className="service-card-desc">
                                    Fulfillment and last-mile delivery for online sellers.
                                </p>
                            </div>
                            <div>
                                <button onClick={onRequestDelivery} className="btn service-link p-0">
                                    Request Delivery →
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Card 4: Scheduled Delivery */}
                    <div className="col-md-6">
                        <div className="glass-card glass-card-hover p-4 h-100 d-flex flex-column justify-content-between">
                            <div>
                                <div className="service-icon-box">📅</div>
                                <h3 className="service-card-title">Scheduled Delivery</h3>
                                <p className="service-card-desc">
                                    Planned deliveries for customers who do not require immediate service.
                                </p>
                            </div>
                            <div>
                                <button onClick={onRequestDelivery} className="btn service-link p-0">
                                    Schedule Delivery →
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
};

export default ServicesSection;
