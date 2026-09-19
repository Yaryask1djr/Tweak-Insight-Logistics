import React, { useEffect, useState, useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient, apiGet, apiPost, authHeaders } from '../../api/client';
import { showToast } from '../common/Toast';
import { TableSkeleton } from '../common/SkeletonLoader';
import QueryState from '../common/QueryState';

const defaultVehicle = { vehicle_type: '', vehicle_registration: '', max_payload_kg: '' };

const DOCUMENT_TYPE_LABELS = {
    government_id: 'National / Government ID',
    drivers_license: "Driver's Licence",
    vehicle_registration: 'Vehicle Registration',
    proof_of_address: 'Proof of Address',
    profile_photo: 'Profile Photo'
};

const PartnerOperationsTab = ({ initialSection = 'all' }) => {
    const [file, setFile] = useState(null);
    const [previewUrl, setPreviewUrl] = useState(null);
    const [fileInfo, setFileInfo] = useState(null);
    const [documentType, setDocumentType] = useState('government_id');
    const [documentNumber, setDocumentNumber] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [selectedZone, setSelectedZone] = useState('');
    const [vehicle, setVehicle] = useState(defaultVehicle);
    const [useCamera, setUseCamera] = useState(false);

    const fileInputRef = useRef(null);
    const uploadFormRef = useRef(null);
    const vehicleSectionRef = useRef(null);
    const queryClient = useQueryClient();

    useEffect(() => {
        if (initialSection === 'profile-vehicle' && vehicleSectionRef.current) {
            vehicleSectionRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (initialSection === 'kyc-documents' && uploadFormRef.current) {
            uploadFormRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (initialSection === 'settings' && vehicleSectionRef.current) {
            vehicleSectionRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, [initialSection]);

    const profile = useQuery({
        queryKey: ['driver', 'operations-profile'],
        queryFn: () => apiGet('/delivery-person/operations-profile'),
        select: (response) => response.data
    });

    useEffect(() => {
        const current = profile.data?.profile;
        if (current) {
            setVehicle({
                vehicle_type: current.vehicle_type || '',
                vehicle_registration: current.vehicle_registration || '',
                max_payload_kg: current.max_payload_kg || ''
            });
        }
    }, [profile.data]);

    // Handle file selection and live preview generation
    const handleFileChange = (e) => {
        const selectedFile = e.target.files?.[0];
        if (!selectedFile) {
            clearSelectedFile();
            return;
        }

        if (selectedFile.size > 5 * 1024 * 1024) {
            showToast.error('File exceeds the 5 MB maximum limit. Please choose a smaller file.');
            clearSelectedFile();
            return;
        }

        setFile(selectedFile);
        setFileInfo({
            name: selectedFile.name,
            sizeKb: Math.round(selectedFile.size / 1024),
            type: selectedFile.type
        });

        if (selectedFile.type.startsWith('image/')) {
            const url = URL.createObjectURL(selectedFile);
            setPreviewUrl(url);
        } else {
            setPreviewUrl(null);
        }
    };

    const clearSelectedFile = () => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }
        setFile(null);
        setPreviewUrl(null);
        setFileInfo(null);
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const invalidate = () => queryClient.invalidateQueries({ queryKey: ['driver', 'operations-profile'] });


    const saveVehicleMutation = useMutation({
        mutationFn: (payload) => apiPost('/delivery-person/operations-profile', payload),
        onSuccess: () => {
            showToast.success('Vehicle profile updated.');
            invalidate();
        },
        onError: (err) => {
            showToast.error(err.response?.data?.message || 'Could not update vehicle profile.');
        }
    });

    const uploadMutation = useMutation({
        mutationFn: (form) =>
            apiClient.post('/delivery-person/documents/upload', form, { headers: authHeaders() }),
        onSuccess: () => {
            showToast.success('Document submitted successfully for operations review!');
            clearSelectedFile();
            setDocumentNumber('');
            setExpiresAt('');
            invalidate();
        },
        onError: (err) => {
            showToast.error(err.response?.data?.message || 'Document upload failed. Please try again.');
        }
    });

    const handleSubmitDocument = (e) => {
        e.preventDefault();
        if (!file) {
            return showToast.error('Please choose or photograph a document first.');
        }

        // Client-side expiry validation
        if (expiresAt) {
            const exp = new Date(expiresAt);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (exp < today) {
                return showToast.error('The selected expiry date has already passed. Please verify your document.');
            }
        }

        const form = new FormData();
        form.append('document', file);
        form.append('document_type', documentType);
        if (documentNumber) form.append('document_number', documentNumber);
        if (expiresAt) form.append('expires_at', expiresAt);

        uploadMutation.mutate(form);
    };

    const handleResubmit = (docType) => {
        setDocumentType(docType);
        showToast.info(`Selected ${DOCUMENT_TYPE_LABELS[docType] || docType} for re-upload.`);
        if (uploadFormRef.current) {
            uploadFormRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    };

    const viewDocument = async (documentId) => {
        try {
            const response = await apiClient.get(`/delivery-person/documents/file?document_id=${documentId}`, {
                headers: authHeaders(),
                responseType: 'blob'
            });
            const url = URL.createObjectURL(response.data);
            window.open(url, '_blank', 'noopener,noreferrer');
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (error) {
            showToast.error(error.response?.data?.message || 'Could not open this KYC document.');
        }
    };

    if (profile.isError && !profile.data) {
        return <QueryState query={profile} loading={<TableSkeleton rows={4} cols={4} />} />;
    }

    if (profile.isLoading) {
        return (
            <div className="card border-0 shadow-sm custom-card p-4">
                <TableSkeleton rows={4} cols={4} />
            </div>
        );
    }

    const data = profile.data || {};
    const partner = data.profile || {};
    const documents = data.documents || [];
    const zones = data.zones || [];

    const isVerified = partner.kyc_status === 'verified';

    return (
        <div className="card border-0 shadow-sm custom-card p-4">
            {/* Top Status Header */}
            <div className="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h4 className="fw-bold mb-1">KYC Identity & Documents</h4>
                    <p className="text-muted small mb-0">
                        Upload identity and vehicle verification documents for Kano operations clearance.
                    </p>
                </div>
                <div className="d-flex align-items-center gap-2">
                    <span className={`status-pill status-${isVerified ? 'completed' : 'under_review'}`}>
                        {isVerified ? '✓ KYC Verified' : (partner.kyc_status || 'not submitted').replaceAll('_', ' ')}
                    </span>
                </div>
            </div>

            {/* Explanatory notice: KYC required for account approval and dispatch access */}
            <div className="alert alert-light border border-info border-opacity-25 d-flex align-items-start gap-3 mb-4 bg-info bg-opacity-10 text-dark">
                <span className="fs-5">ℹ️</span>
                <div className="small">
                    <strong>KYC & Verification Requirement:</strong> Valid identity and vehicle documents must be verified by operations to clear your account for dispatch access. Once verified, manage your live shifts and daily dispatch availability from the <strong>Overview</strong> console.
                </div>
            </div>

            {partner.kyc_rejection_reason && (
                <div className="alert alert-danger d-flex align-items-center gap-3 mb-4">
                    <span className="fs-4">⚠️</span>
                    <div>
                        <strong className="d-block">Account Review Notice</strong>
                        <span className="small">{partner.kyc_rejection_reason}</span>
                    </div>
                </div>
            )}

            {/* Account & Verification Status Metrics */}
            <div className="row g-3 mb-4">
                <div className="col-md-6">
                    <div className="border rounded p-3 h-100 bg-light">
                        <small className="text-muted d-block">Account Standing</small>
                        <strong className="text-capitalize text-dark fs-6">{partner.active_status || 'inactive'}</strong>
                    </div>
                </div>
                <div className="col-md-6">
                    <div className="border rounded p-3 h-100 bg-light">
                        <small className="text-muted d-block">KYC Verification Status</small>
                        <strong className="text-capitalize text-dark fs-6">{(partner.kyc_status || 'not submitted').replaceAll('_', ' ')}</strong>
                    </div>
                </div>
            </div>

            {/* Dispatch Zone & Vehicle Form */}
            <div ref={vehicleSectionRef} id="vehicle-section" className="border rounded p-3 mb-4 bg-white">
                <h6 className="fw-bold text-dark mb-3">Operating Zone & Vehicle Profile</h6>
                <div className="row g-3">
                    <div className="col-md-4">
                        <label className="form-label small">Preferred Kano Service Hub</label>
                        <select
                            className="form-select"
                            value={selectedZone}
                            onChange={(e) => setSelectedZone(e.target.value)}
                        >
                            <option value="">Any Active Hub</option>
                            {zones.map((zone) => (
                                <option key={zone.id} value={zone.id}>
                                    {zone.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="col-md-8">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                saveVehicleMutation.mutate(vehicle);
                            }}
                            className="row g-2"
                        >
                            <div className="col-md-4">
                                <label className="form-label small">Vehicle Type</label>
                                <input
                                    className="form-control"
                                    value={vehicle.vehicle_type}
                                    onChange={(e) => setVehicle({ ...vehicle, vehicle_type: e.target.value })}
                                    placeholder="Motorcycle"
                                />
                            </div>
                            <div className="col-md-4">
                                <label className="form-label small">Plate Number</label>
                                <input
                                    className="form-control"
                                    value={vehicle.vehicle_registration}
                                    onChange={(e) => setVehicle({ ...vehicle, vehicle_registration: e.target.value })}
                                    placeholder="KMC-421-AA"
                                />
                            </div>
                            <div className="col-md-4">
                                <label className="form-label small">Payload (kg)</label>
                                <div className="d-flex gap-2">
                                    <input
                                        type="number"
                                        min="0"
                                        className="form-control"
                                        value={vehicle.max_payload_kg}
                                        onChange={(e) => setVehicle({ ...vehicle, max_payload_kg: e.target.value })}
                                        placeholder="50"
                                    />
                                    <button disabled={saveVehicleMutation.isPending} className="btn btn-outline-primary px-3">
                                        Save
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <hr className="my-4" />

            {/* Document Upload Section */}
            <div ref={uploadFormRef} className="mb-4">
                <div className="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 className="fw-bold text-dark mb-0">Submit KYC Document</h5>
                        <small className="text-muted">
                            Mandatory for partner approval: <strong>Government ID</strong> and <strong>Driver Licence</strong>.
                        </small>
                    </div>
                    {/* Camera Toggle */}
                    <div className="form-check form-switch">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            id="cameraModeSwitch"
                            checked={useCamera}
                            onChange={(e) => setUseCamera(e.target.checked)}
                        />
                        <label className="form-check-label small fw-semibold text-dark" htmlFor="cameraModeSwitch">
                            📷 Camera Mode
                        </label>
                    </div>
                </div>

                <form onSubmit={handleSubmitDocument} className="row g-3">
                    <div className="col-md-4">
                        <label className="form-label small fw-bold">Document Category</label>
                        <select
                            value={documentType}
                            onChange={(e) => setDocumentType(e.target.value)}
                            className="form-select"
                        >
                            <option value="government_id">National / Government ID</option>
                            <option value="drivers_license">Driver Licence</option>
                            <option value="vehicle_registration">Vehicle Registration</option>
                            <option value="proof_of_address">Proof of Address (Utility Bill)</option>
                            <option value="profile_photo">Rider Passport Photo</option>
                        </select>
                    </div>

                    <div className="col-md-4">
                        <label className="form-label small fw-bold">
                            Document Number <span className="text-muted font-normal">(optional)</span>
                        </label>
                        <input
                            value={documentNumber}
                            onChange={(e) => setDocumentNumber(e.target.value)}
                            className="form-control"
                            placeholder="e.g. DL-12345678"
                        />
                    </div>

                    <div className="col-md-4">
                        <label className="form-label small fw-bold">
                            Expiry Date <span className="text-muted font-normal">(optional)</span>
                        </label>
                        <input
                            type="date"
                            value={expiresAt}
                            onChange={(e) => setExpiresAt(e.target.value)}
                            className="form-control"
                        />
                    </div>

                    {/* File Picker & Pre-Upload Thumbnail Preview */}
                    <div className="col-12">
                        <label className="form-label small fw-bold">Document Photo / PDF</label>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/*,application/pdf"
                            capture={useCamera ? 'environment' : undefined}
                            onChange={handleFileChange}
                            className="form-control"
                        />
                        <small className="text-muted">
                            Supported formats: PDF, JPEG, PNG, or WebP up to 5 MB. Stored securely on encrypted cloud storage.
                        </small>
                    </div>

                    {/* Pre-Upload Thumbnail Card */}
                    {fileInfo && (
                        <div className="col-12">
                            <div className="card bg-light border p-3">
                                <div className="d-flex align-items-center justify-content-between flex-wrap gap-3">
                                    <div className="d-flex align-items-center gap-3">
                                        {previewUrl ? (
                                            <img
                                                src={previewUrl}
                                                alt="Upload Preview"
                                                style={{
                                                    width: '64px',
                                                    height: '64px',
                                                    objectFit: 'cover',
                                                    borderRadius: '8px'
                                                }}
                                            />
                                        ) : (
                                            <div
                                                className="bg-primary text-white d-flex align-items-center justify-content-center fw-bold"
                                                style={{ width: '64px', height: '64px', borderRadius: '8px' }}
                                            >
                                                PDF
                                            </div>
                                        )}
                                        <div>
                                            <strong className="d-block text-dark small">{fileInfo.name}</strong>
                                            <span className="text-muted small">
                                                Size: {fileInfo.sizeKb} KB | Type: {fileInfo.type}
                                            </span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={clearSelectedFile}
                                        className="btn btn-sm btn-outline-danger"
                                    >
                                        ✕ Remove File
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="col-12 d-flex justify-content-end">
                        <button
                            type="submit"
                            className="btn btn-primary px-4 py-2 fw-semibold"
                            disabled={uploadMutation.isPending || !file}
                        >
                            {uploadMutation.isPending ? 'Uploading Securely…' : 'Submit Document for Review →'}
                        </button>
                    </div>
                </form>
            </div>

            <hr className="my-4" />

            {/* Submitted Documents Status Table */}
            <div>
                <h5 className="fw-bold text-dark mb-3">Submitted KYC Documents</h5>
                {documents.length === 0 ? (
                    <div className="alert alert-light border text-center py-4">
                        <p className="text-muted mb-0">
                            No documents submitted yet. Submit a verified <strong>Government ID</strong> and{' '}
                            <strong>Driver Licence</strong> to receive delivery jobs.
                        </p>
                    </div>
                ) : (
                    <div className="table-responsive">
                        <table className="table table-hover align-middle">
                            <thead className="table-light">
                                <tr>
                                    <th>Document Type</th>
                                    <th>Status</th>
                                    <th>Expiry Date</th>
                                    <th>Operations Feedback</th>
                                    <th className="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                {documents.map((doc) => (
                                    <tr key={doc.id}>
                                        <td className="text-capitalize fw-semibold text-dark">
                                            {doc.document_type.replaceAll('_', ' ')}
                                        </td>
                                        <td>
                                            <span
                                                className={`status-pill status-${
                                                    doc.verification_status === 'verified'
                                                        ? 'completed'
                                                        : doc.verification_status === 'rejected'
                                                        ? 'rejected'
                                                        : 'under_review'
                                                }`}
                                            >
                                                {doc.verification_status}
                                            </span>
                                        </td>
                                        <td>{doc.expires_at || '—'}</td>
                                        <td>
                                            {doc.rejection_reason ? (
                                                <span className="text-danger small fw-semibold">
                                                    ⚠️ {doc.rejection_reason}
                                                </span>
                                            ) : doc.verification_status === 'verified' ? (
                                                <span className="text-success small">✓ Verified by Operations</span>
                                            ) : (
                                                <span className="text-muted small">In review queue</span>
                                            )}
                                        </td>
                                        <td className="text-end">
                                            {doc.verification_status === 'rejected' && (
                                                <button
                                                    onClick={() => handleResubmit(doc.document_type)}
                                                    className="btn btn-sm btn-danger fw-semibold"
                                                >
                                                    ↺ Resubmit
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-secondary ms-2"
                                                onClick={() => viewDocument(doc.id)}
                                            >
                                                View
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </div>
    );
};

export default PartnerOperationsTab;
