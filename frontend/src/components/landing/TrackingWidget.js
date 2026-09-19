import React, { useState } from 'react';
import { apiClient, authHeaders } from '../../api/client';

/**
 * Formats a timestamp into a friendly relative time difference.
 * e.g., "just now", "4 minutes ago", "1 hour ago", "yesterday"
 */
const formatRelativeTime = (timestamp) => {
    if (!timestamp) return null;
    const date = new Date(timestamp);
    if (isNaN(date.getTime())) return null;

    const diffSeconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (diffSeconds < 45) return 'just now';
    const diffMinutes = Math.floor(diffSeconds / 60);
    if (diffMinutes === 1) return '1 minute ago';
    if (diffMinutes < 60) return `${diffMinutes} minutes ago`;

    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours === 1) return '1 hour ago';
    if (diffHours < 24) return `${diffHours} hours ago`;

    const diffDays = Math.floor(diffHours / 24);
    if (diffDays === 1) return 'yesterday';
    return `${diffDays} days ago`;
};

/**
 * Computes a friendly relative status estimate headline and contextual detail.
 * e.g. headline: "Driver assigned 4 minutes ago"
 */
const getFriendlyStatusEstimate = (delivery) => {
    if (!delivery) return null;
    const { status } = delivery;

    switch (status) {
        case 'assigned':
        case 'driver_en_route': {
            const timeStr = formatRelativeTime(
                delivery.assigned_at || delivery.last_location_at || delivery.request_time
            );
            return {
                headline: `Driver assigned ${timeStr || 'recently'}`,
                subtext: delivery.delivery_person_name
                    ? `${delivery.delivery_person_name} is dispatched and navigating to pickup location.`
                    : 'A verified Kano dispatch rider has accepted the delivery and is en route.',
                badge: 'Driver Assigned',
                isLive: true,
            };
        }
        case 'picked_up': {
            const timeStr = formatRelativeTime(
                delivery.picked_up_at || delivery.pickup_time || delivery.last_location_at
            );
            return {
                headline: `Package picked up ${timeStr || 'recently'}`,
                subtext: 'Cargo verified and secured. Driver is departing pickup origin.',
                badge: 'Cargo Secured',
                isLive: true,
            };
        }
        case 'in_transit': {
            const timeStr = formatRelativeTime(
                delivery.in_transit_at || delivery.tracking_started_at || delivery.picked_up_at || delivery.last_location_at
            );
            return {
                headline: `Package in transit (${timeStr ? `departed ${timeStr}` : 'live'})`,
                subtext: 'Shipment is moving directly toward the delivery destination.',
                badge: 'Live In Transit',
                isLive: true,
            };
        }
        case 'arrived': {
            const timeStr = formatRelativeTime(delivery.last_location_at || delivery.in_transit_at);
            return {
                headline: `Driver arrived at destination ${timeStr || 'just now'}`,
                subtext: 'Driver is at drop-off point awaiting delivery OTP verification.',
                badge: 'At Destination',
                isLive: true,
            };
        }
        case 'delivered':
        case 'completed': {
            const timeStr = formatRelativeTime(
                delivery.delivered_at || delivery.delivery_time || delivery.last_location_at
            );
            return {
                headline: `Delivered & confirmed ${timeStr || 'successfully'}`,
                subtext: 'Receipt verified with digital OTP. Shipment successfully completed.',
                badge: 'Completed',
                isLive: false,
            };
        }
        case 'cancelled':
        case 'rejected':
        case 'failed': {
            return {
                headline: `Shipment ${status}`,
                subtext: delivery.status_reason || 'This delivery request has been closed.',
                badge: 'Closed',
                isLive: false,
            };
        }
        case 'pending':
        case 'under_review':
        case 'broadcasted':
        default: {
            const timeStr = formatRelativeTime(delivery.request_time);
            return {
                headline: `Order placed ${timeStr || 'recently'}`,
                subtext: 'Order received into central dispatch. Searching for nearby available Kano riders.',
                badge: 'Searching Riders',
                isLive: true,
            };
        }
    }
};

const TrackingWidget = () => {
    const [trackingInput, setTrackingInput] = useState('');
    const [phoneInput, setPhoneInput] = useState('');
    const [showPhoneVerify, setShowPhoneVerify] = useState(false);
    const [loading, setLoading] = useState(false);
    const [delivery, setDelivery] = useState(null);
    const [error, setError] = useState('');

    const handleSearch = async (e) => {
        if (e) e.preventDefault();
        const query = trackingInput.trim();
        if (!query) {
            setError('Please enter a valid tracking number or tracking token.');
            return;
        }

        // Direct numeric inputs / sequential order IDs are not allowed on the public portal
        if (/^#?\d+$/.test(query)) {
            setError('Direct numeric order IDs cannot be used for public tracking. Please enter your full tracking reference (e.g. TIL-2026-X8K9-M4PQ) or 32-character tracking token.');
            return;
        }

        setLoading(true);
        setError('');
        setDelivery(null);

        try {
            let url = `/deliveries/track?id=${encodeURIComponent(query)}`;
            if (phoneInput.trim()) {
                url += `&phone=${encodeURIComponent(phoneInput.trim())}`;
            }

            const res = await apiClient.get(url, { headers: authHeaders() });
            if (res.data && res.data.data) {
                setDelivery(res.data.data);
            } else {
                setError('No shipment record found for that tracking reference.');
            }
        } catch (err) {
            setError(err.response?.data?.message || 'Shipment record not found. Please verify your tracking reference number.');
        } finally {
            setLoading(false);
        }
    };

    const statuses = ['pending', 'under_review', 'broadcasted', 'assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered', 'completed'];
    const currentIdx = delivery ? statuses.indexOf(delivery.status) : -1;
    const estimate = delivery ? getFriendlyStatusEstimate(delivery) : null;

    // Active stage checks for pulsating green glow micro-interaction
    const isPickedUpActive = delivery?.status === 'picked_up';
    const isInTransitActive = delivery?.status === 'in_transit' || delivery?.status === 'arrived';
    const isDriverAssignedActive = delivery?.status === 'assigned' || delivery?.status === 'driver_en_route';

    return (
        <section id="track" className="section-spacing">
            <div className="page-container">
                <div className="section-header text-center">
                    <div className="section-eyebrow">
                        <span>Real-Time Visibility</span>
                    </div>
                    <h2 className="section-heading">Track Your Delivery</h2>
                    <p className="section-lead">
                        Enter your unique tracking reference number to see live status updates, assigned driver details, and delivery milestones.
                    </p>
                </div>

                {/* Tracker Search Box */}
                <div className="row justify-content-center">
                    <div className="col-lg-8">
                        <div className="glass-card p-4 p-md-5">
                            <form onSubmit={handleSearch} className="mb-3">
                                <div className="tracker-input-group mb-2">
                                    <label htmlFor="tracking-reference" className="visually-hidden">Tracking reference or tracking token</label>
                                    <input 
                                        id="tracking-reference"
                                        type="text" 
                                        className="form-control tracker-input" 
                                        placeholder="Enter Tracking ID (e.g. TIL-2026-X8K9-M4PQ or tracking token)" 
                                        value={trackingInput}
                                        onChange={(e) => setTrackingInput(e.target.value)}
                                    />
                                    <button className="btn-brand-primary" type="submit" disabled={loading}>
                                        {loading ? 'Searching...' : 'Track Shipment →'}
                                    </button>
                                </div>

                                {/* Phone verification option */}
                                <div className="d-flex justify-content-between align-items-center flex-wrap gap-2 px-1">
                                    <button 
                                        type="button" 
                                        className="btn btn-link btn-sm p-0 text-decoration-none text-muted"
                                        onClick={() => setShowPhoneVerify(!showPhoneVerify)}
                                    >
                                        {showPhoneVerify ? '− Hide phone verification' : '+ Are you the sender or recipient? Unlock full details'}
                                    </button>
                                </div>

                                {showPhoneVerify && (
                                    <div className="mt-2 p-3 bg-light rounded border">
                                        <label htmlFor="tracking-phone" className="form-label small text-muted mb-1">
                                             Sender or Recipient Phone Number (to reveal unmasked contact details)
                                        </label>
                                        <input 
                                            id="tracking-phone"
                                            type="tel"
                                            className="form-control form-control-sm" 
                                            placeholder="e.g. +234 803 000 0000 or 08030000000"
                                            value={phoneInput}
                                            onChange={(e) => setPhoneInput(e.target.value)}
                                        />
                                    </div>
                                )}
                            </form>

                            {error && (
                                <div className="alert alert-danger py-2 text-center small mb-4">
                                    {error}
                                </div>
                            )}

                            {/* Tracking Result Card */}
                            {delivery && (
                                <div className="p-4 rounded border bg-white shadow-sm mt-3">
                                    <div className="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4 pb-3 border-bottom">
                                        <div>
                                            <span className="badge bg-danger me-2">
                                                Tracking Ref: {delivery.tracking_number || (delivery.public_tracking_token ? `${delivery.public_tracking_token.substring(0, 12)}…` : 'Shipment')}
                                            </span>
                                            <span className={`status-pill status-${delivery.status} me-2`}>
                                                {delivery.status.replace('_', ' ')}
                                            </span>
                                            {delivery.is_verified_viewer ? (
                                                <span className="badge bg-success" title="Full verified access">✓ Verified Stakeholder</span>
                                            ) : (
                                                <span className="badge bg-secondary" title="Public view with PII protection">🔒 Privacy Protected</span>
                                            )}
                                        </div>
                                        <small className="text-muted">
                                            Updated: {new Date(delivery.last_location_at || delivery.request_time || Date.now()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                        </small>
                                    </div>

                                    {!delivery.is_verified_viewer && (
                                        <div className="alert alert-info py-2 px-3 small d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                            <span>🔒 Contact details are masked to protect user privacy.</span>
                                            {!showPhoneVerify && (
                                                <button 
                                                    type="button" 
                                                    className="btn btn-sm btn-outline-primary py-0 px-2"
                                                    onClick={() => setShowPhoneVerify(true)}
                                                >
                                                    Verify Phone to Unlock
                                                </button>
                                            )}
                                        </div>
                                    )}

                                    {/* Friendly Relative Status Estimate Banner */}
                                    {estimate && (
                                        <div className="tracking-estimate-banner">
                                            <div className="d-flex align-items-center gap-3">
                                                <span className={`pulse-beacon-dot ${!estimate.isLive ? 'bg-secondary' : ''}`} />
                                                <div>
                                                    <div className="fw-bold text-dark fs-6 mb-0">
                                                        {estimate.headline}
                                                    </div>
                                                    <small className="text-muted d-block">
                                                        {estimate.subtext}
                                                    </small>
                                                </div>
                                            </div>
                                            <span className="tracking-estimate-badge">
                                                {estimate.isLive ? '● Live' : '✓ Done'} {estimate.badge}
                                            </span>
                                        </div>
                                    )}

                                    {/* Milestone Progress Stepper with Pulsating Green Glow on active stages */}
                                    <div className="tracking-stepper mb-4" aria-label="Delivery progress milestones">
                                        {/* Stage 1: Order Received */}
                                        <div className={`tracking-node ${currentIdx > 2 ? 'done' : 'current'}`}>
                                            <div className="circle">{currentIdx > 2 ? '✓' : '1'}</div>
                                            <div className="label">Order Received</div>
                                            {delivery.request_time && (
                                                <span className="tracking-node-time text-muted">
                                                    {formatRelativeTime(delivery.request_time)}
                                                </span>
                                            )}
                                        </div>

                                        {/* Stage 2: Driver Assigned */}
                                        <div className={`tracking-node ${currentIdx >= 5 ? 'done' : (isDriverAssignedActive ? 'current pulsing-glow' : (currentIdx >= 3 ? 'current' : ''))}`}>
                                            <div className="circle">{currentIdx >= 5 ? '✓' : '2'}</div>
                                            <div className="label">Driver Assigned</div>
                                            {isDriverAssignedActive && (
                                                <span className="tracking-node-time text-success fw-bold">
                                                    ● {formatRelativeTime(delivery.assigned_at || delivery.request_time) || 'Active'}
                                                </span>
                                            )}
                                            {!isDriverAssignedActive && delivery.assigned_at && currentIdx >= 5 && (
                                                <span className="tracking-node-time text-muted">
                                                    {formatRelativeTime(delivery.assigned_at)}
                                                </span>
                                            )}
                                        </div>

                                        {/* Stage 3: Picked Up (Pulsating green glow when active) */}
                                        <div className={`tracking-node ${currentIdx >= 6 ? 'done' : (isPickedUpActive ? 'current pulsing-glow' : '')}`}>
                                            <div className="circle">{currentIdx >= 6 ? '✓' : '3'}</div>
                                            <div className="label">Picked Up</div>
                                            {isPickedUpActive && (
                                                <span className="tracking-node-time text-success fw-bold">
                                                    ● {formatRelativeTime(delivery.picked_up_at || delivery.pickup_time) || 'Active'}
                                                </span>
                                            )}
                                            {!isPickedUpActive && (delivery.picked_up_at || delivery.pickup_time) && currentIdx >= 6 && (
                                                <span className="tracking-node-time text-muted">
                                                    {formatRelativeTime(delivery.picked_up_at || delivery.pickup_time)}
                                                </span>
                                            )}
                                        </div>

                                        {/* Stage 4: In Transit (Pulsating green glow when active) */}
                                        <div className={`tracking-node ${currentIdx >= 8 ? 'done' : (isInTransitActive ? 'current pulsing-glow' : '')}`}>
                                            <div className="circle">{currentIdx >= 8 ? '✓' : '4'}</div>
                                            <div className="label">In Transit</div>
                                            {isInTransitActive && (
                                                <span className="tracking-node-time text-success fw-bold">
                                                    ● {formatRelativeTime(delivery.in_transit_at || delivery.tracking_started_at || delivery.picked_up_at) || 'In Motion'}
                                                </span>
                                            )}
                                            {!isInTransitActive && (delivery.in_transit_at || delivery.tracking_started_at) && currentIdx >= 8 && (
                                                <span className="tracking-node-time text-muted">
                                                    {formatRelativeTime(delivery.in_transit_at || delivery.tracking_started_at)}
                                                </span>
                                            )}
                                        </div>

                                        {/* Stage 5: Delivered */}
                                        <div className={`tracking-node ${currentIdx >= 8 ? 'done' : ''}`}>
                                            <div className="circle">{currentIdx >= 8 ? '✓' : '5'}</div>
                                            <div className="label">Delivered</div>
                                            {currentIdx >= 8 && (delivery.delivered_at || delivery.delivery_time) && (
                                                <span className="tracking-node-time text-success">
                                                    {formatRelativeTime(delivery.delivered_at || delivery.delivery_time)}
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Details Grid */}
                                    <div className="row g-3 bg-light p-3 rounded">
                                        <div className="col-md-6">
                                            <small className="text-muted d-block">Cargo / Item:</small>
                                            <strong className="text-dark">{delivery.item_description}</strong>
                                        </div>
                                        <div className="col-md-6">
                                            <small className="text-muted d-block">Assigned Partner:</small>
                                            <strong className="text-dark">
                                                {delivery.delivery_person_name || 'Awaiting central dispatch'}
                                                {delivery.delivery_person_phone ? ` (${delivery.delivery_person_phone})` : ''}
                                            </strong>
                                        </div>
                                        <div className="col-md-6">
                                            <small className="text-muted d-block">Pickup Location:</small>
                                            <div className="text-dark small">📍 {delivery.pickup_address} {delivery.pickup_contact_name ? `(${delivery.pickup_contact_name})` : ''}</div>
                                        </div>
                                        <div className="col-md-6">
                                            <small className="text-muted d-block">Destination:</small>
                                            <div className="text-dark small">🏁 {delivery.delivery_address} {delivery.delivery_contact_name ? `(${delivery.delivery_contact_name})` : ''}</div>
                                        </div>
                                        <div className="col-12 border-top pt-3 mt-1">
                                            <small className="text-muted d-block">Live GPS status:</small>
                                            {delivery.status === 'in_transit' && delivery.last_location_latitude && delivery.last_location_longitude ? (
                                                <strong className="text-success">● Driver location received {delivery.last_location_at ? `at ${new Date(delivery.last_location_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}</strong>
                                            ) : delivery.status === 'in_transit' ? (
                                                <span className="text-muted">Driver is in transit; the next GPS update will appear here.</span>
                                            ) : (
                                                <span className="text-muted">Live GPS becomes available after pickup and transit begins.</span>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
};

export default TrackingWidget;
