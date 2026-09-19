import React, { useState, useEffect, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost } from '../../api/client';
import Pagination from '../common/Pagination';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import Icon from '../common/Icon';
import QueryState from '../common/QueryState';

const REJECTION_PRESETS = [
    { label: 'Blurry / Unreadable', text: 'The submitted document image is blurry or unreadable. Please upload a clear, high-resolution photo.' },
    { label: 'Expired Document', text: 'This document has expired. Please upload a valid, unexpired document.' },
    { label: 'Name Mismatch', text: 'The full name on the document does not match your registered Tweak Insight account profile.' },
    { label: 'Wrong Document Type', text: 'The uploaded file does not match the selected document category. Please re-upload the correct document.' },
    { label: 'Edges / Details Cut Off', text: 'Corners, expiry date, or essential document details are cut off. Please upload a complete photo showing all 4 corners.' },
    { label: 'Invalid / Unofficial ID', text: 'The provided document is not an accepted government ID or driver licence.' }
];

const DocumentReviewTab = () => {
    const [page, setPage] = useState(1);
    const queryClient = useQueryClient();

    // Modal state for Document Viewer
    const [viewerDoc, setViewerDoc] = useState(null);
    const [blobUrl, setBlobUrl] = useState(null);
    const [isBlobLoading, setIsBlobLoading] = useState(false);
    const [blobType, setBlobType] = useState('image'); // 'image' | 'pdf' | 'unknown'
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);

    // Modal state for Rejection Reason Dialog
    const [rejectingDoc, setRejectingDoc] = useState(null);
    const [rejectionReason, setRejectionReason] = useState('');

    const documentsQuery = useQuery({
        queryKey: ['admin', 'pending-driver-documents', page],
        queryFn: () => apiGet(`/admin/pending-driver-documents?page=${page}&limit=15`)
    });

    const reviewMutation = useMutation({
        mutationFn: ({ documentId, decision, reason }) =>
            apiPost('/admin/review-driver-document', { document_id: documentId, decision, reason }),
        onSuccess: (_, { decision }) => {
            showToast.success(`Document marked as ${decision}.`);
            closeViewer();
            closeRejectModal();
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-driver-documents'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'dashboard-stats'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-deliverymen'] });
        },
        onError: (err) => {
            showToast.error(err.response?.data?.message || 'Could not record document decision.');
        }
    });

    const approvePartnerMutation = useMutation({
        mutationFn: (userId) => apiPost('/admin/approve-deliveryman', { user_id: userId }),
        onSuccess: () => {
            showToast.success('Driver partner verified and account activated!');
            closeViewer();
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-driver-documents'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'dashboard-stats'] });
            queryClient.invalidateQueries({ queryKey: ['admin', 'pending-deliverymen'] });
        },
        onError: (err) => {
            showToast.error(err.response?.data?.message || 'Could not activate driver partner.');
        }
    });

    const openViewer = async (doc) => {
        setViewerDoc(doc);
        setZoom(1);
        setRotation(0);
        setIsBlobLoading(true);
        setBlobUrl(null);

        try {
            const blob = await apiGet(`/admin/driver-document-file?document_id=${doc.id}`, {
                responseType: 'blob'
            });
            const url = URL.createObjectURL(blob);
            setBlobUrl(url);
            setBlobType(blob.type === 'application/pdf' ? 'pdf' : 'image');
        } catch (err) {
            showToast.error('Could not load document preview securely.');
        } finally {
            setIsBlobLoading(false);
        }
    };

    const closeViewer = () => {
        if (blobUrl) {
            URL.revokeObjectURL(blobUrl);
        }
        setViewerDoc(null);
        setBlobUrl(null);
        setZoom(1);
        setRotation(0);
    };

    const handleZoomIn = () => setZoom((prev) => Math.min(prev + 0.25, 3.5));
    const handleZoomOut = () => setZoom((prev) => Math.max(prev - 0.25, 0.5));
    const handleRotate = () => setRotation((prev) => (prev + 90) % 360);
    const handleResetTransform = () => {
        setZoom(1);
        setRotation(0);
    };

    const openRejectModal = (doc) => {
        setRejectingDoc(doc);
        setRejectionReason('');
    };

    const closeRejectModal = () => {
        setRejectingDoc(null);
        setRejectionReason('');
    };

    const submitRejection = () => {
        if (!rejectionReason.trim()) {
            return showToast.error('Please enter or select a rejection reason for the partner.');
        }
        reviewMutation.mutate({
            documentId: rejectingDoc.id,
            decision: 'rejected',
            reason: rejectionReason.trim()
        });
    };

    const handleVerifyAndApprove = (doc) => {
        // First mark document verified
        reviewMutation.mutate(
            { documentId: doc.id, decision: 'verified', reason: '' },
            {
                onSuccess: () => {
                    // Then trigger partner account approval
                    approvePartnerMutation.mutate(doc.user_id);
                }
            }
        );
    };

    const documents = documentsQuery.data?.data || [];

    if (documentsQuery.isError && !documentsQuery.data) {
        return <QueryState query={documentsQuery} loading={<TableSkeleton rows={5} cols={7} />} />;
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            {/* Header */}
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h4 className="fw-bold mb-1">Driver KYC Document Review</h4>
                    <p className="text-muted small mb-0">
                        Inspect identity documents, reconcile partner profile data, and approve or reject submissions.
                    </p>
                </div>
                <button
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => documentsQuery.refetch()}
                    disabled={documentsQuery.isFetching}
                >
                    <Icon name="refresh" size={16} /> {documentsQuery.isFetching ? 'Refreshing...' : 'Refresh Queue'}
                </button>
            </div>

            {/* Document Queue Table */}
            {documentsQuery.isLoading ? (
                <TableSkeleton rows={5} cols={7} />
            ) : documents.length === 0 ? (
                <div className="alert alert-light border text-center py-5">
                    <div className="fs-1 mb-2">✅</div>
                    <h6 className="fw-bold text-dark mb-1">All Caught Up!</h6>
                    <p className="text-muted small mb-0">No driver documents are currently awaiting operations review.</p>
                </div>
            ) : (
                <>
                    <div className="table-responsive">
                        <table className="table table-hover align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Partner Profile</th>
                                    <th>Document Type</th>
                                    <th>Doc Number</th>
                                    <th>Expiry Date</th>
                                    <th>Submitted</th>
                                    <th>Verification</th>
                                    <th className="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {documents.map((doc) => (
                                    <tr key={doc.id}>
                                        <td>
                                            <strong className="d-block text-dark">{doc.full_name}</strong>
                                            <span className="small text-muted">{doc.phone || doc.email}</span>
                                            {doc.vehicle_type && (
                                                <span className="badge bg-secondary bg-opacity-10 text-secondary ms-1 small">
                                                    {doc.vehicle_type}
                                                </span>
                                            )}
                                        </td>
                                        <td>
                                            <span className="badge bg-primary bg-opacity-10 text-primary text-capitalize px-2 py-1">
                                                {doc.document_type.replaceAll('_', ' ')}
                                            </span>
                                        </td>
                                        <td>
                                            <code className="text-dark">{doc.document_number || '—'}</code>
                                        </td>
                                        <td>
                                            {doc.expires_at ? (
                                                <span className={new Date(doc.expires_at) < new Date() ? 'text-danger fw-bold' : 'text-muted'}>
                                                    {doc.expires_at}
                                                </span>
                                            ) : (
                                                <span className="text-muted">—</span>
                                            )}
                                        </td>
                                        <td className="small text-muted">
                                            {new Date(doc.created_at).toLocaleDateString()}
                                        </td>
                                        <td>
                                            <span className="badge bg-warning bg-opacity-10 text-warning px-2 py-1">
                                                ⏳ Pending Review
                                            </span>
                                        </td>
                                        <td className="text-end">
                                            <div className="d-inline-flex gap-2">
                                                <button
                                                    onClick={() => openViewer(doc)}
                                                    className="btn btn-sm btn-primary fw-semibold px-3"
                                                >
                                                    🔍 Inspect File
                                                </button>
                                                <button
                                                    disabled={reviewMutation.isPending}
                                                    onClick={() =>
                                                        reviewMutation.mutate({
                                                            documentId: doc.id,
                                                            decision: 'verified',
                                                            reason: ''
                                                        })
                                                    }
                                                    className="btn btn-sm btn-success fw-semibold"
                                                    title="Approve Document"
                                                >
                                                    ✓ Verify
                                                </button>
                                                <button
                                                    disabled={reviewMutation.isPending}
                                                    onClick={() => openRejectModal(doc)}
                                                    className="btn btn-sm btn-outline-danger fw-semibold"
                                                    title="Reject Document"
                                                >
                                                    ✕ Reject
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination pagination={documentsQuery.data?.pagination || null} onPageChange={setPage} />
                </>
            )}

            {/* ========================================================================= */}
            {/* EMBEDDED DOCUMENT VIEWER MODAL WITH SIDE-BY-SIDE RECONCILIATION */}
            {/* ========================================================================= */}
            {viewerDoc && (
                <div
                    className="modal fade show d-block"
                    tabIndex="-1"
                    style={{ backgroundColor: 'rgba(15, 23, 42, 0.75)', zIndex: 1060 }}
                >
                    <div className="modal-dialog modal-xl modal-dialog-centered" style={{ maxWidth: '94vw', height: '90vh' }}>
                        <div className="modal-content h-100 shadow-lg border-0 d-flex flex-column">
                            {/* Modal Header */}
                            <div className="modal-header bg-light py-2 px-3 border-bottom d-flex justify-content-between align-items-center">
                                <div className="d-flex align-items-center gap-2">
                                    <span className="badge bg-primary text-capitalize">
                                        {viewerDoc.document_type.replaceAll('_', ' ')}
                                    </span>
                                    <h6 className="modal-title fw-bold mb-0 text-dark">
                                        {viewerDoc.full_name} — Document Inspection
                                    </h6>
                                </div>
                                <button
                                    type="button"
                                    className="btn-close"
                                    onClick={closeViewer}
                                    aria-label="Close"
                                />
                            </div>

                            {/* Modal Body: Side-by-Side Layout */}
                            <div className="modal-body p-0 d-flex flex-column flex-lg-row overflow-hidden flex-grow-1">
                                {/* Left Side: Document Preview Canvas */}
                                <div
                                    className="flex-grow-1 bg-dark d-flex flex-column justify-content-between position-relative overflow-hidden"
                                    style={{ minHeight: '400px' }}
                                >
                                    {/* Viewer Toolbar */}
                                    <div
                                        className="p-2 d-flex justify-content-center gap-2 bg-black bg-opacity-75 position-absolute top-0 start-0 end-0"
                                        style={{ zIndex: 10 }}
                                    >
                                        <button
                                            className="btn btn-sm btn-dark border border-secondary text-light"
                                            onClick={handleZoomIn}
                                            title="Zoom In"
                                        >
                                            ➕ Zoom In
                                        </button>
                                        <button
                                            className="btn btn-sm btn-dark border border-secondary text-light"
                                            onClick={handleZoomOut}
                                            title="Zoom Out"
                                        >
                                            ➖ Zoom Out
                                        </button>
                                        <button
                                            className="btn btn-sm btn-dark border border-secondary text-light"
                                            onClick={handleRotate}
                                            title="Rotate 90°"
                                        >
                                            <Icon name="rotate" size={16} /> Rotate ({rotation}°)
                                        </button>
                                        <button
                                            className="btn btn-sm btn-dark border border-secondary text-light"
                                            onClick={handleResetTransform}
                                            title="Reset Zoom & Rotation"
                                        >
                                            ↺ Reset
                                        </button>
                                    </div>

                                    {/* Document Canvas Area */}
                                    <div
                                        className="w-100 h-100 d-flex align-items-center justify-content-center overflow-auto p-4"
                                        style={{ cursor: zoom > 1 ? 'grab' : 'default', marginTop: '40px' }}
                                    >
                                        {isBlobLoading ? (
                                            <div className="text-center text-light">
                                                <div className="spinner-border text-primary mb-2" role="status" />
                                                <p className="small text-muted mb-0">Decryption & Loading secure file...</p>
                                            </div>
                                        ) : !blobUrl ? (
                                            <div className="text-danger small">Unable to render document file.</div>
                                        ) : blobType === 'pdf' ? (
                                            <iframe
                                                src={blobUrl}
                                                title="PDF Preview"
                                                className="w-100 h-100 border-0 rounded"
                                                style={{ minHeight: '550px' }}
                                            />
                                        ) : (
                                            <img
                                                src={blobUrl}
                                                alt="Driver Document"
                                                style={{
                                                    transform: `scale(${zoom}) rotate(${rotation}deg)`,
                                                    transition: 'transform 0.15s ease-out',
                                                    maxWidth: '90%',
                                                    maxHeight: '90%',
                                                    objectFit: 'contain',
                                                    boxShadow: '0 8px 30px rgba(0,0,0,0.5)'
                                                }}
                                            />
                                        )}
                                    </div>

                                    {/* Bottom Canvas Footer */}
                                    <div className="p-2 bg-black bg-opacity-50 text-center text-muted small">
                                        Zoom: {Math.round(zoom * 100)}% | Rotation: {rotation}° | Format:{' '}
                                        {blobType.toUpperCase()}
                                    </div>
                                </div>

                                {/* Right Side: Partner Reconciliation Panel */}
                                <div
                                    className="p-4 bg-white border-start d-flex flex-column justify-content-between"
                                    style={{ width: '100%', maxWidth: '380px', minWidth: '320px', overflowY: 'auto' }}
                                >
                                    <div>
                                        <h6 className="fw-bold text-dark border-bottom pb-2 mb-3">
                                            Partner Identity Verification
                                        </h6>

                                        <div className="mb-3">
                                            <label className="text-muted small d-block">Registered Full Name</label>
                                            <strong className="text-dark fs-6">{viewerDoc.full_name}</strong>
                                        </div>

                                        <div className="mb-3">
                                            <label className="text-muted small d-block">Phone Number</label>
                                            <span className="text-dark fw-semibold">{viewerDoc.phone || '—'}</span>
                                        </div>

                                        <div className="mb-3">
                                            <label className="text-muted small d-block">Email Address</label>
                                            <span className="text-dark small">{viewerDoc.email}</span>
                                        </div>

                                        <div className="row g-2 mb-3">
                                            <div className="col-6">
                                                <label className="text-muted small d-block">Vehicle Type</label>
                                                <strong className="text-dark">{viewerDoc.vehicle_type || 'Motorcycle'}</strong>
                                            </div>
                                            <div className="col-6">
                                                <label className="text-muted small d-block">Plate Number</label>
                                                <code className="text-primary">{viewerDoc.vehicle_registration || '—'}</code>
                                            </div>
                                        </div>

                                        <hr className="my-3" />

                                        <h6 className="fw-bold text-dark mb-2">Document Details</h6>

                                        <div className="mb-2">
                                            <label className="text-muted small d-block">Document Number</label>
                                            <code className="text-dark fs-6">{viewerDoc.document_number || 'Not specified'}</code>
                                        </div>

                                        <div className="mb-3">
                                            <label className="text-muted small d-block">Document Expiry Date</label>
                                            {viewerDoc.expires_at ? (
                                                <span
                                                    className={
                                                        new Date(viewerDoc.expires_at) < new Date()
                                                            ? 'badge bg-danger'
                                                            : 'badge bg-success bg-opacity-10 text-success'
                                                    }
                                                >
                                                    {viewerDoc.expires_at}
                                                </span>
                                            ) : (
                                                <span className="text-muted small">No expiry date provided</span>
                                            )}
                                        </div>
                                    </div>

                                    {/* Action Buttons */}
                                    <div className="pt-3 border-top d-flex flex-column gap-2">
                                        {/* Standard Verify Button */}
                                        <button
                                            disabled={reviewMutation.isPending || approvePartnerMutation.isPending}
                                            onClick={() =>
                                                reviewMutation.mutate({
                                                    documentId: viewerDoc.id,
                                                    decision: 'verified',
                                                    reason: ''
                                                })
                                            }
                                            className="btn btn-success fw-bold py-2"
                                        >
                                            ✓ Verify Document
                                        </button>

                                        {/* 1-Click Verify & Activate Partner Button */}
                                        <button
                                            disabled={reviewMutation.isPending || approvePartnerMutation.isPending}
                                            onClick={() => handleVerifyAndApprove(viewerDoc)}
                                            className="btn btn-primary fw-bold py-2"
                                            title="Verifies document and immediately activates the partner account"
                                        >
                                            ⚡ Verify & Activate Partner
                                        </button>

                                        {/* Reject Button */}
                                        <button
                                            disabled={reviewMutation.isPending || approvePartnerMutation.isPending}
                                            onClick={() => {
                                                const doc = viewerDoc;
                                                closeViewer();
                                                openRejectModal(doc);
                                            }}
                                            className="btn btn-outline-danger fw-semibold"
                                        >
                                            ✕ Reject Document...
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* ========================================================================= */}
            {/* STRUCTURED REJECTION REASON MODAL WITH PRESET CHIPS */}
            {/* ========================================================================= */}
            {rejectingDoc && (
                <div
                    className="modal fade show d-block"
                    tabIndex="-1"
                    style={{ backgroundColor: 'rgba(15, 23, 42, 0.65)', zIndex: 1070 }}
                >
                    <div className="modal-dialog modal-dialog-centered" style={{ maxWidth: '540px' }}>
                        <div className="modal-content shadow-lg border-0">
                            <div className="modal-header bg-danger text-white py-3">
                                <h6 className="modal-title fw-bold mb-0">
                                    Reject {rejectingDoc.full_name}'s {rejectingDoc.document_type.replaceAll('_', ' ')}
                                </h6>
                                <button
                                    type="button"
                                    className="btn-close btn-close-white"
                                    onClick={closeRejectModal}
                                    aria-label="Close"
                                />
                            </div>

                            <div className="modal-body p-4">
                                <p className="text-muted small mb-3">
                                    Select a standardized reason preset or enter specific feedback. The driver will receive this note immediately to submit a corrected document.
                                </p>

                                {/* Preset Chips */}
                                <label className="form-label small fw-bold text-dark mb-2">Quick Presets</label>
                                <div className="d-flex flex-wrap gap-2 mb-3">
                                    {REJECTION_PRESETS.map((preset, idx) => (
                                        <button
                                            key={idx}
                                            type="button"
                                            onClick={() => setRejectionReason(preset.text)}
                                            className="btn btn-sm btn-outline-secondary text-start"
                                            style={{ fontSize: '0.78rem' }}
                                        >
                                            🏷️ {preset.label}
                                        </button>
                                    ))}
                                </div>

                                {/* Custom Text Area */}
                                <label className="form-label small fw-bold text-dark mb-1">
                                    Reason Shown to Partner <span className="text-danger">*</span>
                                </label>
                                <textarea
                                    className="form-control"
                                    rows="4"
                                    value={rejectionReason}
                                    onChange={(e) => setRejectionReason(e.target.value)}
                                    placeholder="Explain clearly what was wrong with the document and how to fix it..."
                                />
                            </div>

                            <div className="modal-footer bg-light py-2 px-4 d-flex justify-content-between">
                                <button
                                    type="button"
                                    onClick={closeRejectModal}
                                    className="btn btn-outline-secondary"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={submitRejection}
                                    disabled={reviewMutation.isPending || !rejectionReason.trim()}
                                    className="btn btn-danger fw-bold px-4"
                                >
                                    {reviewMutation.isPending ? 'Submitting...' : 'Confirm Rejection'}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default DocumentReviewTab;
