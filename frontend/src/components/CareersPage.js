import React from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Navbar from './landing/Navbar';
import Footer from './landing/Footer';
import { useAuth } from './AuthContext';

const opportunityAreas = [
    {
        icon: '📡',
        title: 'Delivery Operations',
        description: 'Coordinate dispatch queues, manage route schedules, and monitor order turnaround times across central Kano.'
    },
    {
        icon: '🛵',
        title: 'Delivery Partners / Drivers',
        description: 'Verified motorcycle, tricycle, and van partners handling pickup, secure transit, and proof-of-delivery handovers.'
    },
    {
        icon: '🎧',
        title: 'Customer Support',
        description: 'Assist clients with shipment tracking inquiries, order modifications, and prompt resolution of delivery queries.'
    },
    {
        icon: '💼',
        title: 'Business Development',
        description: 'Build partnerships with Kano wholesale merchants, e-commerce stores, manufacturers, and corporate clients.'
    },
    {
        icon: '💻',
        title: 'Technology & Software',
        description: 'Develop and maintain resilient dispatch systems, GPS telemetry, client web portals, and driver interfaces.'
    },
    {
        icon: '📋',
        title: 'Operations Administration',
        description: 'Oversee compliance, partner KYC document verification, billing reconciliation, and operational audit records.'
    }
];

const coreValues = [
    'Reliability',
    'Professionalism',
    'Accountability',
    'Clear Communication',
    'Problem Solving',
    'Teamwork',
    'Customer Service Focus'
];

const CareersPage = () => {
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
                    <div className="text-center mb-5" style={{ maxWidth: '800px', margin: '0 auto' }}>
                        <div className="section-eyebrow mb-2">
                            <span>Join Our Team</span>
                        </div>
                        <h1 className="fw-bold display-6 text-dark mt-2 mb-3">
                            Careers at Tweak Insight Logistics
                        </h1>
                        <p className="text-muted fs-6 lh-base mb-0">
                            We are building a more organized and dependable way to move goods across Kano State. As we grow, we are looking for responsible, motivated, and service-oriented people who want to contribute to the future of local logistics.
                        </p>
                    </div>

                    {/* Section: Opportunities */}
                    <div className="mb-5">
                        <div className="text-center mb-4">
                            <h2 className="h4 fw-bold text-dark mb-1">Opportunities</h2>
                            <p className="text-muted small">Potential opportunities exist across key operational and business functions:</p>
                        </div>

                        <div className="row g-4">
                            {opportunityAreas.map((opp, idx) => (
                                <div key={idx} className="col-md-6 col-lg-4">
                                    <div className="card h-100 border-0 shadow-sm custom-card p-4">
                                        <div className="fs-3 mb-2">{opp.icon}</div>
                                        <h3 className="h6 fw-bold text-dark mb-2">{opp.title}</h3>
                                        <p className="text-muted small mb-0 lh-base">{opp.description}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Section: Who We Look For */}
                    <div className="card border-0 shadow-sm custom-card p-4 p-md-5 mb-5 bg-white">
                        <div className="text-center mb-4" style={{ maxWidth: '720px', margin: '0 auto' }}>
                            <h2 className="h4 fw-bold text-dark mb-2">Who We Look For</h2>
                            <p className="text-muted fs-6 mb-0">
                                We value reliability, professionalism, accountability, communication, problem-solving, teamwork, and a strong commitment to customer service.
                            </p>
                        </div>
                        <div className="d-flex flex-wrap justify-content-center gap-2">
                            {coreValues.map((val, idx) => (
                                <span key={idx} className="badge bg-light text-dark border px-3 py-2 fs-6 fw-semibold rounded-pill">
                                    ✓ {val}
                                </span>
                            ))}
                        </div>
                    </div>

                    {/* Section: Interested in Joining Us? CTA */}
                    <div className="card border-0 shadow-sm custom-card text-center p-5 bg-white">
                        <div className="rounded-circle bg-danger-subtle text-danger d-inline-flex align-items-center justify-content-center mx-auto mb-3" style={{ width: '56px', height: '56px', fontSize: '24px' }}>
                            ✉️
                        </div>
                        <h3 className="h4 fw-bold text-dark mb-2">Interested in Joining Us?</h3>
                        <p className="text-muted mb-4" style={{ maxWidth: '640px', margin: '0 auto' }}>
                            We do not have any publicly listed vacancies at the moment. Check back later or contact our team to express your interest.
                        </p>
                        <div className="d-flex justify-content-center gap-3 flex-wrap">
                            <Link to="/contact" className="btn btn-primary px-4 py-2 fw-semibold">
                                Contact Us About Opportunities →
                            </Link>
                            <Link to="/register-delivery" className="btn btn-outline-secondary px-4 py-2 fw-semibold">
                                Apply as a Delivery Partner
                            </Link>
                        </div>
                    </div>
                </div>
            </main>

            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

export default CareersPage;
