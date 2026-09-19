import React from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { showToast } from '../common/Toast';
import { StatCardSkeleton } from '../common/SkeletonLoader';

const DriverOverviewTab = ({ onNavigate }) => {
    const queryClient = useQueryClient();

    // 1. Driver Profile & Availability
    const profileQuery = useQuery({
        queryKey: ['driver', 'operations-profile'],
        queryFn: () => apiGet('/delivery-person/operations-profile').then(res => res.data?.profile),
        staleTime: 30000,
    });

    // 2. Active Deliveries
    const activeQuery = useQuery({
        queryKey: ['driver', 'active-assignments'],
        queryFn: () => apiGet('/delivery-person/my-assignments?status=all').then(res => res.data || []),
        select: items => items.filter(item => item.status !== 'delivered' && item.status !== 'cancelled'),
        staleTime: 15000,
    });

    // 3. Available Jobs
    const availableQuery = useQuery({
        queryKey: ['driver', 'available-jobs'],
        queryFn: () => apiGet('/delivery-person/available-deliveries').then(res => res.data || []),
        staleTime: 15000,
    });

    // 4. Earnings Summary
    const earningsQuery = useQuery({
        queryKey: ['driver', 'earnings-summary'],
        queryFn: () => apiGet('/delivery-person/earnings-summary').then(res => res.data),
        staleTime: 30000,
    });

    // Availability Mutation (Available / Paused / Offline)
    const availabilityMutation = useMutation({
        mutationFn: (newStatus) =>
            apiPost('/delivery-person/availability', {
                availability_status: newStatus,
            }),
        onSuccess: (_, newStatus) => {
            queryClient.invalidateQueries({ queryKey: ['driver', 'operations-profile'] });
            const labels = { available: 'Available', paused: 'Paused', offline: 'Offline' };
            showToast.success(`Availability updated to ${labels[newStatus] || newStatus}.`);
        },
        onError: (err) => {
            showToast.error(err.response?.data?.message || 'Could not update availability status.');
        }
    });

    const profile = profileQuery.data;
    const activeDeliveries = activeQuery.data || [];
    const availableJobs = availableQuery.data || [];
    const earnings = earningsQuery.data;

    const rawAvailability = profile?.availability_status;
    const currentStatus = (rawAvailability === 'online' ? 'available' : rawAvailability) || 'offline';
    const isKycVerified = profile?.kyc_status === 'verified';

    // Calculate time-based greeting
    const currentHour = new Date().getHours();
    const timeGreeting = currentHour < 12 
        ? 'Good morning' 
        : currentHour < 17 
        ? 'Good afternoon' 
        : 'Good evening';

    const driverName = profile?.full_name || 'Kano Driver';

    // Primary Active Delivery (the most urgent one)
    const primaryActiveDelivery = activeDeliveries[0] || null;

    // Calculate ETA display for active delivery
    const calculateEta = (delivery) => {
        if (!delivery) return null;
        if (delivery.status === 'arrived') return 'Arrived at destination';
        if (delivery.status === 'in_transit') {
            const km = Number(delivery.distance_km) || 5;
            const estMins = Math.max(8, Math.round(km * 3));
            return `ETA: ~${estMins} min`;
        }
        if (delivery.status === 'picked_up') return 'Departing pickup point';
        if (delivery.status === 'driver_en_route') return 'En route to pickup';
        return 'Dispatched to you';
    };

    // Extract clean short route names (e.g. "Sabon Gari → BUK")
    const getShortRoute = (delivery) => {
        if (!delivery) return '';
        const cleanAddress = (addr) => {
            if (!addr) return '';
            const firstPart = addr.split(',')[0].trim();
            return firstPart.length > 22 ? firstPart.substring(0, 20) + '…' : firstPart;
        };
        const from = cleanAddress(delivery.pickup_address);
        const to = cleanAddress(delivery.delivery_address);
        return `${from} → ${to}`;
    };

    return (
        <div className="driver-overview-container">
            {/* ── Top Greeting Card ────────────────────────────────────── */}
            <div className="card border-0 shadow-sm custom-card p-4 mb-3">
                <div className="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                    <div>
                        <span className="text-muted small text-uppercase fw-semibold" style={{ letterSpacing: '0.5px' }}>
                            Tweak Insight Logistics · Kano Dispatch Terminal
                        </span>
                        <h3 className="fw-bold mb-0 text-dark">
                            {timeGreeting}, {driverName}
                        </h3>
                    </div>

                    <div className="d-flex align-items-center gap-2">
                        <span className="badge bg-light text-dark border px-3 py-2 fw-semibold">
                            🛵 {profile?.vehicle_type || 'Motorcycle'} · {profile?.vehicle_registration || 'Kano Dispatch'}
                        </span>
                    </div>
                </div>
            </div>

            {/* ── Compact Driver Availability & Dispatch Operations Card ── */}
            <div className="card border-0 shadow-sm custom-card p-4 mb-4">
                <div className="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                    <div>
                        <div className="d-flex align-items-center gap-2 mb-1">
                            <span className="small text-muted text-uppercase fw-bold" style={{ letterSpacing: '0.5px' }}>
                                Dispatch Availability Status
                            </span>
                            <span className={`badge rounded-pill px-2 py-1 text-uppercase ${
                                currentStatus === 'available'
                                    ? 'bg-success text-white'
                                    : currentStatus === 'paused'
                                    ? 'bg-warning text-dark'
                                    : 'bg-secondary text-white'
                            }`} style={{ fontSize: '0.78rem', fontWeight: 700 }}>
                                ● {currentStatus}
                            </span>
                        </div>
                        <p className="text-muted small mb-0">
                            {currentStatus === 'available' && '🟢 You are online and ready to receive instant Kano delivery dispatches.'}
                            {currentStatus === 'paused' && '🟡 Temporarily on break. New dispatches will not be assigned until you resume.'}
                            {currentStatus === 'offline' && '⚪ You are currently offline. Switch to Available to receive job assignments.'}
                        </p>
                    </div>

                    {/* Simple Actions: Go Available, Pause, Go Offline */}
                    <div className="d-flex flex-wrap align-items-center gap-2">
                        <button
                            type="button"
                            className={`btn btn-sm px-3 py-2 fw-semibold d-flex align-items-center gap-1 ${
                                currentStatus === 'available'
                                    ? 'btn-success text-white shadow-sm'
                                    : 'btn-outline-success'
                            }`}
                            disabled={availabilityMutation.isPending || currentStatus === 'available' || !isKycVerified}
                            onClick={() => availabilityMutation.mutate('available')}
                            title={!isKycVerified ? 'KYC verification required to go available' : 'Set status to Available'}
                        >
                            <span>⚡</span>
                            <span>{availabilityMutation.isPending && currentStatus !== 'available' ? 'Updating…' : 'Go Available'}</span>
                        </button>

                        <button
                            type="button"
                            className={`btn btn-sm px-3 py-2 fw-semibold d-flex align-items-center gap-1 ${
                                currentStatus === 'paused'
                                    ? 'btn-warning text-dark shadow-sm'
                                    : 'btn-outline-warning text-dark'
                            }`}
                            disabled={availabilityMutation.isPending || currentStatus === 'paused' || currentStatus === 'offline'}
                            onClick={() => availabilityMutation.mutate('paused')}
                            title="Pause dispatches for a quick break"
                        >
                            <span>⏸️</span>
                            <span>Pause</span>
                        </button>

                        <button
                            type="button"
                            className={`btn btn-sm px-3 py-2 fw-semibold d-flex align-items-center gap-1 ${
                                currentStatus === 'offline'
                                    ? 'btn-secondary text-white shadow-sm'
                                    : 'btn-outline-secondary'
                            }`}
                            disabled={availabilityMutation.isPending || currentStatus === 'offline'}
                            onClick={() => availabilityMutation.mutate('offline')}
                            title="Go offline and conclude active shift"
                        >
                            <span>⏹️</span>
                            <span>Go Offline</span>
                        </button>
                    </div>
                </div>

                {!isKycVerified && (
                    <div className="mt-3 pt-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <span className="small text-muted">
                            ⚠️ Complete your KYC verification to unlock Kano dispatch availability and job offers.
                        </span>
                        <button
                            type="button"
                            className="btn btn-sm btn-link p-0 text-primary fw-semibold text-decoration-none"
                            onClick={() => onNavigate('kyc-documents')}
                        >
                            Upload KYC Documents →
                        </button>
                    </div>
                )}
            </div>

            {/* ── 4 Main KPI Cards: Available, Active, Completed, Today's Earnings ── */}
            <div className="row g-3 mb-4">
                {/* 1. Available Jobs count */}
                <div className="col-6 col-lg-3">
                    <div 
                        className="card border-0 shadow-sm custom-card p-3 h-100 cursor-pointer hover-lift"
                        onClick={() => onNavigate('available')}
                        role="button"
                        tabIndex={0}
                    >
                        <div className="d-flex justify-content-between align-items-center mb-1">
                            <span className="text-muted small fw-semibold text-uppercase" style={{ fontSize: '0.72rem' }}>
                                Available Jobs
                            </span>
                            <span className="fs-5" aria-hidden="true">📋</span>
                        </div>
                        <div className="d-flex align-items-baseline gap-2">
                            <span className="display-6 fw-bold text-primary">
                                {availableJobs.length}
                            </span>
                            <small className="text-muted">offers</small>
                        </div>
                        <small className="text-muted d-block mt-1">Ready for pickup</small>
                    </div>
                </div>

                {/* 2. Active Delivery count */}
                <div className="col-6 col-lg-3">
                    <div 
                        className="card border-0 shadow-sm custom-card p-3 h-100 cursor-pointer hover-lift"
                        onClick={() => onNavigate('active')}
                        role="button"
                        tabIndex={0}
                    >
                        <div className="d-flex justify-content-between align-items-center mb-1">
                            <span className="text-muted small fw-semibold text-uppercase" style={{ fontSize: '0.72rem' }}>
                                Active Delivery
                            </span>
                            <span className="fs-5" aria-hidden="true">🚚</span>
                        </div>
                        <div className="d-flex align-items-baseline gap-2">
                            <span className={`display-6 fw-bold ${activeDeliveries.length > 0 ? 'text-warning' : 'text-dark'}`}>
                                {activeDeliveries.length}
                            </span>
                            <small className="text-muted">in progress</small>
                        </div>
                        <small className="text-muted d-block mt-1">Current trip</small>
                    </div>
                </div>

                {/* 3. Completed Orders count */}
                <div className="col-6 col-lg-3">
                    <div 
                        className="card border-0 shadow-sm custom-card p-3 h-100 cursor-pointer hover-lift"
                        onClick={() => onNavigate('completed')}
                        role="button"
                        tabIndex={0}
                    >
                        <div className="d-flex justify-content-between align-items-center mb-1">
                            <span className="text-muted small fw-semibold text-uppercase" style={{ fontSize: '0.72rem' }}>
                                Completed Orders
                            </span>
                            <span className="fs-5" aria-hidden="true">✅</span>
                        </div>
                        <div className="d-flex align-items-baseline gap-2">
                            <span className="display-6 fw-bold text-success">
                                {earningsQuery.isLoading ? '…' : (earnings?.completed_trips || 0)}
                            </span>
                            <small className="text-muted">delivered</small>
                        </div>
                        <small className="text-muted d-block mt-1">Delivered orders</small>
                    </div>
                </div>

                {/* 4. Today's Earnings */}
                <div className="col-6 col-lg-3">
                    <div 
                        className="card border-0 shadow-sm custom-card p-3 h-100 cursor-pointer hover-lift"
                        onClick={() => onNavigate('earnings')}
                        role="button"
                        tabIndex={0}
                    >
                        <div className="d-flex justify-content-between align-items-center mb-1">
                            <span className="text-muted small fw-semibold text-uppercase" style={{ fontSize: '0.72rem' }}>
                                Today's Earnings
                            </span>
                            <span className="fs-5" aria-hidden="true">💰</span>
                        </div>
                        <div className="d-flex align-items-baseline gap-1">
                            <span className="display-6 fw-bold text-dark">
                                {earningsQuery.isLoading ? '…' : `₦${Number(earnings?.today_earned || 0).toLocaleString()}`}
                            </span>
                        </div>
                        <small className="text-muted d-block mt-1">
                            Total: ₦{Number(earnings?.total_earned || 0).toLocaleString()}
                        </small>
                    </div>
                </div>
            </div>

            {/* ── Main Priority Section: Current Active Delivery ─────────── */}
            <div className="card border-0 shadow-sm custom-card p-4 mb-4 driver-active-hero-card">
                <div className="d-flex justify-content-between align-items-center mb-3">
                    <div className="d-flex align-items-center gap-2">
                        <span className="fs-5" aria-hidden="true">🚚</span>
                        <h5 className="fw-bold mb-0 text-dark">CURRENT ACTIVE DELIVERY</h5>
                    </div>
                    {primaryActiveDelivery && (
                        <span className="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                            {primaryActiveDelivery.status.replaceAll('_', ' ')}
                        </span>
                    )}
                </div>

                {primaryActiveDelivery ? (
                    <div>
                        <div className="p-3 bg-light rounded-3 border mb-3">
                            <div className="d-flex flex-wrap justify-content-between align-items-baseline gap-2 mb-2">
                                <div>
                                    <small className="text-muted d-block" style={{ fontSize: '0.72rem' }}>TRACKING ID</small>
                                    <span className="fw-bold fs-5 text-dark" style={{ fontFamily: 'monospace', letterSpacing: '0.5px' }}>
                                        {primaryActiveDelivery.tracking_number || `TIL-2026-000${primaryActiveDelivery.id}`}
                                    </span>
                                </div>
                                <span className="badge bg-success text-white fw-bold px-3 py-2" style={{ fontSize: '0.85rem' }}>
                                    {calculateEta(primaryActiveDelivery)}
                                </span>
                            </div>

                            {/* Short Route Preview */}
                            <div className="fw-semibold text-dark fs-6 mb-2">
                                📍 {getShortRoute(primaryActiveDelivery)}
                            </div>

                            <div className="row g-2 small text-muted border-top pt-2 mt-2">
                                <div className="col-sm-6 text-truncate">
                                    <strong>Pickup:</strong> {primaryActiveDelivery.pickup_address}
                                </div>
                                <div className="col-sm-6 text-truncate">
                                    <strong>Destination:</strong> {primaryActiveDelivery.delivery_address}
                                </div>
                                <div className="col-12 text-dark fw-semibold mt-1">
                                    📦 {primaryActiveDelivery.item_description} ({primaryActiveDelivery.item_weight}kg) · ₦{Number(primaryActiveDelivery.total_cost || 0).toLocaleString()}
                                </div>
                            </div>
                        </div>

                        <div className="d-flex gap-2">
                            <button
                                type="button"
                                className="btn btn-primary fw-bold flex-grow-1 shadow-sm py-2 driver-action-btn"
                                style={{ minHeight: '48px', fontSize: '1rem' }}
                                onClick={() => onNavigate('active')}
                            >
                                View Delivery →
                            </button>
                            {activeDeliveries.length > 1 && (
                                <button
                                    type="button"
                                    className="btn btn-outline-secondary px-3"
                                    onClick={() => onNavigate('active')}
                                >
                                    +{activeDeliveries.length - 1} more
                                </button>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="text-center py-4 bg-light rounded-3 border">
                        <div className="fs-2 mb-2">📦</div>
                        <h6 className="fw-bold text-dark mb-1">No Active Delivery Assignment</h6>
                        <p className="text-muted small mb-3">
                            You are currently available with no active trip in progress. Check available orders waiting for pickup in Kano.
                        </p>
                        <button
                            type="button"
                            className="btn btn-outline-primary px-4 fw-bold"
                            onClick={() => onNavigate('available')}
                        >
                            Find Available Jobs →
                        </button>
                    </div>
                )}
            </div>

            {/* ── Quick Operational Navigation ──────────────────────────── */}
            <div className="card border-0 shadow-sm custom-card p-4">
                <h6 className="fw-bold text-muted text-uppercase small mb-3" style={{ letterSpacing: '0.5px' }}>
                    Quick Dispatch Operations
                </h6>
                <div className="row g-3">
                    <div className="col-md-4">
                        <div 
                            className="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between hover-lift cursor-pointer"
                            onClick={() => onNavigate('available')}
                            role="button"
                            tabIndex={0}
                        >
                            <div>
                                <div className="fw-bold text-dark">📋 Available Jobs</div>
                                <small className="text-muted">{availableJobs.length} delivery offers waiting</small>
                            </div>
                            <span className="badge bg-primary rounded-pill">{availableJobs.length}</span>
                        </div>
                    </div>
                    <div className="col-md-4">
                        <div 
                            className="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between hover-lift cursor-pointer"
                            onClick={() => onNavigate('active')}
                            role="button"
                            tabIndex={0}
                        >
                            <div>
                                <div className="fw-bold text-dark">🚚 Active Assignment</div>
                                <small className="text-muted">{activeDeliveries.length} orders in progress</small>
                            </div>
                            <span className="badge bg-warning text-dark rounded-pill">{activeDeliveries.length}</span>
                        </div>
                    </div>
                    <div className="col-md-4">
                        <div 
                            className="p-3 border rounded-3 bg-light d-flex align-items-center justify-content-between hover-lift cursor-pointer"
                            onClick={() => onNavigate('completed')}
                            role="button"
                            tabIndex={0}
                        >
                            <div>
                                <div className="fw-bold text-dark">✅ Completed Orders</div>
                                <small className="text-muted">Trip receipts & history</small>
                            </div>
                            <span className="text-muted">→</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default DriverOverviewTab;
