import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import Pagination from '../common/Pagination';
import QueryState from '../common/QueryState';

const ClientKycTab = () => {
    const [page, setPage] = useState(1);
    const [selectedUserId, setSelectedUserId] = useState(null);
    const [rejectReason, setRejectReason] = useState('');
    const [showRejectInput, setShowRejectInput] = useState(false);
    const queryClient = useQueryClient();

    const submissions = useQuery({
        queryKey: ['admin', 'client-kyc', page],
        queryFn: () => apiGet(`/admin/client-kyc/pending?page=${page}&limit=15`),
    });

    const details = useQuery({
        queryKey: ['admin', 'client-kyc-details', selectedUserId],
        queryFn: () => apiGet(`/admin/client-kyc/details?user_id=${selectedUserId}`),
        enabled: Boolean(selectedUserId),
    });

    const invalidate = () => {
        queryClient.invalidateQueries({ queryKey: ['admin', 'client-kyc'] });
        queryClient.invalidateQueries({ queryKey: ['admin', 'dashboard-stats'] });
    };

    const decision = useMutation({
        mutationFn: ({ userId, state, reason }) =>
            apiPost(`/admin/client-kyc/${state}`, {
                user_id: userId,
                ...(reason ? { reason } : {}),
            }),
        onSuccess: (_, variables) => {
            showToast.success(
                variables.state === 'approve' ? 'Client KYC approved.' : 'Client KYC rejected.'
            );
            setShowRejectInput(false);
            setRejectReason('');
            invalidate();
            setSelectedUserId(null);
        },
        onError: error =>
            showToast.error(
                error.response?.data?.message || 'Could not save the KYC decision.'
            ),
    });

    const review = state => {
        const userId = details.data?.data?.profile?.user_id;
        if (!userId) return;
        if (state === 'reject') {
            const trimmed = rejectReason.trim();
            if (!trimmed) {
                showToast.error('Please enter a rejection reason.');
                return;
            }
            decision.mutate({ userId, state, reason: trimmed });
        } else {
            decision.mutate({ userId, state, reason: '' });
        }
    };

    const viewDocument = async id => {
        try {
            const blob = await apiGet(`/admin/client-kyc/document-file?document_id=${id}`, {
                responseType: 'blob',
            });
            const url = URL.createObjectURL(blob);
            window.open(url, '_blank', 'noopener,noreferrer');
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (error) {
            showToast.error(error.response?.data?.message || 'Could not open this KYC document.');
        }
    };

    const rows = submissions.data?.data || [];
    const profile = details.data?.data?.profile;
    const documents = details.data?.data?.documents || [];

    if (submissions.isError && !submissions.data) {
        return <QueryState query={submissions} loading={<TableSkeleton rows={5} cols={2} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            <div className="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h4 className="fw-bold mb-1">Client KYC review</h4>
                    <p className="text-muted mb-0">
                        Review identity documents and approve delivery access for client accounts.
                    </p>
                </div>
                <button
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => submissions.refetch()}
                >
                    Refresh
                </button>
            </div>

            <div className="row g-4">
                {/* ── Submissions List ────────────────────────────────── */}
                <div className="col-lg-5">
                    {submissions.isLoading ? (
                        <TableSkeleton rows={5} cols={2} />
                    ) : rows.length === 0 ? (
                        <div className="alert alert-light border mb-0">
                            No client KYC submissions are awaiting review.
                        </div>
                    ) : (
                        <>
                            <div className="list-group">
                                {rows.map(item => (
                                    <button
                                        key={item.user_id}
                                        type="button"
                                        onClick={() => setSelectedUserId(item.user_id)}
                                        className={`list-group-item list-group-item-action ${
                                            selectedUserId === item.user_id ? 'active' : ''
                                        }`}
                                    >
                                        <strong>{item.full_name}</strong>
                                        <small className="d-block">{item.email}</small>
                                        <small className="d-block">
                                            {item.doc_count} document
                                            {item.doc_count === 1 ? '' : 's'} ·{' '}
                                            {item.kyc_status.replaceAll('_', ' ')}
                                        </small>
                                    </button>
                                ))}
                            </div>
                            <Pagination
                                pagination={submissions.data?.pagination || null}
                                onPageChange={setPage}
                            />
                        </>
                    )}
                </div>

                {/* ── Profile & Document Details ──────────────────────── */}
                <div className="col-lg-7">
                    {!selectedUserId ? (
                        <div className="border rounded h-100 d-flex align-items-center justify-content-center text-muted p-4">
                            Select a client submission to inspect documents and make a KYC decision.
                        </div>
                    ) : details.isLoading ? (
                        <TableSkeleton rows={4} cols={3} />
                    ) : details.isError && !details.data ? (
                        <QueryState query={details} loading={<TableSkeleton rows={4} cols={3} />} />
                    ) : profile ? (
                        <div className="border rounded p-3">
                            <div className="d-flex justify-content-between gap-2 mb-3">
                                <div>
                                    <h5 className="fw-bold mb-1">{profile.full_name}</h5>
                                    <div className="small text-muted">
                                        {profile.email} · {profile.phone}
                                    </div>
                                    <div className="small text-muted mt-1">
                                        {profile.address || 'No address provided'}
                                    </div>
                                </div>
                                <span
                                    className={`status-pill status-${
                                        profile.kyc_status === 'under_review'
                                            ? 'under_review'
                                            : profile.kyc_status
                                    }`}
                                >
                                    {profile.kyc_status.replaceAll('_', ' ')}
                                </span>
                            </div>

                            <h6 className="fw-bold">KYC documents</h6>
                            {documents.length === 0 ? (
                                <p className="text-muted">
                                    No documents are attached to this client profile.
                                </p>
                            ) : (
                                <div className="table-responsive">
                                    <table className="table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th>Document</th>
                                                <th>Number</th>
                                                <th>Expiry</th>
                                                <th>File</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {documents.map(doc => (
                                                <tr key={doc.id}>
                                                    <td className="text-capitalize">
                                                        {doc.document_type.replaceAll('_', ' ')}
                                                    </td>
                                                    <td>{doc.document_number || '—'}</td>
                                                    <td>{doc.expires_at || '—'}</td>
                                                    <td>
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-primary"
                                                            onClick={() => viewDocument(doc.id)}
                                                        >
                                                            Open
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}

                            <div className="mt-3">
                                <div className="d-flex flex-wrap gap-2 mb-2">
                                    <button
                                        className="btn btn-success"
                                        disabled={decision.isPending}
                                        onClick={() => review('approve')}
                                    >
                                        {decision.isPending && !showRejectInput ? 'Saving…' : 'Approve KYC'}
                                    </button>
                                    {!showRejectInput ? (
                                        <button
                                            className="btn btn-outline-danger"
                                            disabled={decision.isPending}
                                            onClick={() => setShowRejectInput(true)}
                                        >
                                            Reject with reason
                                        </button>
                                    ) : (
                                        <button
                                            className="btn btn-outline-secondary btn-sm"
                                            onClick={() => { setShowRejectInput(false); setRejectReason(''); }}
                                        >
                                            Cancel
                                        </button>
                                    )}
                                </div>
                                {showRejectInput && (
                                    <div>
                                        <label className="form-label small fw-semibold text-danger mb-1">
                                            Rejection reason (client will see this):
                                        </label>
                                        <textarea
                                            className="form-control form-control-sm mb-2"
                                            rows={3}
                                            placeholder="e.g. Document image is unclear, national ID has expired…"
                                            value={rejectReason}
                                            onChange={e => setRejectReason(e.target.value)}
                                            autoFocus
                                        />
                                        <button
                                            className="btn btn-danger"
                                            disabled={decision.isPending || !rejectReason.trim()}
                                            onClick={() => review('reject')}
                                        >
                                            {decision.isPending ? 'Rejecting…' : 'Confirm Reject'}
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="alert alert-danger">
                            Could not load this client KYC profile.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
};

export default ClientKycTab;

