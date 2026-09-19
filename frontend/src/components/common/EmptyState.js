const EmptyState = ({ icon = '📦', title, children, actionLabel, onAction }) => (
    <div className="empty-state" role="status">
        <div className="empty-state-icon" aria-hidden="true">{icon}</div>
        <h5>{title}</h5>
        {children && <p>{children}</p>}
        {actionLabel && onAction && (
            <button type="button" className="btn btn-primary empty-state-action" onClick={onAction}>
                {actionLabel}
            </button>
        )}
    </div>
);

export default EmptyState;
