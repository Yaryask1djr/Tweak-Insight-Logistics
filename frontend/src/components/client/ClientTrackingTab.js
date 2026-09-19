import React, { useState, useEffect, useMemo } from 'react';
import { apiClient } from '../../api/client';
import LiveLocationMap from '../common/LiveLocationMap';
import { showToast } from '../common/Toast';
import Icon from '../common/Icon';

const ClientTrackingTab = ({
    deliveries = [],
    selectedDelivery: initialSelectedDelivery = null,
    onSelectDelivery,
    onBook
}) => {
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedDelivery, setSelectedDelivery] = useState(initialSelectedDelivery || deliveries[0] || null);
    const [liveLocation, setLiveLocation] = useState(null);
    const [copiedOtp, setCopiedOtp] = useState(false);
    const [copiedTracking, setCopiedTracking] = useState(false);
    const [searchError, setSearchError] = useState('');

    // Update if parent passes a different selectedDelivery
    useEffect(() => {
        if (initialSelectedDelivery) {
            setSelectedDelivery(initialSelectedDelivery);
        } else if (!selectedDelivery && deliveries.length > 0) {
            // Default to first active delivery, or first delivery
            const firstActive = deliveries.find(d =>
                ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived'].includes(d.status)
            );
            setSelectedDelivery(firstActive || deliveries[0]);
        }
    }, [initialSelectedDelivery, deliveries]);

    // Live location polling when selectedDelivery changes
    useEffect(() => {
        if (!selectedDelivery?.id) return;
        let isMounted = true;

        const fetchLocation = async () => {
            try {
                const res = await apiClient.get(`/deliveries/live-location?id=${selectedDelivery.id}`);
                if (isMounted && res.data?.data) {
                    setLiveLocation(res.data.data);
                }
            } catch (err) {
                console.warn('[ClientTrackingTab] Live location poll failed:', err?.message || err);
            }
        };

        fetchLocation();
        const timer = setInterval(fetchLocation, 15000); // 15s live GPS updates
        return () => {
            isMounted = false;
            clearInterval(timer);
        };
    }, [selectedDelivery?.id]);

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
    const currentIdx = selectedDelivery ? statuses.indexOf(selectedDelivery.status) : -1;
    const isDelivered = selectedDelivery && ['delivered', 'completed'].includes(selectedDelivery.status);

    const handleSearch = (e) => {
        e.preventDefault();
        setSearchError('');
        const q = searchQuery.trim().toLowerCase();
        if (!q) {
            setSearchError('Enter a Tracking Reference or Order ID');
            return;
        }
        const match = deliveries.find(d =>
            (d.tracking_number && d.tracking_number.toLowerCase() === q) ||
            String(d.id) === q ||
            (d.tracking_number && d.tracking_number.toLowerCase().includes(q))
        );
        if (match) {
            setSelectedDelivery(match);
            onSelectDelivery?.(match);
        } else {
            setSearchError(`No local match found for "${searchQuery}". Showing available shipments.`);
        }
    };

    const handleCopyTracking = () => {
        if (selectedDelivery?.tracking_number) {
            navigator.clipboard?.writeText(selectedDelivery.tracking_number);
            setCopiedTracking(true);
            showToast.success(`Tracking ID copied: ${selectedDelivery.tracking_number}`);
            setTimeout(() => setCopiedTracking(false), 2000);
        }
    };

    const handleCopyOtp = () => {
        if (selectedDelivery?.delivery_otp) {
            navigator.clipboard?.writeText(selectedDelivery.delivery_otp);
            setCopiedOtp(true);
            showToast.success(`Delivery OTP copied: ${selectedDelivery.delivery_otp}`);
            setTimeout(() => setCopiedOtp(false), 2000);
        }
    };

    // Calculate dynamic ETA based on distance and status
    const getEstimatedArrival = (delivery) => {
        if (!delivery) return { label: 'Status', text: 'N/A', isFinal: false };
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
            const estMins = Math.max(10, Math.round(distance * 3.5));
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
            text: 'Within 15–30 mins of confirmation',
            isFinal: false
        };
    };

    const eta = getEstimatedArrival(selectedDelivery);
    const trackingRef = selectedDelivery ? (selectedDelivery.tracking_number || `#${selectedDelivery.id}`) : '';

    return (
        <div className="client-tracking-tab">
            {/* Top Bar: Search & Select Shipment */}
            <div className="card border-0 shadow-sm custom-card p-3 mb-4">
                <div className="row g-3 align-items-center">
                    <div className="col-lg-5">
                        <form onSubmit={handleSearch}>
                            <div className="input-group input-group-sm">
                                <span className="input-group-text bg-white">🔍</span>
                                <input
                                    type="text"
                                    className="form-control"
                                    placeholder="Search Tracking ID (e.g. TRK-...) or Order #"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                />
                                <button className="btn btn-primary px-3" type="submit">
                                    Search
                                </button>
                            </div>
                            {searchError && (
                                <small className="text-danger d-block mt-1">{searchError}</small>
                            )}
                        </form>
                    </div>

                    <div className="col-lg-7 d-flex align-items-center justify-content-lg-end gap-2 flex-wrap">
                        <label className="text-muted small mb-0 d-none d-sm-inline">Select Shipment:</label>
                        <select
                            className="form-select form-select-sm"
                            style={{ maxWidth: '300px' }}
                            value={selectedDelivery?.id || ''}
                            onChange={(e) => {
                                const d = deliveries.find(item => String(item.id) === e.target.value);
                                if (d) {
                                    setSelectedDelivery(d);
                                    onSelectDelivery?.(d);
                                }
                            }}
                        >
                            {deliveries.length === 0 && <option value="">No shipments found</option>}
                            {deliveries.map(d => (
                                <option key={d.id} value={d.id}>
                                    {d.tracking_number || `#${d.id}`} - {d.item_description?.slice(0, 20)} ({d.status.replace('_', ' ')})
                                </option>
                            ))}
                        </select>
                        <button
                            className="btn btn-sm btn-outline-primary"
                            onClick={() => {
                                if (selectedDelivery?.id) {
                                    // re-trigger polling
                                    apiClient.get(`/deliveries/live-location?id=${selectedDelivery.id}`)
                                        .then(r => r.data?.data && setLiveLocation(r.data.data))
                                        .catch(() => {});
                                    showToast.info('Refreshed tracking status');
                                }
                            }}
                            title="Refresh GPS & Status"
                        >
                            <Icon name="refresh" size={14} /> Refresh
                        </button>
                    </div>
                </div>
            </div>

            {!selectedDelivery ? (
                <div className="card border-0 shadow-sm custom-card p-5 text-center">
                    <div className="fs-1 mb-2">🗺️</div>
                    <h5 className="fw-bold">No Shipment Selected for Tracking</h5>
                    <p className="text-muted small mb-3">
                        Choose a delivery from your shipments or book a new one to follow live courier dispatch.
                    </p>
                    <div className="d-flex justify-content-center gap-2">
                        <button className="btn btn-primary fw-semibold" onClick={onBook}>
                            + Book a Delivery
                        </button>
                    </div>
                </div>
            ) : (
                <div className="row g-4">
                    {/* Main Left Column: Map & Timeline */}
                    <div className="col-lg-8">
                        {/* Shipment Header Banner */}
                        <div className="card border-0 shadow-sm custom-card p-3 mb-4">
                            <div className="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                                <div>
                                    <div className="d-flex align-items-center gap-2">
                                        <h5 className="fw-bold mb-0 font-monospace text-dark">
                                            {trackingRef}
                                        </h5>
                                        {selectedDelivery.tracking_number && (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-secondary py-0 px-2"
                                                style={{ fontSize: '0.75rem' }}
                                                onClick={handleCopyTracking}
                                            >
                                                {copiedTracking ? '✓ Copied' : '📋 Copy'}
                                            </button>
                                        )}
                                        <span className="badge bg-light text-dark border">
                                            {selectedDelivery.service_type?.replace('_', ' ') || 'Standard Courier'}
                                        </span>
                                    </div>
                                    <small className="text-muted">
                                        Booked on {selectedDelivery.request_time ? new Date(selectedDelivery.request_time).toLocaleDateString() : 'Recent'} · Kano Metropolis
                                    </small>
                                </div>
                                <div className="text-sm-end">
                                    <span className={`status-pill status-${selectedDelivery.status} fs-6 px-3 py-1 d-inline-block`}>
                                        {selectedDelivery.status.replaceAll('_', ' ')}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Live Location Map */}
                        <div className="card border-0 shadow-sm custom-card p-3 mb-4">
                            <div className="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h6 className="fw-bold text-dark mb-0">Live GPS Dispatch Map</h6>
                                    <small className="text-muted">Interactive OpenStreetMap view of courier & Kano route</small>
                                </div>
                                <span className="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                                    🟢 Auto-updating
                                </span>
                            </div>

                            <div style={{ height: '340px', borderRadius: '10px', overflow: 'hidden', border: '1px solid #e2e8f0' }}>
                                <LiveLocationMap
                                    location={liveLocation || (selectedDelivery.driver_latitude ? {
                                        latitude: selectedDelivery.driver_latitude,
                                        longitude: selectedDelivery.driver_longitude,
                                        heading: selectedDelivery.driver_heading || 0
                                    } : null)}
                                    pickup={selectedDelivery.pickup_latitude ? {
                                        lat: selectedDelivery.pickup_latitude,
                                        lng: selectedDelivery.pickup_longitude,
                                        label: selectedDelivery.pickup_address
                                    } : null}
                                    destination={selectedDelivery.delivery_latitude ? {
                                        lat: selectedDelivery.delivery_latitude,
                                        lng: selectedDelivery.delivery_longitude,
                                        label: selectedDelivery.delivery_address
                                    } : null}
                                    height={340}
                                />
                            </div>

                            <div className="d-flex justify-content-between align-items-center mt-3 pt-2 border-top small text-muted">
                                <span>📍 Pickup: {selectedDelivery.pickup_address?.slice(0, 30)}...</span>
                                <span>🏁 Dropoff: {selectedDelivery.delivery_address?.slice(0, 30)}...</span>
                            </div>
                        </div>

                        {/* Progression Stepper Timeline */}
                        <div className="card border-0 shadow-sm custom-card p-4 mb-4">
                            <h6 className="fw-bold text-dark mb-3">Delivery Progress Timeline</h6>
                            <div className="tracking-stepper">
                                <div className={`tracking-node ${currentIdx >= 0 ? 'done' : ''}`}>
                                    <div className="circle">{currentIdx >= 0 ? '✓' : '1'}</div>
                                    <div className="label">Order Placed</div>
                                    <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                        {selectedDelivery.request_time ? new Date(selectedDelivery.request_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                                    </small>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 3 ? 'done' : (currentIdx >= 1 ? 'current' : '')}`}>
                                    <div className="circle">{currentIdx >= 3 ? '✓' : '2'}</div>
                                    <div className="label">Driver Assigned</div>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 5 ? 'done' : (currentIdx === 4 ? 'current' : '')}`}>
                                    <div className="circle">{currentIdx >= 5 ? '✓' : '3'}</div>
                                    <div className="label">Picked Up</div>
                                    <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                        {selectedDelivery.pickup_time ? new Date(selectedDelivery.pickup_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                                    </small>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 7 ? 'done' : (currentIdx === 6 ? 'current' : '')}`}>
                                    <div className="circle">{currentIdx >= 7 ? '✓' : '4'}</div>
                                    <div className="label">In Transit</div>
                                </div>
                                <div className={`tracking-node ${currentIdx >= 8 ? 'done' : ''}`}>
                                    <div className="circle">{currentIdx >= 8 ? '✓' : '5'}</div>
                                    <div className="label">Delivered</div>
                                    <small className="text-muted d-block" style={{ fontSize: '10px' }}>
                                        {selectedDelivery.delivery_time ? new Date(selectedDelivery.delivery_time).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Right Column: Driver, OTP & POD, Shipment Details */}
                    <div className="col-lg-4">
                        {/* ETA & Status Banner */}
                        <div className="card border-0 shadow-sm custom-card p-3 mb-4 border-start border-4 border-primary">
                            <small className="text-muted text-uppercase fw-bold d-block" style={{ fontSize: '0.72rem' }}>
                                {eta.label}
                            </small>
                            <div className={`fw-bold fs-6 mt-1 ${eta.isFinal ? 'text-success' : 'text-dark'}`}>
                                {eta.text}
                            </div>
                        </div>

                        {/* Handover OTP or Proof of Delivery */}
                        {!isDelivered ? (
                            <div className="card border-0 shadow-sm custom-card p-3 mb-4 bg-primary-subtle border border-primary-subtle">
                                <div className="d-flex justify-content-between align-items-center mb-2">
                                    <h6 className="fw-bold text-primary mb-0" style={{ fontSize: '0.9rem' }}>
                                        🔐 Recipient Handover OTP
                                    </h6>
                                    {selectedDelivery.delivery_otp && (
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline-primary py-0 px-2"
                                            style={{ fontSize: '0.72rem' }}
                                            onClick={handleCopyOtp}
                                        >
                                            {copiedOtp ? '✓ Copied' : 'Copy'}
                                        </button>
                                    )}
                                </div>
                                <div className="display-6 fw-bold text-primary text-center my-2 font-monospace" style={{ letterSpacing: '4px' }}>
                                    {selectedDelivery.delivery_otp || '----'}
                                </div>
                                <small className="text-muted d-block text-center" style={{ fontSize: '0.75rem' }}>
                                    Provide this 4-digit code to the rider upon package arrival to confirm handover.
                                </small>
                            </div>
                        ) : (
                            <div className="card border-0 shadow-sm custom-card p-3 mb-4 bg-success-subtle border border-success-subtle">
                                <div className="d-flex justify-content-between align-items-center mb-2">
                                    <h6 className="fw-bold text-success mb-0" style={{ fontSize: '0.9rem' }}>
                                        ✓ Proof of Delivery Verified
                                    </h6>
                                    <button
                                        className="btn btn-sm btn-outline-success py-0 px-2"
                                        style={{ fontSize: '0.72rem' }}
                                        onClick={() => window.print()}
                                    >
                                        🖨️ Print
                                    </button>
                                </div>
                                <p className="small text-muted mb-2">
                                    Successfully received by <strong>{selectedDelivery.recipient_name || selectedDelivery.delivery_contact_name || 'Authorized Recipient'}</strong>
                                </p>
                                <small className="text-muted d-block" style={{ fontSize: '0.75rem' }}>
                                    Timestamp: {selectedDelivery.delivery_time ? new Date(selectedDelivery.delivery_time).toLocaleString() : 'Recently'}
                                </small>
                            </div>
                        )}

                        {/* Driver Information (if assigned) */}
                        <div className="card border-0 shadow-sm custom-card p-3 mb-4">
                            <h6 className="fw-bold text-dark mb-3" style={{ fontSize: '0.9rem' }}>
                                🛵 Dispatch Courier
                            </h6>
                            {selectedDelivery.driver_name ? (
                                <div>
                                    <div className="d-flex align-items-center gap-3 mb-3">
                                        <div
                                            className="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fw-bold"
                                            style={{ width: '44px', height: '44px', fontSize: '1.1rem' }}
                                        >
                                            {selectedDelivery.driver_name.charAt(0)}
                                        </div>
                                        <div>
                                            <div className="fw-bold text-dark">{selectedDelivery.driver_name}</div>
                                            <small className="text-muted">
                                                {selectedDelivery.vehicle_type || 'Motorcycle'} · {selectedDelivery.license_plate || 'Kano Dispatch'}
                                            </small>
                                        </div>
                                    </div>
                                    {selectedDelivery.driver_phone && (
                                        <a
                                            href={`tel:${selectedDelivery.driver_phone}`}
                                            className="btn btn-sm btn-outline-primary w-100 fw-semibold"
                                        >
                                            📞 Call Rider ({selectedDelivery.driver_phone})
                                        </a>
                                    )}
                                </div>
                            ) : (
                                <div className="text-muted small py-2">
                                    A dispatch rider is currently being allocated by the Kano operations center.
                                </div>
                            )}
                        </div>

                        {/* Shipment Cargo & Cost Info */}
                        <div className="card border-0 shadow-sm custom-card p-3">
                            <h6 className="fw-bold text-dark mb-3" style={{ fontSize: '0.9rem' }}>
                                📦 Package & Fare Details
                            </h6>
                            <ul className="list-group list-group-flush small">
                                <li className="list-group-item px-0 py-2 d-flex justify-content-between">
                                    <span className="text-muted">Description:</span>
                                    <span className="fw-semibold text-dark">{selectedDelivery.item_description}</span>
                                </li>
                                <li className="list-group-item px-0 py-2 d-flex justify-content-between">
                                    <span className="text-muted">Weight / Category:</span>
                                    <span className="fw-semibold text-dark">{selectedDelivery.item_weight}kg · {selectedDelivery.item_category}</span>
                                </li>
                                <li className="list-group-item px-0 py-2 d-flex justify-content-between">
                                    <span className="text-muted">Service Type:</span>
                                    <span className="fw-semibold text-dark">{selectedDelivery.service_type?.replace('_', ' ') || 'Standard'}</span>
                                </li>
                                <li className="list-group-item px-0 py-2 d-flex justify-content-between">
                                    <span className="text-muted">Total Fare:</span>
                                    <span className="fw-bold text-dark fs-6">₦{Number(selectedDelivery.total_cost || 0).toLocaleString()}</span>
                                </li>
                                <li className="list-group-item px-0 py-2 d-flex justify-content-between">
                                    <span className="text-muted">Payment Status:</span>
                                    <span className={`badge ${selectedDelivery.payment_status === 'paid' ? 'bg-success' : 'bg-warning text-dark'}`}>
                                        {selectedDelivery.payment_status || 'Paid'}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ClientTrackingTab;
