import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Navbar from './landing/Navbar';
import Footer from './landing/Footer';
import { useAuth } from './AuthContext';

const AboutPage = () => {
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
                    <div className="text-center mb-5" style={{ maxWidth: '820px', margin: '0 auto' }}>
                        <div className="section-eyebrow mb-2">
                            <span>Company Profile</span>
                        </div>
                        <h1 className="fw-bold display-6 text-dark mt-2 mb-3">
                            About Tweak Insight Logistics
                        </h1>
                        <p className="text-muted fs-6 lh-base mb-3">
                            Tweak Insight Logistics is a locally focused logistics and delivery company built to make moving goods across Kano State simpler, more reliable, and more accountable. We connect customers and businesses with an organized delivery operation that coordinates requests, dispatch, verified delivery partners, shipment tracking, and proof of delivery through a structured process.
                        </p>
                        <p className="text-muted fs-6 lh-base mb-0">
                            Our focus is on providing dependable delivery services for individuals, businesses, e-commerce sellers, and organizations across Kano State. Whether it is a same-day delivery, scheduled shipment, business delivery, or e-commerce order, our goal is to make every delivery easier to request, easier to monitor, and easier to trust.
                        </p>
                    </div>

                    {/* 4 Pillar Cards Grid */}
                    <div className="row g-4 mb-5">
                        {/* Card 1: What We Do */}
                        <div className="col-md-6">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4 p-md-5">
                                <div className="d-flex align-items-center gap-3 mb-3">
                                    <div className="rounded-3 bg-danger-subtle text-danger p-3 fs-4 lh-1">
                                        📦
                                    </div>
                                    <h3 className="h5 fw-bold text-dark mb-0">What We Do</h3>
                                </div>
                                <p className="text-muted mb-0 lh-base">
                                    We coordinate local deliveries from pickup to final handover. Customers submit delivery requests, our operations team reviews and coordinates the shipment, an appropriate delivery partner is assigned, and the shipment can be monitored using its tracking reference. Delivery completion is documented to provide a clear record of the transaction.
                                </p>
                            </div>
                        </div>

                        {/* Card 2: Our Mission */}
                        <div className="col-md-6">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4 p-md-5">
                                <div className="d-flex align-items-center gap-3 mb-3">
                                    <div className="rounded-3 bg-danger-subtle text-danger p-3 fs-4 lh-1">
                                        🎯
                                    </div>
                                    <h3 className="h5 fw-bold text-dark mb-0">Our Mission</h3>
                                </div>
                                <p className="text-muted mb-0 lh-base">
                                    To provide accessible, dependable, and accountable delivery services that help people and businesses move goods efficiently across Kano State.
                                </p>
                            </div>
                        </div>

                        {/* Card 3: Our Vision */}
                        <div className="col-md-6">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4 p-md-5">
                                <div className="d-flex align-items-center gap-3 mb-3">
                                    <div className="rounded-3 bg-danger-subtle text-danger p-3 fs-4 lh-1">
                                        🔭
                                    </div>
                                    <h3 className="h5 fw-bold text-dark mb-0">Our Vision</h3>
                                </div>
                                <p className="text-muted mb-0 lh-base">
                                    To become a trusted local logistics infrastructure for Kano, enabling businesses and customers to move goods with greater convenience, transparency, and confidence.
                                </p>
                            </div>
                        </div>

                        {/* Card 4: Our Commitment */}
                        <div className="col-md-6">
                            <div className="card h-100 border-0 shadow-sm custom-card p-4 p-md-5">
                                <div className="d-flex align-items-center gap-3 mb-3">
                                    <div className="rounded-3 bg-danger-subtle text-danger p-3 fs-4 lh-1">
                                        🤝
                                    </div>
                                    <h3 className="h5 fw-bold text-dark mb-0">Our Commitment</h3>
                                </div>
                                <p className="text-muted mb-0 lh-base">
                                    We are committed to operational accountability, clear communication, responsible handling of shipments, reliable delivery coordination, and continuously improving the experience for both customers and business clients.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Bottom CTA Card */}
                    <div className="card border-0 shadow-sm custom-card text-center p-5 bg-white">
                        <h4 className="fw-bold text-dark mb-2">Ready to Experience Dependable Kano Logistics?</h4>
                        <p className="text-muted mb-4" style={{ maxWidth: '600px', margin: '0 auto' }}>
                            Join hundreds of businesses and individuals who count on Tweak Insight Logistics for daily deliveries.
                        </p>
                        <div className="d-flex justify-content-center gap-3 flex-wrap">
                            <button onClick={handleRequestDelivery} className="btn btn-primary px-4 py-2 fw-semibold">
                                Request a Delivery →
                            </button>
                            <Link to="/register-client" className="btn btn-outline-secondary px-4 py-2 fw-semibold">
                                Open Business Account
                            </Link>
                        </div>
                    </div>
                </div>
            </main>

            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

export default AboutPage;
