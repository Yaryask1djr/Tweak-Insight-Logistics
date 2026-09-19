import React from 'react';
import { Link } from 'react-router-dom';

const CoverageSection = () => {
    return (
        <section id="coverage" className="section-spacing bg-white border-top border-bottom">
            <div className="page-container" style={{ maxWidth: '960px' }}>
                <div className="text-center mb-5">
                    <h2 className="section-heading">Serving Businesses and Customers Across Kano State</h2>
                    <p className="section-lead" style={{ maxWidth: '700px', margin: '0 auto' }}>
                        Comprehensive delivery coverage connecting major commercial markets, industrial corridors, and residential neighborhoods across Kano.
                    </p>
                </div>

                <div className="row g-4 mb-4">
                    <div className="col-md-6">
                        <div className="p-4 rounded-4 bg-light border h-100 d-flex gap-3 align-items-start">
                            <div className="fs-1 text-danger lh-1">📍</div>
                            <div>
                                <h6 className="fw-bold text-dark mb-1">Central Kano Operations</h6>
                                <p className="text-muted small mb-0">
                                    Direct hub dispatching across Kantin Kwari, Sabon Gari, Farm Centre, Bompai, and Challawa with dynamic fare calculation.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="col-md-6">
                        <div className="p-4 rounded-4 bg-light border h-100 d-flex gap-3 align-items-start">
                            <div className="fs-1 text-success lh-1">🛡️</div>
                            <div>
                                <h6 className="fw-bold text-dark mb-1">Guaranteed Delivery Accountability</h6>
                                <p className="text-muted small mb-0">
                                    Every driver is identity-verified, background-checked, and bound to strict operational safety and transit protocols.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="text-center">
                    <Link to="/coverage" className="btn btn-outline-secondary px-4 py-2 fw-semibold">
                        View Detailed Kano Hubs & Coverage →
                    </Link>
                </div>
            </div>
        </section>
    );
};

export default CoverageSection;
