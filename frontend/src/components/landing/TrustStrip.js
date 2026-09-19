import React from 'react';

const TrustStrip = () => {
    return (
        <section className="trust-strip-section">
            <div className="page-container">
                <div className="glass-card trust-strip-card">
                    <div className="row g-3 text-center align-items-center">
                        <div className="col-lg-3 col-sm-6 trust-col">
                            <div className="trust-val">Verified Delivery Partners</div>
                            <div className="trust-lbl">Reviewed before dispatch access</div>
                        </div>

                        <div className="col-lg-3 col-sm-6 trust-col">
                            <div className="trust-val">GPS-Enabled Tracking</div>
                            <div className="trust-lbl">Clear shipment milestones</div>
                        </div>

                        <div className="col-lg-3 col-sm-6 trust-col">
                            <div className="trust-val">Digital Delivery Confirmation</div>
                            <div className="trust-lbl">Transparent delivery records</div>
                        </div>

                        <div className="col-lg-3 col-sm-6 trust-col">
                            <div className="trust-val">Centralized Operations</div>
                            <div className="trust-lbl">Support from request to delivery</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
};

export default TrustStrip;
