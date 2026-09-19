import { useState } from 'react';
import { apiClient, authHeaders } from '../../api/client';
import { showToast } from '../common/Toast';
import VerifiedBadge from '../common/VerifiedBadge';

const documentTypes = [
    ['national_id', 'National ID'],
    ['passport', 'International passport'],
    ['utility_bill', 'Utility bill'],
    ['business_registration', 'Business registration'],
    ['other', 'Other supporting document'],
];
const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;
const ACCEPTED_DOCUMENT_TYPES = new Set([
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/webp',
]);

const ClientKycTab = ({ kyc, loading, onRefresh }) => {
    const [documentType, setDocumentType] = useState('national_id');
    const [documentNumber, setDocumentNumber] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [file, setFile] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [uploadProgress, setUploadProgress] = useState(0);

    const documents = kyc?.documents || [];
    const isVerified = kyc?.kyc_status === 'verified';

    const chooseFile = event => {
        const selected = event.target.files?.[0] || null;
        if (!selected) {
            setFile(null);
            return;
        }
        if (selected.size > MAX_DOCUMENT_BYTES) {
            showToast.error('This document is larger than the 10 MB upload limit. Please choose a smaller file.');
            event.target.value = '';
            setFile(null);
            return;
        }
        // The server verifies bytes independently; this only gives immediate
        // feedback on devices that provide a reliable MIME hint.
        if (selected.type && !ACCEPTED_DOCUMENT_TYPES.has(selected.type)) {
            showToast.error('Choose a PDF, JPEG, PNG, or WebP document.');
            event.target.value = '';
            setFile(null);
            return;
        }
        setFile(selected);
    };

    const upload = async event => {
        event.preventDefault();
        if (!file) return showToast.error('Choose a KYC document to upload.');
        const form = new FormData();
        form.append('document', file);
        form.append('document_type', documentType);
        if (documentNumber.trim()) form.append('document_number', documentNumber.trim());
        if (expiresAt) form.append('expires_at', expiresAt);
        setUploading(true);
        setUploadProgress(0);
        try {
            await apiClient.post('/client/kyc/documents/upload', form, {
                headers: { ...authHeaders(), 'Content-Type': 'multipart/form-data' },
                onUploadProgress: progressEvent => {
                    if (progressEvent.total) {
                        setUploadProgress(Math.round((progressEvent.loaded * 100) / progressEvent.total));
                    }
                },
            });
            showToast.success('KYC document submitted for review.');
            setFile(null);
            setDocumentNumber('');
            setExpiresAt('');
            event.target.reset();
            await onRefresh();
        } catch (error) {
            showToast.error(error.response?.data?.message || 'Could not upload this KYC document.');
        } finally {
            setUploading(false);
            setUploadProgress(0);
        }
    };

    const view = async documentId => {
        try {
            const response = await apiClient.get(
                `/client/kyc/documents/file?document_id=${documentId}`,
                {
                    headers: authHeaders(),
                    responseType: 'blob',
                }
            );
            const url = URL.createObjectURL(response.data);
            window.open(url, '_blank', 'noopener,noreferrer');
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (error) {
            showToast.error(error.response?.data?.message || 'Could not open this document.');
        }
    };
    if (loading) {
        return (
            <div className="card border-0 shadow-sm custom-card p-4">
                <div className="placeholder-glow">
                    <span className="placeholder col-5 mb-3 d-block" />
                    <span className="placeholder col-12 mb-2 d-block" />
                    <span className="placeholder col-10 d-block" />
                </div>
            </div>
        );
    }

    return (
        <div className="card border-0 shadow-sm custom-card p-4 kyc-workspace">
            <div className="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
                <div>
                    <h4 className="fw-bold mb-1">Identity verification</h4>
                    <p className="text-muted mb-0">
                        Your KYC documents are stored outside the public web root and visible only to
                        you and authorised operations staff.
                    </p>
                </div>
                {isVerified && <VerifiedBadge status="verified" label="KYC verified" />}
            </div>

            {kyc?.kyc_rejection_reason && (
                <div className="alert alert-danger">
                    <strong>Update required:</strong> {kyc.kyc_rejection_reason}
                </div>
            )}

            {!isVerified && (
                <form onSubmit={upload} className="border rounded p-3 mb-4 kyc-upload-form">
                    <h6 className="fw-bold">Upload or replace a KYC document</h6>
                    <p className="small text-muted">
                        Choose a clear photo, scan, or PDF from your phone. Your document is encrypted
                        in transit and reviewed by authorised staff.
                    </p>
                    <div className="row g-3">
                        <div className="col-md-4">
                            <label htmlFor="kyc-document-type" className="form-label small">
                                Document type
                            </label>
                            <select
                                id="kyc-document-type"
                                className="form-select"
                                value={documentType}
                                onChange={event => setDocumentType(event.target.value)}
                            >
                                {documentTypes.map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-4">
                            <label htmlFor="kyc-document-number" className="form-label small">
                                Document number <span className="text-muted">(optional)</span>
                            </label>
                            <input
                                id="kyc-document-number"
                                className="form-control"
                                value={documentNumber}
                                onChange={event => setDocumentNumber(event.target.value)}
                            />
                        </div>
                        <div className="col-md-4">
                            <label htmlFor="kyc-expiry-date" className="form-label small">
                                Expiry date <span className="text-muted">(optional)</span>
                            </label>
                            <input
                                id="kyc-expiry-date"
                                type="date"
                                className="form-control"
                                value={expiresAt}
                                onChange={event => setExpiresAt(event.target.value)}
                            />
                        </div>
                        <div className="col-md-8">
                            <label htmlFor="kyc-document-file" className="form-label small">
                                Private document file
                            </label>
                            <input
                                id="kyc-document-file"
                                type="file"
                                required
                                accept="application/pdf,image/jpeg,image/png,image/webp"
                                capture="environment"
                                className="form-control"
                                onChange={chooseFile}
                            />
                            <small className="text-muted">
                                PDF, JPEG, PNG, or WebP; maximum 10 MB.
                            </small>
                        </div>
                        <div className="col-md-4 d-flex align-items-end">
                            <button disabled={uploading} className="btn btn-primary w-100">
                                {uploading
                                    ? `Uploading ${uploadProgress}%…`
                                    : 'Submit for review'}
                            </button>
                        </div>
                    </div>

                    {uploading && (
                        <div
                            className="progress mt-3"
                            role="progressbar"
                            aria-label="KYC document upload progress"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            aria-valuenow={uploadProgress}
                        >
                            <div
                                className="progress-bar progress-bar-striped progress-bar-animated"
                                style={{ width: `${uploadProgress}%` }}
                            >
                                {uploadProgress}%
                            </div>
                        </div>
                    )}
                </form>
            )}

            <h6 className="fw-bold">Submitted documents</h6>
            {documents.length === 0 ? (
                <p className="text-muted mb-0">No KYC documents have been submitted.</p>
            ) : (
                <div className="table-responsive kyc-documents-table">
                    <table className="table align-middle">
                        <thead className="table-light">
                            <tr>
                                <th>Type</th>
                                <th>Number</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>File</th>
                            </tr>
                        </thead>
                        <tbody>
                            {documents.map(document => (
                                <tr key={document.id}>
                                    <td data-label="Document type" className="text-capitalize">
                                        {document.document_type.replaceAll('_', ' ')}
                                    </td>
                                    <td data-label="Number">
                                        {document.document_number || '—'}
                                    </td>
                                    <td data-label="Status">
                                        <span
                                            className={`badge ${
                                                document.verification_status === 'verified'
                                                    ? 'bg-success'
                                                    : document.verification_status === 'rejected'
                                                    ? 'bg-danger'
                                                    : 'bg-warning text-dark'
                                            }`}
                                        >
                                            {document.verification_status}
                                        </span>
                                        {document.rejection_reason && (
                                            <small className="d-block text-danger">
                                                {document.rejection_reason}
                                            </small>
                                        )}
                                    </td>
                                    <td data-label="Submitted">
                                        {new Date(document.created_at).toLocaleDateString()}
                                    </td>
                                    <td data-label="File">
                                        <button
                                            type="button"
                                            className="btn btn-sm btn-outline-primary"
                                            onClick={() => view(document.id)}
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
        </div>
    );
};

export default ClientKycTab;
