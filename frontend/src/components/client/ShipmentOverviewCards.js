import React from 'react';

const ShipmentOverviewCards = ({ deliveries = [], activeStatusFilter = 'all', onSelectFilter }) => {
    // Categorize deliveries
    const activeCount = deliveries.filter(d =>
        ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'].includes(d.status)
    ).length;

    const deliveredCount = deliveries.filter(d =>
        ['delivered', 'completed'].includes(d.status)
    ).length;

    const pendingCount = deliveries.filter(d =>
        ['pending', 'under_review', 'broadcasted'].includes(d.status)
    ).length;

    const cancelledCount = deliveries.filter(d =>
        ['cancelled', 'rejected', 'failed'].includes(d.status)
    ).length;

    const totalCost = deliveries.reduce((acc, d) => acc + (Number(d.total_cost) || 0), 0);

    const cards = [
        {
            id: 'active',
            title: 'Active Shipments',
            count: activeCount,
            icon: '🚚',
            badgeClass: 'bg-primary-subtle text-primary border border-primary-subtle',
            borderClass: activeStatusFilter === 'active' ? 'border-primary shadow' : 'border-0 shadow-sm',
            subtitle: activeCount > 0 ? `${activeCount} in transit or dispatched` : 'No shipments currently en route',
        },
        {
            id: 'delivered',
            title: 'Delivered & Complete',
            count: deliveredCount,
            icon: '✅',
            badgeClass: 'bg-success-subtle text-success border border-success-subtle',
            borderClass: activeStatusFilter === 'delivered' ? 'border-success shadow' : 'border-0 shadow-sm',
            subtitle: deliveredCount > 0 ? `${deliveredCount} successfully delivered` : 'None delivered yet',
        },
        {
            id: 'pending',
            title: 'Pending Dispatch',
            count: pendingCount,
            icon: '⏳',
            badgeClass: 'bg-warning-subtle text-dark border border-warning-subtle',
            borderClass: activeStatusFilter === 'pending' ? 'border-warning shadow' : 'border-0 shadow-sm',
            subtitle: pendingCount > 0 ? `${pendingCount} awaiting rider match` : 'All requests processed',
        },
        {
            id: 'cancelled',
            title: 'Exceptions / Cancelled',
            count: cancelledCount,
            icon: '✕',
            badgeClass: 'bg-danger-subtle text-danger border border-danger-subtle',
            borderClass: activeStatusFilter === 'cancelled' ? 'border-danger shadow' : 'border-0 shadow-sm',
            subtitle: cancelledCount > 0 ? `${cancelledCount} cancelled or rejected` : '0 delivery exceptions',
        },
    ];

    return (
        <div className="mb-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h5 className="fw-bold mb-0">Shipment Overview</h5>
                    <small className="text-muted">Real-time status breakdown across Kano dispatch channels</small>
                </div>
                {deliveries.length > 0 && (
                    <div className="d-none d-md-block text-end">
                        <small className="text-muted d-block" style={{ fontSize: '0.75rem' }}>Total Logistics Volume</small>
                        <span className="fw-bold text-dark fs-6">
                            ₦{totalCost.toLocaleString()} <span className="text-muted fw-normal" style={{ fontSize: '0.8rem' }}>({deliveries.length} orders)</span>
                        </span>
                    </div>
                )}
            </div>

            <div className="row g-3">
                {cards.map(card => {
                    const isSelected = activeStatusFilter === card.id;
                    return (
                        <div key={card.id} className="col-sm-6 col-xl-3">
                            <div
                                className={`card custom-card p-3 h-100 ${card.borderClass} overview-kpi-card ${isSelected ? 'is-selected' : ''}`}
                                style={{
                                    cursor: 'pointer',
                                    transition: 'all 0.2s ease',
                                }}
                                onClick={() => onSelectFilter(isSelected ? 'all' : card.id)}
                                role="button"
                                tabIndex={0}
                                onKeyDown={e => (e.key === 'Enter' || e.key === ' ') && onSelectFilter(isSelected ? 'all' : card.id)}
                                aria-label={`Filter by ${card.title}`}
                            >
                                <div className="d-flex justify-content-between align-items-start mb-2">
                                    <span className="fs-4" aria-hidden="true">{card.icon}</span>
                                    <span className={`badge rounded-pill ${card.badgeClass}`} style={{ fontSize: '0.75rem' }}>
                                        {card.count}
                                    </span>
                                </div>
                                <h6 className="fw-bold text-dark mb-1" style={{ fontSize: '0.95rem' }}>{card.title}</h6>
                                <div className="display-6 fw-bold mb-1" style={{ fontSize: '1.6rem' }}>
                                    {card.count}
                                </div>
                                <small className="text-muted" style={{ fontSize: '0.78rem' }}>
                                    {card.subtitle}
                                </small>
                                {isSelected && (
                                    <div className="mt-2 pt-2 border-top small text-primary fw-semibold d-flex align-items-center justify-content-between" style={{ fontSize: '0.75rem' }}>
                                        <span>Active Filter</span>
                                        <span>Click to clear ✕</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
};

export default ShipmentOverviewCards;
