import { useState } from 'react';
import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';
import SensitiveValue from '../common/SensitiveValue';

const ApprovalsTab = () => {
    const [limit, setLimit] = useState(15);
    // Track which row has the reject reason input open + the reason text
    const [rejectingId, setRejectingId] = useState(null);
    const [rejectReason, setRejectReason] = useState('');
    const queryClient = useQueryClient();

    const pendingQuery = useInfiniteQuery({
        queryKey: ['admin', 'pending-deliverymen', limit],
        initialPageParam: null,
        queryFn: ({ pageParam }) => apiGet(`/admin/pending-deliverymen?limit=${limit}${pageParam ? `&cursor=${pageParam}` : ''}`),
        getNextPageParam: lastPage => lastPage.data?.next_cursor ?? undefined,
    });

    const update = useMutation({
        mutationFn: ({ id, action, reason }) =>
            apiPost(
                action === 'approve'
                    ? '/admin/approve-deliveryman'
                    : '/admin/reject-deliveryman',
                { id, ...(reason ? { reason } : {}) }
            ),
        onSuccess: (_, variables) => {
            showToast[variables.action === 'approve' ? 'success' : 'info'](
                variables.action === 'approve'
                    ? 'Delivery partner approved successfully.'
                    : 'Partner KYC rejected. They can correct and resubmit documents.'
            );
            setRejectingId(null);
            setRejectReason('');
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-deliverymen'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-driver-documents'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'dashboard-stats'] });
        },
        onError: error =>
            showToast.error(
                error.response?.data?.message || 'Could not update this partner application.'
            ),
    });

    const submitReject = (id) => {
        const trimmed = rejectReason.trim();
        if (!trimmed) {
            showToast.error('Please enter a rejection reason before submitting.');
            return;
        }
        update.mutate({ id, action: 'reject', reason: trimmed });
    };

    const pending = pendingQuery.data?.pages.flatMap(page => page.data?.items || []) || [];

    if (pendingQuery.isError && !pendingQuery.data) {
        return <QueryState query={pendingQuery} loading={<TableSkeleton rows={5} cols={7} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <h4 className="fw-bold mb-1">Pending Delivery Partner Approvals</h4>
            <p className="text-muted small mb-3">
                Approve only after both the government ID and driver licence have been verified in Document Review.
            </p>

            {pendingQuery.isLoading ? (
                <TableSkeleton rows={5} cols={7} />
            ) : pending.length === 0 ? (
                <div className="alert alert-light text-center py-4 border">
                    <p className="text-muted mb-0">No pending partner applications to review.</p>
                </div>
            ) : (
                <>
                    <div className="table-responsive table-to-cards-wrap">
                        <table className="table table-hover align-middle table-to-cards">
                            <thead className="table-light">
                                <tr>
                                    <th>Applicant Name</th>
                                    <th>Email Address</th>
                                    <th>Phone</th>
                                    <th>Address / Base</th>
                                    <th>KYC documents</th>
                                    <th>Applied Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pending.map(user => {
                                    const canApprove = Number(user.verified_mandatory_documents) >= 2;
                                    const isRejecting = rejectingId === user.id;

                                    return (
                                        <tr key={user.id}>
                                            <td data-label="Applicant" className="fw-bold">{user.full_name}</td>
                                            <td data-label="Email"><SensitiveValue value={user.email} kind="email" label="applicant email" /></td>
                                            <td data-label="Phone"><SensitiveValue value={user.phone} kind="phone" label="applicant phone" /></td>
                                            <td data-label="Address / base">
                                                <small>{user.address}</small>
                                            </td>
                                            <td data-label="KYC documents">
                                                <span
                                                    className={`badge ${
                                                        canApprove ? 'bg-success' : 'bg-warning text-dark'
                                                    }`}
                                                >
                                                    {user.verified_mandatory_documents || 0}/2 verified
                                                </span>
                                                <small className="d-block text-muted">
                                                    {user.pending_documents || 0} awaiting review
                                                </small>
                                            </td>
                                            <td data-label="Applied">
                                                <small>{new Date(user.created_at).toLocaleDateString()}</small>
                                            </td>
                                            <td data-label="Action">
                                                {!isRejecting ? (
                                                    <div className="d-flex gap-2">
                                                        <button
                                                            className="btn btn-sm btn-success px-3 fw-semibold"
                                                            disabled={update.isPending || !canApprove}
                                                            title={
                                                                canApprove
                                                                    ? 'Approve partner KYC'
                                                                    : 'Verify government ID and driver licence in Document Review first.'
                                                            }
                                                            onClick={() =>
                                                                update.mutate({ id: user.id, action: 'approve' })
                                                            }
                                                        >
                                                            {update.isPending && update.variables?.id === user.id && update.variables?.action === 'approve'
                                                                ? 'Approving…'
                                                                : 'Approve'}
                                                        </button>
                                                        <button
                                                            className="btn btn-sm btn-outline-danger px-3"
                                                            disabled={update.isPending}
                                                            onClick={() => {
                                                                setRejectingId(user.id);
                                                                setRejectReason('');
                                                            }}
                                                        >
                                                            Reject
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <div style={{ minWidth: '220px' }}>
                                                        <textarea
                                                            className="form-control form-control-sm mb-2"
                                                            rows={2}
                                                            placeholder="Rejection reason (partner will see this)…"
                                                            value={rejectReason}
                                                            onChange={e => setRejectReason(e.target.value)}
                                                            autoFocus
                                                        />
                                                        <div className="d-flex gap-2">
                                                            <button
                                                                className="btn btn-sm btn-danger flex-grow-1"
                                                                disabled={update.isPending || !rejectReason.trim()}
                                                                onClick={() => submitReject(user.id)}
                                                            >
                                                                {update.isPending ? 'Rejecting…' : 'Confirm Reject'}
                                                            </button>
                                                            <button
                                                                className="btn btn-sm btn-outline-secondary"
                                                                onClick={() => { setRejectingId(null); setRejectReason(''); }}
                                                            >
                                                                Cancel
                                                            </button>
                                                        </div>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {pendingQuery.hasNextPage && (
                        <button type="button" className="btn btn-sm btn-outline-primary mt-3" onClick={() => pendingQuery.fetchNextPage()} disabled={pendingQuery.isFetchingNextPage}>
                            {pendingQuery.isFetchingNextPage ? 'Loading more…' : 'Load more applications'}
                        </button>
                    )}
                </>
            )}
        </div>
    );
};

export default ApprovalsTab;
