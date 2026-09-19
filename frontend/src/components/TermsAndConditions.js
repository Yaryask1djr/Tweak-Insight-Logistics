import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Navbar from './landing/Navbar';
import Footer from './landing/Footer';
import { useAuth } from './AuthContext';

const TermsAndConditions = () => {
    const { user } = useAuth();
    const navigate = useNavigate();

    const handleRequestDelivery = () => {
        if (!user) {
            navigate('/login?redirect=/dashboard');
        } else if (user.role === 'admin') {
            navigate('/admin');
        } else {
            navigate('/dashboard');
        }
    };

    return (
        <div className="min-vh-100 d-flex flex-column bg-light">
            <Navbar onRequestDelivery={handleRequestDelivery} />

            <main id="main-content" className="flex-grow-1 py-5">
                <div className="page-container" style={{ maxWidth: '880px' }}>
                    <div className="mb-4">
                        <Link to="/" className="btn btn-outline-secondary btn-sm mb-3">← Back to Home</Link>
                        <h1 className="fw-bold display-6 text-dark mb-1">Terms of Service & Privacy Policy</h1>
                        <p className="text-muted small">Last updated: August 2026</p>
                    </div>

                    <div className="card border-0 shadow-sm custom-card p-4 p-md-5 bg-white">
                        <h5 className="fw-bold text-dark">1. Service Scope</h5>
                        <p className="text-muted lh-base">
                            Tweak Insight Logistics operates an on-demand intra-city package pickup and delivery platform within Kano State, Nigeria. We coordinate delivery requests with verified local delivery partners.
                        </p>

                        <h5 className="fw-bold text-dark mt-4">2. Pricing & Payments</h5>
                        <p className="text-muted lh-base">
                            Delivery prices are calculated dynamically using base fares, estimated transit distance, cargo weight, and extra fragile/perishable handling surcharges. All fares are denominated in Nigerian Naira (NGN).
                        </p>

                        <h5 className="fw-bold text-dark mt-4">3. Delivery Partner Responsibilities</h5>
                        <p className="text-muted lh-base">
                            Verified delivery partners undergo KYC identity screening and agree to transport cargo safely, maintain active updates regarding order status transitions (Accepted, Picked Up, In Transit, Delivered), and adhere to local road and safety regulations in Kano.
                        </p>

                        <h5 className="fw-bold text-dark mt-4">4. Prohibited Items</h5>
                        <p className="text-muted lh-base">
                            Senders are strictly prohibited from submitting illegal goods, hazardous chemicals, unregistered firearms, or contraband items for dispatch.
                        </p>

                        <h5 className="fw-bold text-dark mt-4">5. Privacy & Data Protection</h5>
                        <p className="text-muted lh-base mb-0">
                            We take stakeholder privacy seriously. Public tracking portals mask sensitive phone numbers and names to prevent unauthorized data scraping. Unmasked contact details are only accessible to verified senders, recipients, and dispatch coordinators.
                        </p>
                    </div>
                </div>
            </main>

            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

export default TermsAndConditions;