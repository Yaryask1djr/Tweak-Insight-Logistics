import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { showToast } from '../common/Toast';
import { CardGridSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';
import { triggerOtpSuccessHaptic, triggerActionTapHaptic } from '../../utils/haptics';

const mapSearchUrl = address =>
    `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;

const SlideToConfirm = ({ onConfirm, disabled }) => {
    const [value, setValue] = useState(0);
    const sent = useRef(false);

    useEffect(() => {
        if (!disabled) {
            setValue(0);
            sent.current = false;
        }
    }, [disabled]);

    const change = event => {
        const next = Number(event.target.value);
        setValue(next);
        if (next >= 92 && !sent.current) {
            sent.current = true;
            onConfirm();
        }
    };

    return (
        <label className={`driver-slide-confirm ${disabled ? 'is-loading' : ''}`}>
            <span className="visually-hidden">Slide to confirm pickup</span>
            <input
                type="range"
                min="0"
                max="100"
                value={value}
                disabled={disabled}
                onChange={change}
                aria-valuetext={value >= 92 ? 'Pickup confirmed' : 'Slide right to confirm pickup'}
            />
            <span className="driver-slide-confirm__label">
                {disabled ? 'Confirming pickup…' : 'Slide to confirm pickup  →'}
            </span>
        </label>
    );
};

const DELIVERY_STEPS = [
    { key: 'assigned', label: 'Assigned' },
    { key: 'driver_en_route', label: 'En Route' },
    { key: 'picked_up', label: 'Picked Up' },
    { key: 'in_transit', label: 'In Transit' },
    { key: 'arrived', label: 'Arrived' },
];

const DeliveryStatusTimeline = ({ currentStatus }) => {
    const stepOrder = ['assigned', 'driver_en_route', 'picked_up', 'in_transit', 'arrived', 'delivered'];
    const currentIndex = stepOrder.indexOf(currentStatus);

    return (
        <div className="delivery-status-timeline mb-3 py-2 px-1">
            <div className="d-flex justify-content-between position-relative">
                <div 
                    className="position-absolute bg-secondary opacity-25" 
                    style={{ top: '10px', left: '10%', right: '10%', height: '2px', zIndex: 0 }} 
                />
                {DELIVERY_STEPS.map((step, idx) => {
                    const isCompleted = currentIndex > idx;
                    const isCurrent = currentIndex === idx;
                    return (
                        <div key={step.key} className="text-center position-relative" style={{ zIndex: 1, flex: 1 }}>
                            <div 
                                className={`rounded-circle mx-auto d-flex align-items-center justify-content-center ${
                                    isCompleted 
                                        ? 'bg-success text-white' 
                                        : isCurrent 
                                        ? 'bg-primary text-white shadow-sm' 
                                        : 'bg-light text-muted border'
                                }`}
                                style={{ width: '22px', height: '22px', fontSize: '11px', fontWeight: 'bold' }}
                            >
                                {isCompleted ? '✓' : idx + 1}
                            </div>
                            <span 
                                className={`d-block small mt-1 ${
                                    isCurrent ? 'fw-bold text-primary' : isCompleted ? 'text-dark' : 'text-muted'
                                }`}
                                style={{ fontSize: '0.68rem', lineHeight: '1.1' }}
                            >
                                {step.label}
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
};

const ProofOfDeliverySheet = ({ delivery, submitting, onClose, onSubmit }) => {
    const [otp, setOtp] = useState('');
    const [recipientName, setRecipientName] = useState(delivery?.client_name || '');
    const [signatureConfirmed, setSignatureConfirmed] = useState(true);
    const [gpsCoords, setGpsCoords] = useState(null);
    const ref = useRef(null);

    useEffect(() => {
        ref.current?.focus();
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                (pos) => setGpsCoords({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
                () => setGpsCoords({ error: true })
            );
        }
    }, []);

    const nowFormatted = new Date().toLocaleString('en-NG', { 
        dateStyle: 'medium', 
        timeStyle: 'short' 
    });

    return (
        <div
            className="driver-otp-sheet"
            role="dialog"
            aria-modal="true"
            aria-labelledby="delivery-otp-title"
        >
            <form
                className="driver-otp-sheet__panel"
                onSubmit={event => {
                    event.preventDefault();
                    if (otp.trim()) onSubmit(delivery.id, otp.trim());
                }}
            >
                <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
                    <div>
                        <span className="badge bg-success-subtle text-success border border-success-subtle mb-1">
                            Proof of Delivery (POD)
                        </span>
                        <h5 id="delivery-otp-title" className="fw-bold mb-1">
                            Order #{delivery.id} ({delivery.tracking_number || 'TIL-2026'})
                        </h5>
                        <p className="text-muted small mb-0">
                            Verify 6-digit confirmation code with the recipient.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="btn-close driver-tap-target--icon"
                        aria-label="Close OTP entry"
                        disabled={submitting}
                        onClick={onClose}
                    />
                </div>

                {/* 1. OTP Verification Input */}
                <div className="mb-3">
                    <label className="form-label small fw-bold text-dark mb-1">
                        🔑 Recipient Delivery OTP Code
                    </label>
                    <input
                        ref={ref}
                        className="form-control driver-otp-input text-center fw-bold fs-4"
                        value={otp}
                        onChange={event =>
                            setOtp(event.target.value.replace(/\D/g, '').slice(0, 10))
                        }
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        placeholder="Enter 6-digit OTP"
                        style={{ letterSpacing: '4px' }}
                    />
                </div>

                {/* 2. Recipient Confirmation & Details */}
                <div className="mb-3">
                    <label className="form-label small fw-semibold text-muted mb-1">
                        👤 Recipient Confirmation Name
                    </label>
                    <input
                        type="text"
                        className="form-control form-control-sm"
                        value={recipientName}
                        onChange={e => setRecipientName(e.target.value)}
                        placeholder="Recipient full name"
                    />
                </div>

                {/* 3. Photo / Signature Confirmation Note */}
                <div className="p-2 bg-light rounded border mb-3">
                    <div className="form-check">
                        <input
                            type="checkbox"
                            className="form-check-input"
                            id="sigConfirmCheck"
                            checked={signatureConfirmed}
                            onChange={e => setSignatureConfirmed(e.target.checked)}
                        />
                        <label className="form-check-label small fw-semibold text-dark" htmlFor="sigConfirmCheck">
                            ✍️ Recipient verified & goods handed over in good order
                        </label>
                    </div>
                </div>

                {/* 4. Timestamp & 5. GPS Confirmation Badges */}
                <div className="d-flex justify-content-between align-items-center small text-muted bg-light p-2 rounded mb-3">
                    <div>
                        🕒 <strong>Time:</strong> {nowFormatted}
                    </div>
                    <div>
                        📍 <strong>GPS:</strong> {gpsCoords?.lat ? `${gpsCoords.lat.toFixed(4)}°N, ${gpsCoords.lng.toFixed(4)}°E` : 'Kano Localized'}
                    </div>
                </div>

                <button
                    className="btn btn-success driver-tap-target driver-action-btn w-100 fw-bold shadow-sm"
                    style={{ minHeight: '54px', fontSize: '1.05rem' }}
                    disabled={submitting || !otp.trim()}
                >
                    {submitting ? 'Verifying OTP & Closing Order…' : '✅ Verify Delivery OTP & Confirm'}
                </button>
            </form>
        </div>
    );
};

const ActiveDeliveriesTab = () => {
    const [trackingId, setTrackingId] = useState(null);
    const [trackingMessage, setTrackingMessage] = useState('');
    const [otpId, setOtpId] = useState(null);
    // Inline issue-report state (replaces window.prompt)
    const [reportId, setReportId] = useState(null);
    const [reportText, setReportText] = useState('');
    const watch = useRef(null);
    const queryClient = useQueryClient();

    const assignments = useQuery({
        queryKey: ['driver', 'active-assignments'],
        queryFn: () => apiGet('/delivery-person/my-assignments?status=all'),
        select: response =>
            (response.data || []).filter(
                item => item.status !== 'delivered' && item.status !== 'cancelled'
            ),
    });

    const invalidate = () =>
        queryClient.invalidateQueries({ queryKey: ['driver', 'active-assignments'] });

    const stopTracking = () => {
        if (watch.current !== null) navigator.geolocation?.clearWatch(watch.current);
        watch.current = null;
        setTrackingId(null);
    };

    useEffect(() => () => stopTracking(), []);

    const status = useMutation({
        mutationFn: ({ id, next }) =>
            apiPost('/delivery-person/update-status', { delivery_id: id, status: next }),
        onSuccess: (_, { id, next }) => {
            showToast.success(`Status updated to '${next.replace('_', ' ')}'`);
            if (next === 'in_transit') startTracking(id);
            invalidate();
        },
        onError: () => showToast.error('Failed to update status.'),
    });

    const location = useMutation({
        mutationFn: ({ id, position }) =>
            apiPost('/delivery-person/update-location', {
                delivery_id: id,
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy_m: position.coords.accuracy,
                heading_degrees: Number.isFinite(position.coords.heading)
                    ? position.coords.heading
                    : null,
            }),
        onError: () =>
            setTrackingMessage(
                'Unable to send this GPS update. Retrying on the next location change.'
            ),
    });

    const startTracking = id => {
        if (!navigator.geolocation) {
            setTrackingMessage(
                'GPS is unavailable in this browser. Use a location-enabled driver device.'
            );
            return;
        }
        if (watch.current !== null) navigator.geolocation.clearWatch(watch.current);
        setTrackingId(id);
        setTrackingMessage(
            'Live GPS tracking is active. Your location is shared only with the client and operations while this delivery is active.'
        );
        watch.current = navigator.geolocation.watchPosition(
            position => location.mutate({ id, position }),
            error => setTrackingMessage(`GPS tracking needs permission: ${error.message}`),
            { enableHighAccuracy: true, maximumAge: 15_000, timeout: 20_000 }
        );
    };

    const receipt = useMutation({
        mutationFn: ({ id, otp }) =>
            apiPost('/deliveries/confirm-receipt', { delivery_id: id, otp }),
        onSuccess: () => {
            // Trigger mobile browser haptic vibration confirmation [100, 50, 100]
            triggerOtpSuccessHaptic();
            showToast.success('Proof of delivery recorded. Delivery is now closed.');
            stopTracking();
            setOtpId(null);
            invalidate();
        },
        onError: error =>
            showToast.error(error.response?.data?.message || 'Could not confirm delivery.'),
    });

    const report = id => {
        setReportId(id);
        setReportText('');
    };

    const submitReport = () => {
        const trimmed = reportText.trim();
        if (!trimmed) {
            showToast.error('Please describe the issue before submitting.');
            return;
        }
        apiPost('/delivery-person/report-issue', { delivery_id: reportId, reason: trimmed })
            .then(() => {
                showToast.info('Operations has been notified of the issue.');
                setReportId(null);
                setReportText('');
            })
            .catch(() => showToast.error('Could not report this issue.'));
    };

    const rows = assignments.data || [];
    const busy = id => status.isPending && status.variables?.id === id;

    if (assignments.isError && !assignments.data) {
        return <QueryState query={assignments} loading={<CardGridSkeleton count={2} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4 driver-active-panel">
            <h4 className="fw-bold mb-3">My Active Assignments</h4>

            {trackingMessage && (
                <div className="alert alert-success py-2 mb-3">{trackingMessage}</div>
            )}

            {assignments.isLoading ? (
                <CardGridSkeleton count={2} />
            ) : rows.length === 0 ? (
                <div className="alert alert-light text-center py-4 border">
                    <p className="text-muted mb-0">
                        No active delivery assignments. Accept a job from the Available tab!
                    </p>
                </div>
            ) : (
                <div className="row g-3">
                    {rows.map(item => (
                        <div key={item.id} className="col-md-6">
                            <div className="card h-100 border-0 shadow-sm custom-card p-3 driver-job-card">
                                <div className="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <span className="fw-bold text-dark">Order #{item.id}</span>
                                        {item.tracking_number && (
                                            <div className="small text-muted" style={{ fontFamily: 'monospace' }}>
                                                {item.tracking_number}
                                            </div>
                                        )}
                                    </div>
                                    <div className="d-flex flex-column align-items-end gap-1">
                                        <span className={`status-pill status-${item.status}`}>
                                            {item.status.replaceAll('_', ' ')}
                                        </span>
                                        <span className="badge bg-success-subtle text-success border border-success-subtle" style={{ fontSize: '0.7rem' }}>
                                            {(() => {
                                                if (item.status === 'arrived') return 'At destination';
                                                if (item.status === 'in_transit') return `ETA ~${Math.max(8, Math.round(Number(item.distance_km || 5) * 3))} min`;
                                                if (item.status === 'picked_up') return 'Departing pickup';
                                                if (item.status === 'driver_en_route') return 'En route to pickup';
                                                return 'Assigned';
                                            })()}
                                        </span>
                                    </div>
                                </div>

                                {/* Delivery Status Timeline */}
                                <DeliveryStatusTimeline currentStatus={item.status} />

                                <hr className="my-2" />

                                <div className="mb-2">
                                    <strong>{item.item_description}</strong>
                                    <div className="small text-muted">
                                        Client: {item.client_name}
                                    </div>
                                </div>

                                <div className="driver-stop-list mb-3">
                                    <div>
                                        <span aria-hidden="true">📍</span>
                                        <div>
                                            <small>Pickup</small>
                                            <strong>{item.pickup_address}</strong>
                                        </div>
                                    </div>
                                    <div>
                                        <span aria-hidden="true">🏁</span>
                                        <div>
                                            <small>Destination</small>
                                            <strong>{item.delivery_address}</strong>
                                        </div>
                                    </div>
                                </div>

                                <div className="driver-quick-actions mb-3">
                                    <a
                                        className="btn btn-outline-success driver-tap-target"
                                        href={`tel:${String(item.client_phone || '').replace(
                                            /[^+\d]/g,
                                            ''
                                        )}`}
                                    >
                                        📞 Call client
                                    </a>
                                    <a
                                        className="btn btn-outline-primary driver-tap-target"
                                        href={mapSearchUrl(
                                            ['assigned', 'driver_en_route'].includes(item.status)
                                                ? item.pickup_address
                                                : item.delivery_address
                                        )}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        🧭 Directions
                                    </a>
                                </div>

                                {item.status === 'assigned' && (
                                    <button
                                        className="btn btn-warning w-100 fw-bold driver-tap-target driver-action-btn shadow-sm"
                                        style={{ minHeight: '52px', fontSize: '1.05rem' }}
                                        disabled={busy(item.id)}
                                        onClick={() => {
                                            triggerActionTapHaptic();
                                            status.mutate({ id: item.id, next: 'driver_en_route' });
                                        }}
                                    >
                                        {busy(item.id) ? 'Updating…' : '🏍️ Start pickup route'}
                                    </button>
                                )}

                                {item.status === 'driver_en_route' && (
                                    <SlideToConfirm
                                        disabled={busy(item.id)}
                                        onConfirm={() => {
                                            triggerActionTapHaptic();
                                            status.mutate({ id: item.id, next: 'picked_up' });
                                        }}
                                    />
                                )}

                                {item.status === 'picked_up' && (
                                    <button
                                        className="btn btn-info text-white w-100 fw-bold driver-tap-target driver-action-btn shadow-sm"
                                        style={{ minHeight: '52px', fontSize: '1.05rem' }}
                                        disabled={busy(item.id)}
                                        onClick={() => {
                                            triggerActionTapHaptic();
                                            status.mutate({ id: item.id, next: 'in_transit' });
                                        }}
                                    >
                                        {busy(item.id)
                                            ? 'Starting…'
                                            : '🚀 Start GPS-tracked transit'}
                                    </button>
                                )}

                                {item.status === 'in_transit' && (
                                    <div className="d-grid gap-2">
                                        {trackingId === item.id ? (
                                            <div className="text-success text-center fw-bold py-2 bg-success-subtle rounded border border-success">
                                                ● Live GPS tracking active
                                            </div>
                                        ) : (
                                            <button
                                                className="btn btn-outline-info w-100 fw-bold driver-tap-target driver-action-btn"
                                                style={{ minHeight: '48px' }}
                                                onClick={() => {
                                                    triggerActionTapHaptic();
                                                    startTracking(item.id);
                                                }}
                                            >
                                                🛰️ Activate live GPS
                                            </button>
                                        )}
                                        <button
                                            className="btn btn-primary w-100 fw-bold driver-tap-target driver-action-btn shadow-sm"
                                            style={{ minHeight: '52px', fontSize: '1.05rem' }}
                                            disabled={busy(item.id)}
                                            onClick={() => {
                                                triggerActionTapHaptic();
                                                status.mutate({ id: item.id, next: 'arrived' });
                                            }}
                                        >
                                            📍 I have Arrived
                                        </button>
                                    </div>
                                )}

                                {item.status === 'arrived' && (
                                    <button
                                        className="btn btn-success w-100 fw-bold driver-tap-target driver-action-btn shadow-sm"
                                        style={{ minHeight: '54px', fontSize: '1.1rem' }}
                                        disabled={busy(item.id)}
                                        onClick={() => {
                                            triggerActionTapHaptic();
                                            setOtpId(item.id);
                                        }}
                                    >
                                        🔑 Verify Delivery OTP
                                    </button>
                                )}

                                {reportId === item.id ? (
                                    <div className="mt-2">
                                        <label className="form-label small fw-semibold text-muted mb-1">
                                            Describe the issue (do not include recipient OTP):
                                        </label>
                                        <textarea
                                            className="form-control form-control-sm mb-2"
                                            rows={2}
                                            placeholder="e.g. Address unrecognised, recipient not reachable…"
                                            value={reportText}
                                            onChange={e => setReportText(e.target.value)}
                                            autoFocus
                                        />
                                        <div className="d-flex gap-2">
                                            <button
                                                className="btn btn-sm btn-warning flex-grow-1 driver-tap-target"
                                                disabled={!reportText.trim()}
                                                onClick={submitReport}
                                            >
                                                Submit Report
                                            </button>
                                            <button
                                                className="btn btn-sm btn-outline-secondary driver-tap-target"
                                                onClick={() => { setReportId(null); setReportText(''); }}
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        className="btn btn-sm btn-link text-muted w-100 mt-2"
                                        onClick={() => report(item.id)}
                                    >
                                        Report an operations issue
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {otpId && (() => {
                const delivery = rows.find(r => r.id === otpId) || { id: otpId };
                return (
                    <ProofOfDeliverySheet
                        delivery={delivery}
                        submitting={receipt.isPending}
                        onClose={() => setOtpId(null)}
                        onSubmit={(id, otp) => receipt.mutate({ id, otp })}
                    />
                );
            })()}
        </div>
    );
};

export default ActiveDeliveriesTab;

