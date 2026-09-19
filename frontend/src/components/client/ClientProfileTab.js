import React from 'react';
import VerifiedBadge from '../common/VerifiedBadge';

const ClientProfileTab = ({ user, kyc, onOpenKyc }) => {
    return (
        <div className="card border-0 shadow-sm custom-card p-4 client-profile-tab">
            <div className="d-flex justify-content-between align-items-center mb-4 border-bottom pb-3">
                <div>
                    <h4 className="fw-bold mb-1">👤 Client Profile & Account Settings</h4>
                    <p className="text-muted small mb-0">Manage your contact details, service preferences, and verification status.</p>
                </div>
                <VerifiedBadge status={kyc?.kyc_status} label="KYC Status" />
            </div>

            <div className="row g-4">
                {/* Account Details Card */}
                <div className="col-md-6">
                    <div className="bg-light p-3 rounded border h-100">
                        <h6 className="fw-bold text-primary mb-3">Personal Information</h6>
                        <div className="mb-2">
                            <small className="text-muted d-block">Full Name</small>
                            <strong>{user?.full_name || 'N/A'}</strong>
                        </div>
                        <div className="mb-2">
                            <small className="text-muted d-block">Email Address</small>
                            <span>{user?.email || 'N/A'}</span>
                        </div>
                        <div className="mb-2">
                            <small className="text-muted d-block">Phone Number</small>
                            <span>{user?.phone || 'N/A'}</span>
                        </div>
                        <div className="mb-0">
                            <small className="text-muted d-block">Account Type</small>
                            <span className="badge bg-secondary text-capitalize">{user?.role || 'Client'}</span>
                        </div>
                    </div>
                </div>

                {/* Operations & Location Card */}
                <div className="col-md-6">
                    <div className="bg-light p-3 rounded border h-100">
                        <h6 className="fw-bold text-success mb-3">Service & Operational Zone</h6>
                        <div className="mb-2">
                            <small className="text-muted d-block">Primary Operating Region</small>
                            <strong>Kano Metropolitan Hub</strong>
                        </div>
                        <div className="mb-2">
                            <small className="text-muted d-block">Saved Default Address</small>
                            <span>{user?.address || 'No default address saved'}</span>
                        </div>
                        <div className="mb-2">
                            <small className="text-muted d-block">Identity Verification</small>
                            <span className="text-capitalize">{kyc?.kyc_status?.replace('_', ' ') || 'Not Submitted'}</span>
                        </div>
                        <div className="mt-3">
                            <button className="btn btn-sm btn-outline-primary" onClick={onOpenKyc}>
                                View / Update KYC Documents →
                            </button>
                        </div>
                    </div>
                </div>

                {/* Logistics Support & Help */}
                <div className="col-12">
                    <div className="alert alert-info py-3 mb-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <strong>Need Business Fleet Rates or Corporate Billing?</strong>
                            <p className="small mb-0 text-muted">
                                If you ship in bulk across Kano markets, reach out to central operations for customized rate cards and dedicated dispatch priority.
                            </p>
                        </div>
                        <a href="/#contact" className="btn btn-sm btn-primary">
                            Contact Operations
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ClientProfileTab;
