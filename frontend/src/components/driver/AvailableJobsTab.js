import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import Pagination from '../common/Pagination';
import { showToast } from '../common/Toast';
import { CardGridSkeleton } from '../common/SkeletonLoader';
import Icon from '../common/Icon';
import { triggerActionTapHaptic } from '../../utils/haptics';
import QueryState from '../common/QueryState';

const AvailableJobsTab = () => {
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(10);
    // Which job card is showing the inline accept-confirm row
    const [confirmingId, setConfirmingId] = useState(null);
    // Which job card is showing the inline decline form, and its reason text
    const [decliningId, setDecliningId] = useState(null);
    const [declineReason, setDeclineReason] = useState('');

    const queryClient = useQueryClient();

    const jobs = useQuery({
        queryKey: ['driver', 'available-jobs', page, limit],
        queryFn: () =>
            apiGet(`/delivery-person/available-deliveries?page=${page}&limit=${limit}`),
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['driver', 'available-jobs'] });
        queryClient.invalidateQueries({ queryKey: ['driver', 'active-assignments'] });
    };

    const accept = useMutation({
        mutationFn: id =>
            apiPost('/delivery-person/accept-delivery', { delivery_id: id }),
        onSuccess: (_, id) => {
            showToast.success(
                `Order #${id} assigned to you. Navigate to the pickup point to begin.`
            );
            setConfirmingId(null);
            invalidate();
        },
        onError: () => {
            showToast.error('Failed to accept order or order already taken.');
            setConfirmingId(null);
        },
    });

    const decline = useMutation({
        mutationFn: ({ id, reason }) =>
            apiPost('/delivery-person/decline-offer', { delivery_id: id, reason }),
        onSuccess: () => {
            showToast.info('Offer declined. Operations has been notified.');
            setDecliningId(null);
            setDeclineReason('');
            invalidate();
        },
        onError: () => showToast.error('Could not decline this offer.'),
    });

    const submitDecline = (id) => {
        const trimmed = declineReason.trim();
        if (!trimmed) {
            showToast.error('Please enter a reason before declining.');
            return;
        }
        decline.mutate({ id, reason: trimmed });
    };

    const rows = jobs.data?.data || [];

    if (jobs.isError && !jobs.data) {
        return <QueryState query={jobs} loading={<CardGridSkeleton count={4} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <h4 className="fw-bold mb-0">Available Delivery Jobs in Kano</h4>
                <button
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => jobs.refetch()}
                >
                    <Icon name="refresh" size={16} /> Refresh Feed
                </button>
            </div>

            {jobs.isLoading ? (
                <CardGridSkeleton count={4} />
            ) : rows.length === 0 ? (
                <div className="alert alert-light text-center py-5 border">
                    <h5>No available orders at the moment</h5>
                    <p className="text-muted mb-0">
                        New orders from clients will appear here automatically.
                    </p>
                </div>
            ) : (
                <>
                    <div className="row g-3">
                        {rows.map(job => {
                            const isConfirming = confirmingId === job.id;
                            const isDeclining = decliningId === job.id;

                            return (
                                <div key={job.id} className="col-md-6">
                                    <div className="card h-100 border-0 shadow-sm custom-card p-3 driver-offer-card">
                                        <div className="d-flex justify-content-between align-items-center mb-2">
                                            <span className="fw-bold fs-6">Order #{job.id}</span>
                                            <span className="badge bg-success-subtle text-success border border-success-subtle fs-6">
                                                ₦{job.driver_payout ?? job.total_cost} Est. Payout
                                            </span>
                                        </div>
                                        <hr className="my-2" />

                                        <div className="mb-2">
                                            <small className="text-muted d-block">
                                                Package Details:
                                            </small>
                                            <strong className="text-dark">
                                                {job.item_description} {job.item_category ? `(${job.item_category})` : ''}
                                            </strong>
                                            <div className="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                                <span className="badge bg-light text-dark border">
                                                    ⚖️ {job.item_weight || 1} kg
                                                </span>
                                                <span className="badge bg-light text-dark border">
                                                    📏 {job.distance_km || 5} km
                                                </span>
                                                <span className="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">
                                                    ⏱️ ETA: ~{Math.max(10, Math.round(Number(job.distance_km || 5) * 3.5))} min
                                                </span>
                                            </div>
                                        </div>

                                        <div className="bg-light p-2 rounded mb-3 small">
                                            <div>
                                                📍 <strong>Pickup:</strong> {job.pickup_address}
                                            </div>
                                            <div>
                                                🏁 <strong>Destination:</strong>{' '}
                                                {job.delivery_address}
                                            </div>
                                            <div>
                                                👤 <strong>Sender:</strong> {job.client_name} (
                                                {job.client_phone})
                                            </div>
                                        </div>

                                        {/* ── Accept flow ── */}
                                        {!isConfirming && !isDeclining && (
                                            <button
                                                className="btn btn-primary w-100 fw-bold d-flex align-items-center justify-content-center driver-tap-target driver-action-btn shadow-sm"
                                                style={{ minHeight: '52px', fontSize: '1.05rem' }}
                                                disabled={accept.isPending}
                                                onClick={() => {
                                                    triggerActionTapHaptic();
                                                    setConfirmingId(job.id);
                                                    setDecliningId(null);
                                                }}
                                            >
                                                🚀 Accept Delivery
                                            </button>
                                        )}

                                        {isConfirming && (
                                            <div className="d-flex gap-2 mt-1">
                                                <button
                                                    className="btn btn-success flex-grow-1 fw-bold driver-tap-target"
                                                    style={{ minHeight: '48px' }}
                                                    disabled={accept.isPending}
                                                    onClick={() => {
                                                        triggerActionTapHaptic();
                                                        accept.mutate(job.id);
                                                    }}
                                                >
                                                    {accept.isPending && accept.variables === job.id
                                                        ? 'Accepting…'
                                                        : '✓ Confirm Accept'}
                                                </button>
                                                <button
                                                    className="btn btn-outline-secondary driver-tap-target"
                                                    style={{ minHeight: '48px' }}
                                                    onClick={() => setConfirmingId(null)}
                                                >
                                                    Cancel
                                                </button>
                                            </div>
                                        )}

                                        {/* ── Decline flow ── */}
                                        {!isConfirming && !isDeclining && (
                                            <button
                                                className="btn btn-sm btn-link text-muted w-100 mt-2 driver-tap-target"
                                                style={{ minHeight: '44px' }}
                                                onClick={() => {
                                                    triggerActionTapHaptic();
                                                    setDecliningId(job.id);
                                                    setDeclineReason('');
                                                    setConfirmingId(null);
                                                }}
                                            >
                                                Decline offer
                                            </button>
                                        )}

                                        {isDeclining && (
                                            <div className="mt-2">
                                                <label className="form-label small fw-semibold text-muted mb-1">
                                                    Reason for declining (required):
                                                </label>
                                                <textarea
                                                    className="form-control form-control-sm mb-2"
                                                    rows={2}
                                                    placeholder="e.g. Too far, vehicle issue, already assigned…"
                                                    value={declineReason}
                                                    onChange={e => setDeclineReason(e.target.value)}
                                                    autoFocus
                                                />
                                                <div className="d-flex gap-2">
                                                    <button
                                                        className="btn btn-sm btn-danger flex-grow-1 driver-tap-target"
                                                        disabled={decline.isPending || !declineReason.trim()}
                                                        onClick={() => submitDecline(job.id)}
                                                    >
                                                        {decline.isPending ? 'Declining…' : 'Submit Decline'}
                                                    </button>
                                                    <button
                                                        className="btn btn-sm btn-outline-secondary driver-tap-target"
                                                        onClick={() => { setDecliningId(null); setDeclineReason(''); }}
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>

                    <Pagination
                        pagination={jobs.data?.pagination || null}
                        onPageChange={setPage}
                        onLimitChange={next => {
                            setLimit(next);
                            setPage(1);
                        }}
                    />
                </>
            )}
        </div>
    );
};

export default AvailableJobsTab;
