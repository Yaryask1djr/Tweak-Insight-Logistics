const VerifiedBadge = ({ status, label = 'Verified' }) => {
    if (status !== 'verified') return null;
    return <span className="badge bg-success-subtle text-success-emphasis border border-success-subtle" title="Identity verification completed">✓ {label}</span>;
};

export default VerifiedBadge;
