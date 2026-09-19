import React, { useState, useEffect } from 'react';
import { apiClient } from '../../api/client';
import LiveLocationMap from '../common/LiveLocationMap';
import { showToast } from '../common/Toast';

const TrackingModal = ({ delivery, onClose }) => {
    const statuses = [
        'pending',
        'under_review',
        'broadcasted',
        'assigned',
        'driver_en_route',
        'picked_up',
        'in_transit',
        'arrived',
        'delivered',
        'completed'
    ];
    const currentIdx = statuses.indexOf(delivery.status);
    const [liveLocation, setLiveLocation] = useState(null);
    const [copiedOtp, setCopiedOtp] = useState(false);
    const [copiedTracking, setCopiedTracking] = useState(false);

    useEffect(() => {
        const load = async () => {
            try {
                const res = await apiClient.get(`/deliveries/live-location?id=${delivery.id}`);
                setLiveLocation(res.data.data);
            } catch (err) {
                console.warn('[TrackingModal] Live location poll failed:', err?.message || err);
            }
        };
        load();
        const timer = setInterval(load, 15000);
        return () => clearInterval(timer);
    }, [delivery.id]);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [onClose]);

    const trackingRef = delivery.tracking_number || `#${delivery.id}`;

    const handleCopyTracking = () => {
        if (delivery.tracking_number) {
            navigator.clipboard?.writeText(delivery.tracking_number);
            setCopiedTracking(true);
            showToast.success(`Tracking ID copied: ${delivery.tracking_number}`);
            setTimeout(() => setCopiedTracking(false), 2000);
        }
    };

    const handleCopyOtp = () => {
        if (delivery.delivery_otp) {
            navigator.clipboard?.writeText(delivery.delivery_otp);
            setCopiedOtp(true);
            showToast.success(`Delivery OTP copied: ${delivery.delivery_otp}`);
            setTimeout(() => setCopiedOtp(false), 2000);
        }
    };

    const handlePrintReceipt = () => {
        window.print();
    };

    // Calculate an intuitive ETA based on status and distance
    const getEstimatedArrival = () => {
        if (['delivered', 'completed'].includes(delivery.status)) {
            return {
                label: 'Delivered',
                text: delivery.delivery_time 
                    ? new Date(delivery.delivery_time).toLocaleString() 
                    : 'Completed recently',
                isFinal: true
            };
        }
        if (delivery.status === 'in_transit' || delivery.status === 'arrived') {
            const distance = Number(delivery.distance_km) || 5;
            const estMins = Math.max(10, Math.round(distance * 3.5)); // ~3.5 mins per km in Kano metro traffic
            return {
                label: 'Estimated Arrival',
                text: `~${estMins} mins (approx. ${estMins < 30 ? 'on schedule' : 'moderate traffic'})`,
                isFinal: false
            };
        }
        if (delivery.status === 'picked_up') {
            return {
                label: 'Status',
                text: 'Cargo picked up, dispatch rider departing shortly',
                isFinal: false
            };
        }
        if (['assigned', 'driver_en_route'].includes(delivery.status)) {
            return {
                label: 'Status',
                text: 'Rider assigned, en route to pickup point',
                isFinal: false
            };
        }
        return {
            label: 'Estimated Dispatch',
            text: 'Within 15–30 mins of verification',
            isFinal: false
        };
    };

    const eta = getEstimatedArrival();
    const isDelivered = ['delivered', 'completed'].includes(delivery.status);
    const isPickedUpActive = delivery?.status === 'picked_up';
    const isInTransitActive = delivery?.status === 'in_transit' || delivery?.status === 'arrived';
    const isDriverAssignedActive = delivery?.status === 'assigned' || delivery?.status === 'driver_en_route';

    return (
        <div 
            className="modal show d-block app-modal-backdrop" 
            role="dialog" 
            aria-modal="true" 
            aria-label={`Live tracking and proof of delivery for order ${trackingRef}`}
        >
            <div className="modal-dialog modal-dialog-centered modal-lg">
                <div className="modal-content border-0 shadow-lg" style={{ borderRadius: '16px', overflow: 'hidden' }}>
                    {/* Header */}
                    <div className="modal-header bg-dark text-white px-4 py-3 d-flex justify-content-between align-items-center">
                        <div className="d-flex align-items-center gap-3">
                            <div>
                                <div className="d-flex align-items-center gap-2">
                                    <h5 className="modal-title fw-bold mb-0" style={{ letterSpacing: '0.5px' }}>
                                        {trackingRef}
                                    </h5>
                                    {delivery.tracking_number && (
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline-light py-0 px-2"
                                            style={{ fontSize: '0.75rem' }}
                                            onClick={handleCopyTracking}
                                            title="Copy Tracking ID"
                                        >
                                            {copiedTracking ? '✓ Copied' : '📋 Copy'}
                                        </button>
                                    )}
                                </div>
                                <small className="text-secondary" style={{ fontSize: '0.8rem' }}>
                                    {delivery.service_type ? delivery.service_type.replace('_', ' ') : 'Standard'} · Kano Metro
                                </small>
                            </div>
                        </div>
                        <button 
                            className="btn-close btn-close-white" 
                            type="button" 
                            aria-label="Close tracking view" 
                            onClick={onClose}
                        />
                    </div>

                    <div className="modal-body p-4">
                        {/* Status & ETA Banner */}
                        <div className="p-3 rounded-3 bg-light border mb-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                            <div>
                                <small className="text-muted text-uppercase fw-semibold d-block" style={{ fontSize: '0.72rem', letterSpacing: '0.5px' }}>
                                    Current Status
                                </small>
                                <div className="d-flex align-items-center gap-2 mt-1">
                                    <span className={`status-pill status-${delivery.status} fs-6 px-3 py-1`}>
                                        {delivery.status.replaceAll('_', ' ')}
                                    </span>
                                </div>
                            </div>
                            <div className="text-sm-end border-start-sm ps-sm-3">
                                <small className="text-muted text-uppercase fw-semibold d-block" style={{ fontSize: '0.72rem', letterSpacing: '0.5px' }}>
                                    {eta.label}
                                </small>
                                <span className={`fw-bold ${eta.isFinal ? 'text-success' : 'text-dark'}`} style={{ fontSize: '0.95rem' }}>
                                    {eta.text}
                                </span>
                            </div>
                        </div>

                        {/* Visual Progress Stepper Timeline */}
                        <div className="mb-4">
                            <h6 className="fw-bold text-dark mb-3" style={{ fontSize: '0.9rem' }}>
                                Delivery Progress Timeline
                            </h6>
                            <div className="tracking-stepper">
                                <div className={`tracking-node ${currentIdx > 2 ? 'done' : 'current'}`}>
                                    <div className="circle">{currentIdx > 2 ? '✓' : '1'}</div>
                                    <div className="label">Order Placed</div>
                                    <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                        {delivery.request_time ? new Date(delivery.request_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                                    </small>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 5 ? 'done' : (isDriverAssignedActive ? 'current pulsing-glow' : (currentIdx >= 3 ? 'current' : ''))}`}>
                                    <div className="circle">{currentIdx >= 5 ? '✓' : '2'}</div>
                                    <div className="label">Driver Assigned</div>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 6 ? 'done' : (isPickedUpActive ? 'current pulsing-glow' : '')}`}>
                                    <div className="circle">{currentIdx >= 6 ? '✓' : '3'}</div>
                                    <div className="label">Picked Up</div>
                                    <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                        {delivery.pickup_time ? new Date(delivery.pickup_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                                    </small>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 8 ? 'done' : (isInTransitActive ? 'current pulsing-glow' : '')}`}>
                                    <div className="circle">{currentIdx >= 8 ? '✓' : '4'}</div>
                                    <div className="label">In Transit</div>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 8 ? 'done' : ''}`}>
                                    <div className="circle">{currentIdx >= 8 ? '✓' : '5'}</div>
                                    <div className="label">Delivered</div>
                                    <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                        {delivery.delivery_time ? new Date(delivery.delivery_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                                    </small>
                                </div>
                            </div>
                        </div>

                        {/* Proof of Delivery (POD) Section */}
                        <div className="mb-4">
                            <div className="d-flex justify-content-between align-items-center mb-2">
                                <h6 className="fw-bold text-dark mb-0" style={{ fontSize: '0.9rem' }}>
                                    🔐 Proof of Delivery (POD)
                                </h6>
                                {isDelivered && (
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-outline-secondary py-1 px-2"
                                        style={{ fontSize: '0.75rem' }}
                                        onClick={handlePrintReceipt}
                                    >
                                        🖨️ Print Receipt
                                    </button>
                                )}
                            </div>

                            {/* Active Orders: Secure Recipient Handover OTP */}
                            {!isDelivered ? (
                                <div className="card p-3 border border-primary-subtle bg-primary-subtle rounded-3">
                                    <div className="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                                        <div>
                                            <span className="fw-bold text-primary d-block" style={{ fontSize: '0.9rem' }}>
                                                Recipient Confirmation OTP
                                            </span>
                                            <p className="text-muted small mb-0" style={{ fontSize: '0.8rem' }}>
                                                Share this 6-digit code with the dispatch rider <strong>only</strong> after physically inspecting your package.
                                            </p>
                                        </div>
                                        <div className="d-flex align-items-center gap-2">
                                            <span 
                                                className="badge bg-dark text-white px-3 py-2 fs-5" 
                                                style={{ letterSpacing: '3px', fontFamily: 'monospace' }}
                                            >
                                                {delivery.delivery_otp || '••••••'}
                                            </span>
                                            {delivery.delivery_otp && (
                                                <button
                                                    type="button"
                                                    className="btn btn-sm btn-primary"
                                                    onClick={handleCopyOtp}
                                                    title="Copy OTP"
                                                >
                                                    {copiedOtp ? '✓' : 'Copy'}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                /* Delivered Orders: Digital Proof of Delivery Certificate */
                                <div className="card p-3 border border-success-subtle bg-success-subtle rounded-3">
                                    <div className="d-flex align-items-center gap-2 mb-2">
                                        <span className="fs-5 text-success">✓</span>
                                        <span className="fw-bold text-success" style={{ fontSize: '0.95rem' }}>
                                            Digital Proof of Delivery Confirmed
                                        </span>
                                        <span className="badge bg-success ms-auto" style={{ fontSize: '0.7rem' }}>
                                            Verified
                                        </span>
                                    </div>
                                    <div className="row g-2 small text-dark mt-1">
                                        <div className="col-sm-6">
                                            <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>Delivered Time:</span>
                                            <strong>{delivery.delivery_time ? new Date(delivery.delivery_time).toLocaleString() : 'Confirmed'}</strong>
                                        </div>
                                        <div className="col-sm-6">
                                            <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>Confirmed Recipient:</span>
                                            <strong>{delivery.delivery_contact_name || 'Authorized Recipient'}</strong>
                                        </div>
                                        <div className="col-sm-6">
                                            <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>Verification Method:</span>
                                            <span className="text-success fw-semibold">✓ 6-Digit OTP Digital Handshake Verified</span>
                                        </div>
                                        <div className="col-sm-6">
                                            <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>Delivered By:</span>
                                            <span>{delivery.delivery_person_name || 'Assigned Partner'}</span>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Driver & Consignment Details Grid */}
                        <div className="row g-3 mb-4">
                            {/* Driver Card */}
                            <div className="col-md-6">
                                <div className="card p-3 h-100 bg-light border">
                                    <small className="text-muted text-uppercase fw-semibold d-block mb-1" style={{ fontSize: '0.7rem' }}>
                                        Assigned Delivery Rider
                                    </small>
                                    <div className="d-flex align-items-center gap-2">
                                        <div className="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold" style={{ width: '40px', height: '40px' }}>
                                            {delivery.delivery_person_name ? delivery.delivery_person_name.charAt(0).toUpperCase() : '🛵'}
                                        </div>
                                        <div>
                                            <div className="fw-bold text-dark" style={{ fontSize: '0.9rem' }}>
                                                {delivery.delivery_person_name || 'Awaiting rider assignment'}
                                            </div>
                                            {delivery.delivery_person_phone ? (
                                                <a 
                                                    href={`tel:${delivery.delivery_person_phone}`} 
                                                    className="small text-primary text-decoration-none fw-semibold"
                                                >
                                                    📞 {delivery.delivery_person_phone}
                                                </a>
                                            ) : (
                                                <small className="text-muted">Operations automated dispatch</small>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Consignment Specs */}
                            <div className="col-md-6">
                                <div className="card p-3 h-100 bg-light border">
                                    <small className="text-muted text-uppercase fw-semibold d-block mb-1" style={{ fontSize: '0.7rem' }}>
                                        Package Information
                                    </small>
                                    <div className="fw-semibold text-dark" style={{ fontSize: '0.9rem' }}>
                                        {delivery.item_description}
                                    </div>
                                    <div className="small text-muted mt-1">
                                        {delivery.item_category} · {delivery.item_weight}kg · {delivery.distance_km}km
                                    </div>
                                    <div className="d-flex gap-1 mt-1">
                                        {delivery.is_fragile ? <span className="badge bg-danger-subtle text-danger" style={{ fontSize: '0.65rem' }}>Fragile</span> : null}
                                        {delivery.is_perishable ? <span className="badge bg-warning-subtle text-dark" style={{ fontSize: '0.65rem' }}>Perishable</span> : null}
                                        <span className="badge bg-secondary-subtle text-dark ms-auto" style={{ fontSize: '0.7rem' }}>
                                            Fare: ₦{Number(delivery.total_cost).toLocaleString()}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Origin & Destination Addresses */}
                            <div className="col-12">
                                <div className="card p-3 bg-light border">
                                    <div className="row g-2 small">
                                        <div className="col-sm-6 border-end-sm">
                                            <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>📍 Origin (Pickup):</span>
                                            <strong className="d-block text-dark">{delivery.pickup_address}</strong>
                                            <small className="text-muted">Contact: {delivery.pickup_contact_name} ({delivery.pickup_contact_phone || 'Account'})</small>
                                        </div>
                                        <div className="col-sm-6 ps-sm-3">
                                            <span className="text-muted d-block" style={{ fontSize: '0.75rem' }}>🏁 Destination:</span>
                                            <strong className="d-block text-dark">{delivery.delivery_address}</strong>
                                            <small className="text-muted">Recipient: {delivery.delivery_contact_name} ({delivery.delivery_contact_phone})</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Live GPS Map section */}
                        <div>
                            <div className="d-flex justify-content-between align-items-center mb-2">
                                <h6 className="fw-bold text-dark mb-0" style={{ fontSize: '0.9rem' }}>
                                    🛰️ Live Driver Location (GPS)
                                </h6>
                                <span className={`badge ${liveLocation?.is_live ? 'bg-success' : 'bg-secondary'}`} style={{ fontSize: '0.7rem' }}>
                                    {liveLocation?.is_live ? '● Live Telemetry' : 'Offline / Periodic'}
                                </span>
                            </div>
                            <p className="small text-muted mb-2" style={{ fontSize: '0.8rem' }}>
                                GPS telemetry streams while this delivery is actively in transit.
                            </p>
                            <LiveLocationMap location={liveLocation} />
                        </div>
                    </div>

                    <div className="modal-footer bg-light px-4 py-3">
                        <button className="btn btn-secondary px-4 fw-semibold" onClick={onClose}>
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default TrackingModal;
