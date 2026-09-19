import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Navbar from './landing/Navbar';
import Footer from './landing/Footer';
import { useAuth } from './AuthContext';

const ContactPage = () => {
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
                <div className="page-container" style={{ maxWidth: '1080px' }}>
                    {/* Header Section */}
                    <div className="text-center mb-5" style={{ maxWidth: '760px', margin: '0 auto' }}>
                        <div className="section-eyebrow mb-2">
                            <span>Get in Touch</span>
                        </div>
                        <h1 className="fw-bold display-6 text-dark mt-2 mb-3">
                            Contact Tweak Insight Logistics
                        </h1>
                        <p className="text-muted fs-6 lh-base mb-0">
                            Have questions about deliveries, business account partnerships, or career inquiries? Reach out to our central Kano operations team.
                        </p>
                    </div>

                    {/* Contact Cards Grid */}
                    <div className="row g-4 mb-5">
                        {/* Card 1: Central Operations HQ */}
                        <div className="col-md-6 col-lg-3">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4">
                                <div className="fs-3 mb-2">📍</div>
                                <h3 className="h6 fw-bold text-dark mb-1">Central Kano HQ</h3>
                                <p className="text-muted small mb-0 lh-base">
                                    Central Business District, Kano State, Nigeria.
                                </p>
                            </div>
                        </div>

                        {/* Card 2: Dispatch Support */}
                        <div className="col-md-6 col-lg-3">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4">
                                <div className="fs-3 mb-2">📞</div>
                                <h3 className="h6 fw-bold text-dark mb-1">Direct Phone</h3>
                                <p className="text-muted small mb-0 lh-base">
                                    +234 8060431696<br />
                                    <span className="text-success fw-semibold">24/7 Operations Dispatch</span>
                                </p>
                            </div>
                        </div>

                        {/* Card 3: Email Inquiries */}
                        <div className="col-md-6 col-lg-3">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4">
                                <div className="fs-3 mb-2">✉️</div>
                                <h3 className="h6 fw-bold text-dark mb-1">Email Inquiries</h3>
                                <p className="text-muted small mb-0 lh-base">
                                    support@tweakinsight.com<br />
                                    dispatch@tweakinsight.com
                                </p>
                            </div>
                        </div>

                        {/* Card 4: Operating Hours */}
                        <div className="col-md-6 col-lg-3">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4">
                                <div className="fs-3 mb-2">⏱</div>
                                <h3 className="h6 fw-bold text-dark mb-1">Operating Hours</h3>
                                <p className="text-muted small mb-0 lh-base">
                                    Mon – Sun: 7:00 AM – 9:00 PM<br />
                                    Sun: On-demand dispatch
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Inquiry Guidance Box */}
                    <div className="card border-0 shadow-sm custom-card p-4 p-md-5 bg-white mb-5">
                        <div className="row g-4 align-items-center">
                            <div className="col-lg-8">
                                <h3 className="h5 fw-bold text-dark mb-2">Need Immediate Shipment Assistance?</h3>
                                <p className="text-muted mb-0">
                                    If you have an active package in transit, you can track it live using your tracking ID or contact the assigned partner directly through your client dashboard.
                                </p>
                            </div>
                            <div className="col-lg-4 d-flex justify-content-lg-end gap-2 flex-wrap">
                                <Link to="/track" className="btn btn-primary px-4 py-2 fw-semibold">
                                    Track Delivery →
                                </Link>
                                <button onClick={handleRequestDelivery} className="btn btn-outline-secondary px-4 py-2 fw-semibold">
                                    New Delivery
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

export default ContactPage;
