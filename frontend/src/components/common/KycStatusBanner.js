import VerifiedBadge from './VerifiedBadge';

const STATUS_COPY = {
    not_submitted: { variant: 'warning', title: 'KYC verification required', text: 'Your account is awaiting KYC verification. Upload your required documents to continue.', action: 'Complete KYC' },
    submitted: { variant: 'info', title: 'KYC submitted', text: 'Your documents are safely received and awaiting operations review.', action: 'View KYC' },
    under_review: { variant: 'info', title: 'KYC under review', text: 'Operations is reviewing your identity documents.', action: 'View KYC' },
    verified: { variant: 'success', title: 'Identity verified', text: 'Your account is cleared for KYC-protected features.', action: 'View KYC' },
    rejected: { variant: 'danger', title: 'KYC needs attention', text: 'Review the feedback and upload an updated document to resubmit.', action: 'Update KYC' },
};

const KycStatusBanner = ({ status = 'not_submitted', rejectionReason, onAction, className = '' }) => {
    const copy = STATUS_COPY[status] || STATUS_COPY.not_submitted;
    return <div className={`alert alert-${copy.variant} d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mb-3 ${className}`} role="status">
        <div><div className="fw-bold">{copy.title} {status === 'verified' && <VerifiedBadge status={status} />}</div><div className="small">{rejectionReason && status === 'rejected' ? rejectionReason : copy.text}</div></div>
        {onAction && <button type="button" className={`btn btn-sm btn-outline-${copy.variant === 'warning' ? 'dark' : copy.variant}`} onClick={onAction}>{copy.action}</button>}
    </div>;
};

export default KycStatusBanner;
