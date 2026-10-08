import React, { useState } from 'react';
import Icon from '../common/Icon';

const ClientOverviewTab = ({
    user,
    kyc,
    deliveries = [],
    loading = false,
    onBook,
    onViewAll,
    onTrack,
    onSelectStatusFilter,
    onNavigateTab
}) => {
    const [quickTrackQuery, setQuickTrackQuery] = useState('');
    const [quickTrackError, setQuickTrackError] = useState('');
    const [showTasklist, setShowTasklist] = useState(true);
    const [dismissedRecs, setDismissedRecs] = useState([]);

    // Categorize deliveries
    const activeDeliveries = deliveries.filter(d =>
        ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'].includes(d.status)
    );
    const pendingDeliveries = deliveries.filter(d =>
        ['pending', 'under_review', 'broadcasted'].includes(d.status)
    );
    const deliveredDeliveries = deliveries.filter(d =>
        ['delivered', 'completed'].includes(d.status)
    );
    const cancelledDeliveries = deliveries.filter(d =>
        ['cancelled', 'rejected', 'failed'].includes(d.status)
    );

    const totalSpend = deliveries.reduce((acc, d) => acc + (Number(d.total_cost) || 0), 0);
    const mostRecentActive = activeDeliveries[0] || null;
    const recentDeliveries = deliveries.slice(0, 5);

    // Dynamic SaaS Onboarding & Operational Tasklist
    const tasks = [
        {
            id: 'kyc',
            title: 'Verify Business / Personal Identity (KYC)',
            desc: 'Upload government-issued credentials to unlock unrestricted courier bookings and immediate dispatch.',
            badge: kyc?.kyc_status === 'verified' ? 'Verified' : (kyc?.kyc_status === 'under_review' ? 'In Review' : 'Action Required'),
            badgeClass: kyc?.kyc_status === 'verified'
                ? 'bg-success-subtle text-success border border-success-subtle'
                : (kyc?.kyc_status === 'under_review' ? 'bg-info-subtle text-info border border-info-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'),
            isCompleted: kyc?.kyc_status === 'verified',
            action: () => onNavigateTab ? onNavigateTab('kyc') : onBook?.(),
            actionLabel: kyc?.kyc_status === 'verified' ? 'View KYC' : 'Verify Identity →',
            icon: '🛡️',
        },
        {
            id: 'book_first',
            title: 'Dispatch Your First Kano Shipment',
            desc: 'Create an on-demand courier or cargo order across Kano metropolitan hubs with dynamic rates.',
            badge: deliveries.length > 0 ? 'Active' : 'Core Setup',
            badgeClass: deliveries.length > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-primary-subtle text-primary border border-primary-subtle',
            isCompleted: deliveries.length > 0,
            action: onBook,
            actionLabel: deliveries.length > 0 ? 'Book Another' : 'Book Delivery →',
            icon: '📦',
        },
        {
            id: 'track_gps',
            title: 'Explore Real-Time GPS Telemetry',
            desc: 'Follow live rider position, straight-line distance, and estimated arrival times on an interactive map.',
            badge: deliveries.some(d => ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered'].includes(d.status)) ? 'Active' : 'Real-Time',
            badgeClass: deliveries.some(d => ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered'].includes(d.status)) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            isCompleted: deliveries.some(d => ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered'].includes(d.status)),
            action: () => onNavigateTab ? onNavigateTab('tracking') : onTrack?.(mostRecentActive || deliveries[0]),
            actionLabel: mostRecentActive ? 'Track Live →' : 'Open Tracking →',
            icon: '📍',
        },
        {
            id: 'security_otp',
            title: 'Digital Proof of Delivery & OTP Handover',
            desc: 'Secure package receipt with 4-digit verification OTP and digital POD signature archiving.',
            badge: deliveries.some(d => ['delivered', 'completed'].includes(d.status)) ? 'Protected' : 'Security',
            badgeClass: deliveries.some(d => ['delivered', 'completed'].includes(d.status)) ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            isCompleted: deliveries.some(d => ['delivered', 'completed'].includes(d.status)),
            action: onViewAll,
            actionLabel: deliveries.some(d => ['delivered', 'completed'].includes(d.status)) ? 'View POD' : 'View Orders →',
            icon: '🔐',
        },
        {
            id: 'billing_invoices',
            title: 'Review Logistics Invoicing & Receipts',
            desc: 'Access downloadable expense statements, automated payment receipts, and delivery breakdown records.',
            badge: totalSpend > 0 ? 'Active' : 'Finance',
            badgeClass: totalSpend > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-info-subtle text-info border border-info-subtle',
            isCompleted: totalSpend > 0,
            action: () => onNavigateTab ? onNavigateTab('payments') : onViewAll?.(),
            actionLabel: 'View Payments →',
            icon: '💳',
        },
    ];

    const completedTasksCount = tasks.filter(t => t.isCompleted).length;
    const taskProgressPercent = Math.round((completedTasksCount / tasks.length) * 100);

    // Curated SaaS Operational Recommendations
    const recommendations = [
        {
            id: 'corridor_speed',
            tag: 'ROUTE INTELLIGENCE',
            tagBadgeClass: 'bg-primary-subtle text-primary border border-primary-subtle',
            icon: '⚡',
            metric: '~25 min avg turnaround',
            title: 'Avoid Afternoon Market Congestion',
            text: 'Wholesale corridors around Kantin Kwari, Sabon Gari, and Fagge peak between 2:00 PM and 5:00 PM. Scheduling dispatches before 1:30 PM yields 30% faster transit.',
            cta: 'Schedule Early Dispatch →',
            action: onBook,
        },
        {
            id: 'bulk_consolidation',
            tag: 'COST SAVINGS',
            tagBadgeClass: 'bg-success-subtle text-success border border-success-subtle',
            icon: '📦',
            metric: 'Save up to 25%',
            title: 'Consolidate Multi-Drop Cargo',
            text: 'Dispatching multiple parcels to the same commercial district? Bundling into one Large Package reduces per-drop base fees by combining routes into single transit trips.',
            cta: 'Book Large Package →',
            action: onBook,
        },
        {
            id: 'fragile_shield',
            tag: 'CARGO PROTECTION',
            tagBadgeClass: 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
            icon: '🛡️',
            metric: '100% Inspected',
            title: 'Enable Fragile / Priority Handling',
            text: 'Transporting electronics, glassware, or delicate documents? Toggle Fragile Handling in Step 2 to assign dedicated motorcycle couriers with secure thermal luggage.',
            cta: 'Configure in Step 2 →',
            action: onBook,
        },
        {
            id: 'expense_invoices',
            tag: 'FINANCE & AUDIT',
            tagBadgeClass: 'bg-info-subtle text-info-emphasis border border-info-subtle',
            icon: '📊',
            metric: '1-Click PDF exports',
            title: 'Export Expense Statements',
            text: 'Track your total logistics spend across Kano State. Export full transaction histories with transaction reference numbers for corporate bookkeeping.',
            cta: 'Open Payments Hub →',
            action: () => onNavigateTab ? onNavigateTab('payments') : onViewAll?.(),
        },
    ].filter(r => !dismissedRecs.includes(r.id));

    // Time of day greeting
    const getGreeting = () => {
        const hour = new Date().getHours();
        if (hour < 12) return 'Good morning';
        if (hour < 17) return 'Good afternoon';
        return 'Good evening';
    };

    const handleQuickTrackSubmit = (e) => {
        e.preventDefault();
        setQuickTrackError('');
        const q = quickTrackQuery.trim();
        if (!q) {
            setQuickTrackError('Please enter a tracking ID or order reference.');
            return;
        }
        // Check in loaded deliveries
        const match = deliveries.find(d =>
            (d.tracking_number && d.tracking_number.toLowerCase() === q.toLowerCase()) ||
            String(d.id) === q ||
            (d.tracking_number && d.tracking_number.toLowerCase().includes(q.toLowerCase()))
        );
        if (match) {
            onTrack?.(match);
        } else {
            // Open tracking modal with item reference
            onTrack?.({ tracking_number: q, id: q });
        }
    };

    const getStatusBadge = (status) => {
        const norm = status ? status.toLowerCase() : '';
        if (['delivered', 'completed'].includes(norm)) {
            return <span className="status-pill status-delivered">✓ Delivered</span>;
        }
        if (['in_transit', 'arrived'].includes(norm)) {
            return <span className="status-pill status-in_transit">🚀 In Transit</span>;
        }
        if (['picked_up', 'assigned', 'driver_en_route'].includes(norm)) {
            return <span className="status-pill status-assigned">🛵 Dispatched</span>;
        }
        if (['pending', 'under_review', 'broadcasted'].includes(norm)) {
            return <span className="status-pill status-pending">⏳ Pending</span>;
        }
        return <span className="status-pill status-cancelled">{status ? status.replaceAll('_', ' ') : 'Unknown'}</span>;
    };

    // Calculate dynamic ETA text
    const getEtaText = (delivery) => {
        if (!delivery) return '';
        if (['delivered', 'completed'].includes(delivery.status)) {
            return 'Delivered';
        }
        if (delivery.status === 'in_transit' || delivery.status === 'arrived') {
            const distance = Number(delivery.distance_km) || 5;
            const estMins = Math.max(10, Math.round(distance * 3.5));
            return `~${estMins} mins away`;
        }
        if (delivery.status === 'picked_up') {
            return 'Picked up, departing shortly';
        }
        if (['assigned', 'driver_en_route'].includes(delivery.status)) {
            return 'Rider assigned, heading to pickup';
        }
        return 'Awaiting rider dispatch';
    };

    return (
        <div className="client-overview-tab">
            {/* Header Greeting */}
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h3 className="fw-bold text-dark mb-1">
                        {getGreeting()}, {user?.full_name ? user.full_name.split(' ')[0] : 'Client'} 👋
                    </h3>
                    <p className="text-muted mb-0" style={{ fontSize: '0.92rem' }}>
                        {activeDeliveries.length > 0
                            ? `You have ${activeDeliveries.length} active shipment${activeDeliveries.length > 1 ? 's' : ''} in transit across Kano.`
                            : 'All your shipments are up to date. Ready to dispatch a new package?'}
                    </p>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <button
                        className="btn btn-primary px-3 py-2 fw-semibold shadow-sm d-flex align-items-center gap-2"
                        onClick={onBook}
                    >
                        <span>📦</span> Book a Delivery
                    </button>
                </div>
            </div>

            {/* KPI Cards Grid */}
            <div className="row g-3 mb-4">
                <div className="col-sm-6 col-xl-3">
                    <div
                        className="card border-0 shadow-sm custom-card p-3 h-100 overview-kpi-card"
                        role="button"
                        tabIndex={0}
                        onClick={() => { onSelectStatusFilter?.('active'); onViewAll?.(); }}
                        style={{ cursor: 'pointer' }}
                    >
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3" aria-hidden="true">🚚</span>
                            <span className="badge rounded-pill bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                En Route
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Active Shipments</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.75rem' }}>
                            {activeDeliveries.length}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            {activeDeliveries.length > 0 ? `${activeDeliveries.length} live packages dispatched` : 'No active parcels en route'}
                        </small>
                    </div>
                </div>

                <div className="col-sm-6 col-xl-3">
                    <div
                        className="card border-0 shadow-sm custom-card p-3 h-100 overview-kpi-card"
                        role="button"
                        tabIndex={0}
                        onClick={() => { onSelectStatusFilter?.('pending'); onViewAll?.(); }}
                        style={{ cursor: 'pointer' }}
                    >
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3" aria-hidden="true">⏳</span>
                            <span className="badge rounded-pill bg-warning-subtle text-dark border border-warning-subtle px-2 py-1">
                                Queue
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Pending Dispatch</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.75rem' }}>
                            {pendingDeliveries.length}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            {pendingDeliveries.length > 0 ? `${pendingDeliveries.length} awaiting rider accept` : '0 requests in queue'}
                        </small>
                    </div>
                </div>

                <div className="col-sm-6 col-xl-3">
                    <div
                        className="card border-0 shadow-sm custom-card p-3 h-100 overview-kpi-card"
                        role="button"
                        tabIndex={0}
                        onClick={() => { onSelectStatusFilter?.('delivered'); onViewAll?.(); }}
                        style={{ cursor: 'pointer' }}
                    >
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3" aria-hidden="true">✅</span>
                            <span className="badge rounded-pill bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                Completed
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Delivered Packages</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.75rem' }}>
                            {deliveredDeliveries.length}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            {deliveredDeliveries.length > 0 ? `${deliveredDeliveries.length} POD certificates issued` : 'None completed yet'}
                        </small>
                    </div>
                </div>

                <div className="col-sm-6 col-xl-3">
                    <div className="card border-0 shadow-sm custom-card p-3 h-100 overview-kpi-card">
                        <div className="d-flex justify-content-between align-items-start mb-2">
                            <span className="fs-3" aria-hidden="true">💳</span>
                            <span className="badge rounded-pill bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1">
                                Spend
                            </span>
                        </div>
                        <h6 className="text-muted small fw-bold mb-1 text-uppercase" style={{ letterSpacing: '0.5px' }}>Logistics Volume</h6>
                        <div className="display-6 fw-bold text-dark mb-1" style={{ fontSize: '1.5rem' }}>
                            ₦{totalSpend.toLocaleString()}
                        </div>
                        <small className="text-muted" style={{ fontSize: '0.8rem' }}>
                            Across {deliveries.length} total shipment order{deliveries.length === 1 ? '' : 's'}
                        </small>
                    </div>
                </div>
            </div>

            {/* SaaS Operational & Setup Tasklist Card */}
            <div className="card border-0 shadow-sm custom-card mb-4 overflow-hidden">
                <div className="p-4 border-bottom bg-body-tertiary d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div className="d-flex align-items-center gap-2 mb-1">
                            <span className="fs-5">📋</span>
                            <h5 className="fw-bold mb-0 text-dark">Action Items & Setup Checklist</h5>
                            <span className={`badge ${taskProgressPercent === 100 ? 'bg-success' : 'bg-primary'} px-2 py-1`}>
                                {completedTasksCount} of {tasks.length} Completed ({taskProgressPercent}%)
                            </span>
                        </div>
                        <p className="text-muted small mb-0">
                            Essential setup and operational milestones to optimize your city-wide Kano dispatch operations.
                        </p>
                    </div>
                    <div className="d-flex align-items-center gap-2">
                        <div className="progress flex-grow-1" style={{ width: '130px', height: '8px' }}>
                            <div
                                className={`progress-bar ${taskProgressPercent === 100 ? 'bg-success' : 'bg-primary'} progress-bar-striped progress-bar-animated`}
                                role="progressbar"
                                style={{ width: `${taskProgressPercent}%` }}
                                aria-valuenow={taskProgressPercent}
                                aria-valuemin="0"
                                aria-valuemax="100"
                            />
                        </div>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary py-1 px-2"
                            onClick={() => setShowTasklist(prev => !prev)}
                            style={{ fontSize: '0.8rem' }}
                        >
                            {showTasklist ? '▲ Hide' : '▼ Show'}
                        </button>
                    </div>
                </div>

                {showTasklist && (
                    <div className="list-group list-group-flush">
                        {tasks.map((task) => (
                            <div
                                key={task.id}
                                className={`list-group-item p-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 ${task.isCompleted ? 'bg-body-secondary-subtle' : ''}`}
                                style={{ transition: 'background-color 0.2s' }}
                            >
                                <div className="d-flex align-items-start gap-3">
                                    <div
                                        className={`rounded-circle d-flex align-items-center justify-content-center flex-shrink-0 mt-1 ${task.isCompleted ? 'bg-success text-white' : 'border border-2 border-secondary-subtle text-muted'}`}
                                        style={{ width: '28px', height: '28px', fontSize: '0.82rem', fontWeight: 'bold' }}
                                    >
                                        {task.isCompleted ? '✓' : ''}
                                    </div>
                                    <div>
                                        <div className="d-flex flex-wrap align-items-center gap-2 mb-1">
                                            <span className={`fw-bold text-dark ${task.isCompleted ? 'text-decoration-line-through opacity-75' : ''}`}>
                                                {task.title}
                                            </span>
                                            <span className={`badge ${task.badgeClass}`} style={{ fontSize: '0.72rem' }}>
                                                {task.badge}
                                            </span>
                                        </div>
                                        <p className="text-muted small mb-0" style={{ maxWidth: '580px', lineHeight: '1.45' }}>
                                            {task.desc}
                                        </p>
                                    </div>
                                </div>
                                <div className="d-flex align-items-center gap-2 flex-shrink-0 align-self-sm-center">
                                    {task.action && (
                                        <button
                                            type="button"
                                            className={`btn btn-sm ${task.isCompleted ? 'btn-outline-secondary' : 'btn-primary'} fw-semibold px-3`}
                                            onClick={task.action}
                                            style={{ fontSize: '0.82rem' }}
                                        >
                                            {task.actionLabel}
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {/* Middle Section: Kano Hero Card + Quick Track Card */}
            <div className="row g-4 mb-4">
                {/* Hero / Quick Booking Promo */}
                <div className="col-lg-7">
                    <div
                        className="card border-0 shadow-sm p-4 h-100 text-white position-relative overflow-hidden"
                        style={{
                            background: 'linear-gradient(135deg, #09101f 0%, #1e293b 60%, #c9182b 120%)',
                            borderRadius: '12px'
                        }}
                    >
                        <div className="position-relative" style={{ zIndex: 2 }}>
                            <span className="badge bg-danger text-white px-3 py-1 mb-2 fw-semibold" style={{ fontSize: '0.78rem' }}>
                                TWEAK EXPRESS LOGISTICS
                            </span>
                            <h4 className="fw-bold mb-2">Your Trusted Delivery Partner in Kano</h4>
                            <p className="text-light-50 small mb-4" style={{ maxWidth: '440px', lineHeight: '1.5' }}>
                                Fast, secure city-wide courier, express bike dispatch, and cargo transport across Kano Metropolis. Real-time GPS tracking and digital Proof of Delivery on every order.
                            </p>
                            <div className="d-flex flex-wrap gap-2">
                                <button className="btn btn-light text-dark fw-bold px-4 py-2" onClick={onBook}>
                                    + Book New Shipment
                                </button>
                                <button className="btn btn-outline-light px-3 py-2" onClick={onViewAll}>
                                    View Past Deliveries
                                </button>
                            </div>
                        </div>
                        {/* Subtle background decoration */}
                        <div
                            className="position-absolute end-0 bottom-0 opacity-10 text-white pe-3 pb-2 select-none pointer-events-none"
                            style={{ fontSize: '8rem', lineHeight: 1, userSelect: 'none' }}
                        >
                            📦
                        </div>
                    </div>
                </div>

                {/* Quick Track Card */}
                <div className="col-lg-5">
                    <div className="card border-0 shadow-sm custom-card p-4 h-100 d-flex flex-column justify-content-between">
                        <div>
                            <div className="d-flex align-items-center gap-2 mb-2">
                                <span className="fs-4">🔍</span>
                                <h5 className="fw-bold mb-0 text-dark">Quick Track Shipment</h5>
                            </div>
                            <p className="text-muted small mb-3">
                                Enter your Tracking Reference or Order ID to inspect live courier position, route, and estimated time of arrival.
                            </p>
                            <form onSubmit={handleQuickTrackSubmit}>
                                <div className="input-group mb-2">
                                    <input
                                        type="text"
                                        className="form-control"
                                        placeholder="e.g. TRK-1741... or #28"
                                        value={quickTrackQuery}
                                        onChange={(e) => setQuickTrackQuery(e.target.value)}
                                        aria-label="Tracking Number"
                                    />
                                    <button className="btn btn-primary px-3 fw-semibold" type="submit">
                                        Track
                                    </button>
                                </div>
                                {quickTrackError && (
                                    <div className="text-danger small mt-1">{quickTrackError}</div>
                                )}
                            </form>
                        </div>

                        {/* Fast suggestions */}
                        <div className="pt-3 border-top mt-3">
                            <small className="text-muted d-block mb-1" style={{ fontSize: '0.75rem' }}>
                                Popular Kano dispatch corridors:
                            </small>
                            <div className="d-flex flex-wrap gap-1">
                                <span className="badge bg-light text-muted border" style={{ fontSize: '0.72rem' }}>Sabon Gari ⇄ BUK</span>
                                <span className="badge bg-light text-muted border" style={{ fontSize: '0.72rem' }}>Nassarawa ⇄ Fagge</span>
                                <span className="badge bg-light text-muted border" style={{ fontSize: '0.72rem' }}>Kano City ⇄ Tarauni</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Active Delivery Spotlight Card (if any active delivery) */}
            {mostRecentActive && (
                <div className="card border-0 shadow-sm custom-card p-4 mb-4 border-start border-4 border-primary">
                    <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
                        <div className="d-flex align-items-center gap-2">
                            <span className="badge bg-primary px-2 py-1">LIVE SHIPMENT</span>
                            <span className="fw-bold text-dark font-monospace" style={{ fontSize: '1.05rem' }}>
                                {mostRecentActive.tracking_number || `#${mostRecentActive.id}`}
                            </span>
                            <span className="badge bg-light text-dark border">
                                {mostRecentActive.service_type?.replace('_', ' ') || 'Express Courier'}
                            </span>
                        </div>
                        <div className="d-flex align-items-center gap-2">
                            <span className="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1">
                                ⏱️ ETA: {getEtaText(mostRecentActive)}
                            </span>
                            <button
                                className="btn btn-sm btn-primary fw-semibold px-3"
                                onClick={() => onTrack(mostRecentActive)}
                            >
                                Track Live Map & POD →
                            </button>
                        </div>
                    </div>

                    <div className="row g-3 align-items-center">
                        <div className="col-md-5">
                            <div className="p-3 bg-light rounded border">
                                <small className="text-muted d-block mb-1" style={{ fontSize: '0.75rem' }}>CARGO DETAILS</small>
                                <div className="fw-bold text-dark">{mostRecentActive.item_description}</div>
                                <small className="text-muted">
                                    {mostRecentActive.item_category} · {mostRecentActive.item_weight}kg
                                </small>
                            </div>
                        </div>

                        <div className="col-md-7">
                            <div className="p-3 bg-light rounded border">
                                <small className="text-muted d-block mb-1" style={{ fontSize: '0.75rem' }}>DISPATCH ROUTE</small>
                                <div className="d-flex align-items-center gap-2 text-truncate mb-1">
                                    <span className="text-primary">📍</span>
                                    <span className="text-truncate fw-semibold text-dark" style={{ fontSize: '0.88rem' }}>
                                        {mostRecentActive.pickup_address}
                                    </span>
                                </div>
                                <div className="d-flex align-items-center gap-2 text-truncate">
                                    <span className="text-danger">🏁</span>
                                    <span className="text-truncate fw-semibold text-dark" style={{ fontSize: '0.88rem' }}>
                                        {mostRecentActive.delivery_address}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* SaaS Smart Recommendations Engine */}
            {recommendations.length > 0 && (
                <div className="mb-4">
                    <div className="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                        <div>
                            <div className="d-flex align-items-center gap-2">
                                <span className="fs-5">💡</span>
                                <h5 className="fw-bold mb-0 text-dark">Smart Logistics Recommendations</h5>
                                <span className="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1" style={{ fontSize: '0.72rem' }}>
                                    Route AI
                                </span>
                            </div>
                            <p className="text-muted small mb-0">
                                Proactive efficiency tips tailored to your Kano delivery routes and dispatch habits.
                            </p>
                        </div>
                        {dismissedRecs.length > 0 && (
                            <button
                                type="button"
                                className="btn btn-sm btn-link text-decoration-none text-muted p-0"
                                onClick={() => setDismissedRecs([])}
                                style={{ fontSize: '0.8rem' }}
                            >
                                ↺ Reset Dismissed Tips
                            </button>
                        )}
                    </div>

                    <div className="row g-3">
                        {recommendations.map((rec) => (
                            <div key={rec.id} className="col-md-6">
                                <div className="card border-0 shadow-sm custom-card p-3 h-100 d-flex flex-column justify-content-between position-relative">
                                    <div>
                                        <div className="d-flex justify-content-between align-items-start mb-2">
                                            <span className={`badge ${rec.tagBadgeClass}`} style={{ fontSize: '0.72rem', letterSpacing: '0.3px' }}>
                                                {rec.icon} {rec.tag}
                                            </span>
                                            <div className="d-flex align-items-center gap-2">
                                                <span className="badge rounded-pill bg-light text-dark border px-2 py-1" style={{ fontSize: '0.72rem' }}>
                                                    {rec.metric}
                                                </span>
                                                <button
                                                    type="button"
                                                    className="btn-close btn-close-sm"
                                                    aria-label="Dismiss recommendation"
                                                    title="Dismiss this tip"
                                                    onClick={() => setDismissedRecs(prev => [...prev, rec.id])}
                                                    style={{ fontSize: '0.65rem' }}
                                                />
                                            </div>
                                        </div>
                                        <h6 className="fw-bold text-dark mb-1" style={{ fontSize: '0.98rem' }}>
                                            {rec.title}
                                        </h6>
                                        <p className="text-muted small mb-3" style={{ lineHeight: '1.45', fontSize: '0.83rem' }}>
                                            {rec.text}
                                        </p>
                                    </div>
                                    <div className="pt-2 border-top d-flex justify-content-between align-items-center">
                                        <button
                                            type="button"
                                            className="btn btn-link text-decoration-none p-0 fw-semibold text-primary"
                                            onClick={rec.action}
                                            style={{ fontSize: '0.83rem' }}
                                        >
                                            {rec.cta}
                                        </button>
                                        <span className="text-muted small" style={{ fontSize: '0.75rem' }}>
                                            Kano Metropolis
                                        </span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Recent Deliveries Table */}
            <div className="card border-0 shadow-sm custom-card p-4">
                <div className="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 className="fw-bold mb-1 text-dark">Recent Shipments</h5>
                        <p className="text-muted small mb-0">Your latest logistics requests and delivery records</p>
                    </div>
                    {deliveries.length > 0 && (
                        <button className="btn btn-sm btn-outline-primary fw-semibold" onClick={onViewAll}>
                            View all ({deliveries.length}) →
                        </button>
                    )}
                </div>

                {loading ? (
                    <div className="text-center py-4 text-muted">
                        <div className="spinner-border spinner-border-sm text-primary me-2" role="status" />
                        Loading recent shipments...
                    </div>
                ) : recentDeliveries.length === 0 ? (
                    <div className="text-center py-5 bg-light rounded border">
                        <div className="fs-1 mb-2">📦</div>
                        <h6 className="fw-bold">No shipments found</h6>
                        <p className="text-muted small mb-3">You have not booked any shipments yet. Get started today!</p>
                        <button className="btn btn-sm btn-primary fw-semibold px-3" onClick={onBook}>
                            Book Your First Delivery
                        </button>
                    </div>
                ) : (
                    <>
                        {/* Desktop Table */}
                        <div className="table-responsive d-none d-md-block">
                            <table className="table table-hover align-middle mb-0">
                                <thead className="table-light">
                                    <tr>
                                        <th>Tracking Ref</th>
                                        <th>Cargo</th>
                                        <th>Origin → Destination</th>
                                        <th>Status</th>
                                        <th>Fare</th>
                                        <th className="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentDeliveries.map(item => (
                                        <tr key={item.id}>
                                            <td>
                                                <div className="fw-bold text-dark font-monospace" style={{ fontSize: '0.88rem' }}>
                                                    {item.tracking_number || `#${item.id}`}
                                                </div>
                                                <small className="text-muted d-block" style={{ fontSize: '0.75rem' }}>
                                                    {item.service_type?.replace('_', ' ') || 'Standard'}
                                                </small>
                                            </td>
                                            <td>
                                                <div className="fw-semibold text-dark small">{item.item_description}</div>
                                                <small className="text-muted">{item.item_weight}kg</small>
                                            </td>
                                            <td>
                                                <div className="small text-truncate text-dark" style={{ maxWidth: '240px' }} title={item.pickup_address}>
                                                    📍 {item.pickup_address}
                                                </div>
                                                <div className="small text-truncate text-dark" style={{ maxWidth: '240px' }} title={item.delivery_address}>
                                                    🏁 {item.delivery_address}
                                                </div>
                                            </td>
                                            <td>
                                                {getStatusBadge(item.status)}
                                            </td>
                                            <td className="fw-bold text-dark small">
                                                ₦{Number(item.total_cost).toLocaleString()}
                                            </td>
                                            <td className="text-end">
                                                <button
                                                    className="btn btn-sm btn-outline-primary px-3 fw-semibold"
                                                    onClick={() => onTrack(item)}
                                                >
                                                    Track & POD →
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Mobile Cards */}
                        <div className="d-md-none d-flex flex-column gap-3">
                            {recentDeliveries.map(item => (
                                <div key={item.id} className="p-3 border rounded bg-white shadow-sm">
                                    <div className="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <span className="fw-bold text-dark font-monospace d-block">
                                                {item.tracking_number || `#${item.id}`}
                                            </span>
                                            <small className="text-muted">{item.item_description}</small>
                                        </div>
                                        {getStatusBadge(item.status)}
                                    </div>
                                    <div className="small text-muted mb-2">
                                        <div className="text-truncate">📍 {item.pickup_address}</div>
                                        <div className="text-truncate">🏁 {item.delivery_address}</div>
                                    </div>
                                    <div className="d-flex justify-content-between align-items-center pt-2 border-top">
                                        <span className="fw-bold text-dark">
                                            ₦{Number(item.total_cost).toLocaleString()}
                                        </span>
                                        <button
                                            className="btn btn-sm btn-primary px-3"
                                            onClick={() => onTrack(item)}
                                        >
                                            Track →
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
};

export default ClientOverviewTab;
