import React, { useState } from 'react';
import { apiClient } from '../../api/client';
import { showToast } from '../common/Toast';
import { useAuth } from '../AuthContext';

const OUT_OF_AREA_REGEX = /\b(abuja|lagos|ibadan|kaduna|port harcourt|enugu|benin|ilorin|jos|maiduguri|sokoto|zaria|calabar|owerri|warri|asaba|aba)\b/i;

// Kano Landmark chips and coordinates
const KANO_LANDMARK_COORDINATES = {
    'Sabon Gari Market': { lat: 12.0022, lng: 8.5385 },
    'BUK New Campus': { lat: 11.9780, lng: 8.4230 },
    'Nasarawa GRA': { lat: 11.9890, lng: 8.5520 },
    'Trade Fair': { lat: 11.9950, lng: 8.5450 },
    'Kantin Kwari Market': { lat: 11.9961, lng: 8.5274 },
    'Zoo Road': { lat: 11.9730, lng: 8.5250 },
    'Sharada Industrial': { lat: 11.9650, lng: 8.4950 },
    'Bompai Industrial': { lat: 12.0150, lng: 8.5550 },
    'Hotoro GRA': { lat: 11.9610, lng: 8.5830 },
    'Tarauni': { lat: 11.9680, lng: 8.5420 },
};

const KANO_LANDMARKS = Object.keys(KANO_LANDMARK_COORDINATES);

// Simple, non-technical package size options
const PACKAGE_SIZES = [
    {
        id: 'envelope',
        title: 'Envelope',
        icon: '✉️',
        description: 'Letters, documents, legal papers, contracts, certificates & flat packages',
        badge: 'Fast Dispatch',
    },
    {
        id: 'small_package',
        title: 'Small Package',
        icon: '📦',
        description: 'Boxes, parcels, electronics, shoes, clothing, groceries & packaged items',
        badge: 'Standard Delivery',
    },
    {
        id: 'large_package',
        title: 'Large Package',
        icon: '🚚',
        description: 'Bulky cartons, wholesale merchandise, crates, multi-item goods & heavy stock',
        badge: 'Cargo Transport',
    },
];

const BookDeliveryTab = ({ onCreated }) => {
    const { user } = useAuth();

    const isKanoAddress = (address) => {
        const trimmed = (address || '').trim();
        if (trimmed.length < 3) return false;
        return !OUT_OF_AREA_REGEX.test(trimmed);
    };

    const [step, setStep] = useState(1);
    const [selectedSize, setSelectedSize] = useState('small_package');
    const [form, setForm] = useState({
        pickup_address: '',
        pickup_city: 'Kano',
        // Sender identity is always the authenticated client
        pickup_contact_name: user?.full_name || '',
        pickup_contact_phone: user?.phone || '',
        pickup_lat: null,
        pickup_lng: null,
        delivery_address: '',
        delivery_city: 'Kano',
        delivery_contact_name: '',
        delivery_contact_phone: '',
        delivery_lat: null,
        delivery_lng: null,
        package_size: 'small_package',
        item_description: 'Packaged parcel / items',
        item_quantity: 1,
        special_instructions: '',
        is_fragile: false,
        is_perishable: false,
    });
    const [priceBreakdown, setPriceBreakdown] = useState(null);
    const [message, setMessage] = useState('');
    const [loading, setLoading] = useState(false);
    const [createdTrackingNumber, setCreatedTrackingNumber] = useState('');

    const handleChange = (e) => {
        const value = e.target.type === 'checkbox' ? e.target.checked : e.target.value;
        setForm(prev => ({ ...prev, [e.target.name]: value }));
    };

    const appendLandmark = (field, landmark) => {
        const coords = KANO_LANDMARK_COORDINATES[landmark];
        setForm(prev => {
            const current = (prev[field] || '').trim();
            const updatedAddress = !current
                ? `${landmark}, Kano`
                : (current.toLowerCase().includes(landmark.toLowerCase()) ? current : `${current}, ${landmark}`);

            const updates = { [field]: updatedAddress };
            if (coords) {
                if (field === 'pickup_address') {
                    updates.pickup_lat = coords.lat;
                    updates.pickup_lng = coords.lng;
                } else {
                    updates.delivery_lat = coords.lat;
                    updates.delivery_lng = coords.lng;
                }
            }
            return { ...prev, ...updates };
        });
    };

    const selectPackageSize = (size) => {
        setSelectedSize(size.id);
        setForm(prev => ({
            ...prev,
            package_size: size.id,
            item_description: prev.item_description || (
                size.id === 'envelope'
                    ? 'Letters & documents'
                    : size.id === 'small_package'
                        ? 'Packaged parcel / items'
                        : 'Commercial cargo merchandise'
            ),
        }));
    };

    const calculatePrice = async () => {
        if (!form.item_description?.trim()) {
            setMessage('Please enter a brief description of what you are sending.');
            return;
        }

        if (!isKanoAddress(form.pickup_address) || !isKanoAddress(form.delivery_address)) {
            setMessage('Tweak Insight Logistics is Kano-only. Please provide addresses within Kano State.');
            return;
        }

        setLoading(true);
        setMessage('');
        try {
            const res = await apiClient.post('/deliveries/calculate-price', {
                pickup_address: form.pickup_address,
                delivery_address: form.delivery_address,
                pickup_city: form.pickup_city,
                delivery_city: form.delivery_city,
                pickup_lat: form.pickup_lat,
                pickup_lng: form.pickup_lng,
                delivery_lat: form.delivery_lat,
                delivery_lng: form.delivery_lng,
                package_size: selectedSize,
                item_quantity: parseInt(form.item_quantity, 10) || 1,
                is_fragile: form.is_fragile,
                is_perishable: form.is_perishable,
            });
            const quote = res.data.data;
            setPriceBreakdown(quote);
            setStep(3);
        } catch (err) {
            const errMsg = err.response?.data?.message || 'Failed to calculate delivery fare.';
            setMessage(errMsg);
            showToast.error(errMsg);
        } finally {
            setLoading(false);
        }
    };

    const submitDelivery = async () => {
        setLoading(true);
        setMessage('');
        try {
            const payload = {
                ...form,
                package_size: selectedSize,
                item_quantity: parseInt(form.item_quantity, 10) || 1,
                pickup_latitude: form.pickup_lat,
                pickup_longitude: form.pickup_lng,
                delivery_latitude: form.delivery_lat,
                delivery_longitude: form.delivery_lng,
                ...(priceBreakdown || {}),
            };

            const res = await apiClient.post('/deliveries/create', payload);
            const createdDelivery = res.data.data;
            const trackingNum = createdDelivery?.tracking_number || '';
            setCreatedTrackingNumber(trackingNum);
            const succMsg = `🎉 Delivery request created! Reference ID #${trackingNum}`;
            setMessage(succMsg);
            showToast.success(succMsg);
            setStep(4);
        } catch (err) {
            const errMsg = err.response?.data?.message || 'Failed to submit request';
            setMessage(errMsg);
            showToast.error(errMsg);
        } finally {
            setLoading(false);
        }
    };

    const isStep1Valid =
        Boolean(form.pickup_address?.trim()) &&
        Boolean(form.delivery_address?.trim()) &&
        Boolean(form.delivery_contact_phone?.trim()) &&
        isKanoAddress(form.pickup_address) &&
        isKanoAddress(form.delivery_address);

    return (
        <div className="card border-0 shadow-sm custom-card p-4 delivery-request-card">
            {/* Header with Title */}
            <div className="d-flex justify-content-between align-items-center gap-2 mb-4 border-bottom pb-3 delivery-request-header">
                <div>
                    <h4 className="fw-bold mb-1">📦 Book a New Delivery</h4>
                    <p className="text-muted small mb-0">Fast, on-demand dispatch across Kano Metropolitan</p>
                </div>
                {step <= 3 && (
                    <span className="badge bg-primary px-3 py-2 fs-6">
                        Step {step} of 3
                    </span>
                )}
            </div>

            {/* Visual 3-Step Progressive Stepper */}
            {step <= 3 && (
                <div className="booking-wizard-stepper" aria-label="Booking steps">
                    <div className={`wizard-step-node ${step >= 1 ? 'active' : ''} ${step > 1 ? 'completed' : ''}`}>
                        <div className="wizard-step-circle">
                            {step > 1 ? '✓' : '1'}
                        </div>
                        <div className="wizard-step-info">
                            <span className="wizard-step-title">1. Route & Locations</span>
                            <small className="wizard-step-subtitle d-none d-sm-block">Where is it going?</small>
                        </div>
                    </div>

                    <div className={`wizard-step-connector ${step > 1 ? 'active' : ''}`} />

                    <div className={`wizard-step-node ${step >= 2 ? 'active' : ''} ${step > 2 ? 'completed' : ''}`}>
                        <div className="wizard-step-circle">
                            {step > 2 ? '✓' : '2'}
                        </div>
                        <div className="wizard-step-info">
                            <span className="wizard-step-title">2. Package Details</span>
                            <small className="wizard-step-subtitle d-none d-sm-block">What are you sending?</small>
                        </div>
                    </div>

                    <div className={`wizard-step-connector ${step >= 3 ? 'active' : ''}`} />

                    <div className={`wizard-step-node ${step >= 3 ? 'active' : ''} ${step > 3 ? 'completed' : ''}`}>
                        <div className="wizard-step-circle">
                            {step > 3 ? '✓' : '3'}
                        </div>
                        <div className="wizard-step-info">
                            <span className="wizard-step-title">3. Confirm Price</span>
                            <small className="wizard-step-subtitle d-none d-sm-block">Review & dispatch</small>
                        </div>
                    </div>
                </div>
            )}

            {message && step !== 4 && (
                <div className="alert alert-danger py-2 d-flex align-items-center gap-2 mb-4">
                    <span>⚠️</span>
                    <div>{message}</div>
                </div>
            )}

            {/* ── STEP 1: Route & Kano Locations ─────────────────────────────────────── */}
            {step === 1 && (
                <div className="delivery-request-step">
                    <h5 className="fw-bold mb-3 d-flex align-items-center gap-2">
                        <span>📍</span>
                        <span>Step 1: Pickup & Destination Locations (Kano)</span>
                    </h5>

                    <div className="row g-4">
                        {/* Pickup Section */}
                        <div className="col-md-6">
                            <div className="delivery-route-section delivery-route-section--pickup h-100 p-3 rounded border">
                                <div className="d-flex align-items-center justify-content-between mb-2">
                                    <h6 className="text-primary fw-bold mb-0">📍 Pickup Location</h6>
                                    <span className="badge bg-primary-subtle text-primary border border-primary-subtle">Origin</span>
                                </div>

                                <div className="mb-3">
                                    <label className="form-label small fw-semibold">Pickup Address</label>
                                    <textarea
                                        name="pickup_address"
                                        className="form-control"
                                        rows="2"
                                        placeholder="e.g. Sabon Gari Market, Section C, Shop 42"
                                        value={form.pickup_address}
                                        onChange={handleChange}
                                        required
                                    />

                                    {/* Kano Landmark Chips */}
                                    <div className="mt-2">
                                        <small className="text-muted d-block fw-semibold" style={{ fontSize: '11px' }}>
                                            Quick Kano Landmarks:
                                        </small>
                                        <div className="landmark-chips-wrapper">
                                            {KANO_LANDMARKS.map(landmark => (
                                                <button
                                                    key={`pickup-${landmark}`}
                                                    type="button"
                                                    className="landmark-chip"
                                                    onClick={() => appendLandmark('pickup_address', landmark)}
                                                    title={`Add ${landmark} to pickup`}
                                                >
                                                    + {landmark}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                {/* Sender identity — auto-filled from the client's registered account */}
                                <div className="d-flex align-items-center gap-2 mt-1 p-2 rounded bg-light border" style={{ fontSize: '0.8rem' }}>
                                    <span>👤</span>
                                    <div>
                                        <span className="fw-semibold">{user?.full_name}</span>
                                        <span className="text-muted mx-1">·</span>
                                        <span className="text-muted">{user?.phone}</span>
                                    </div>
                                    <span className="badge bg-secondary ms-auto" style={{ fontSize: '0.65rem' }}>Your account</span>
                                </div>
                            </div>
                        </div>

                        {/* Destination Section */}
                        <div className="col-md-6">
                            <div className="delivery-route-section delivery-route-section--destination h-100 p-3 rounded border">
                                <div className="d-flex align-items-center justify-content-between mb-2">
                                    <h6 className="text-success fw-bold mb-0">🏁 Delivery Destination</h6>
                                    <span className="badge bg-success-subtle text-success border border-success-subtle">Destination</span>
                                </div>

                                <div className="mb-3">
                                    <label className="form-label small fw-semibold">Delivery Address</label>
                                    <textarea
                                        name="delivery_address"
                                        className="form-control"
                                        rows="2"
                                        placeholder="e.g. BUK New Campus, Faculty of Law, Gate 2"
                                        value={form.delivery_address}
                                        onChange={handleChange}
                                        required
                                    />

                                    {/* Kano Landmark Chips */}
                                    <div className="mt-2">
                                        <small className="text-muted d-block fw-semibold" style={{ fontSize: '11px' }}>
                                            Quick Kano Landmarks:
                                        </small>
                                        <div className="landmark-chips-wrapper">
                                            {KANO_LANDMARKS.map(landmark => (
                                                <button
                                                    key={`delivery-${landmark}`}
                                                    type="button"
                                                    className="landmark-chip"
                                                    onClick={() => appendLandmark('delivery_address', landmark)}
                                                    title={`Add ${landmark} to destination`}
                                                >
                                                    + {landmark}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                <div className="row g-2">
                                    <div className="col-sm-6">
                                        <label className="form-label small fw-semibold">Recipient Name</label>
                                        <input
                                            name="delivery_contact_name"
                                            className="form-control"
                                            placeholder="e.g. Fatima Yusuf"
                                            value={form.delivery_contact_name}
                                            onChange={handleChange}
                                            required
                                        />
                                    </div>
                                    <div className="col-sm-6">
                                        <label className="form-label small fw-semibold">Recipient Phone</label>
                                        <input
                                            name="delivery_contact_phone"
                                            className="form-control"
                                            placeholder="+234..."
                                            value={form.delivery_contact_phone}
                                            onChange={handleChange}
                                            required
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="form-actions form-actions--end mt-4 d-flex justify-content-between align-items-center">
                        <small className="text-muted">
                            📍 Service limited exclusively to Kano Metropolitan
                        </small>
                        <button
                            type="button"
                            className="btn btn-primary px-4 py-2 fw-bold"
                            style={{ minHeight: '48px' }}
                            disabled={!isStep1Valid}
                            onClick={() => {
                                setMessage('');
                                setStep(2);
                            }}
                        >
                            Next: Package Details →
                        </button>
                    </div>
                </div>
            )}

            {/* ── STEP 2: Package Details (Simplified for Non-Technical Users) ─────────── */}
            {step === 2 && (
                <div className="delivery-request-step">
                    <h5 className="fw-bold mb-2 d-flex align-items-center gap-2">
                        <span>📦</span>
                        <span>Step 2: What Are You Sending?</span>
                    </h5>
                    <p className="text-muted small mb-3">
                        Choose the package size and any special handling options for your delivery.
                    </p>

                    {/* Large, Obvious Package Size Selection Cards */}
                    <label className="form-label small fw-bold text-uppercase tracking-wider text-muted mb-2">
                        Package Size:
                    </label>
                    <div className="package-size-grid mb-4">
                        {PACKAGE_SIZES.map(pkg => {
                            const isSelected = selectedSize === pkg.id;
                            return (
                                <div
                                    key={pkg.id}
                                    className={`package-size-tile ${isSelected ? 'active' : ''}`}
                                    onClick={() => selectPackageSize(pkg)}
                                    role="button"
                                    tabIndex={0}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            selectPackageSize(pkg);
                                        }
                                    }}
                                >
                                    <div className="package-tile-header">
                                        <span className="package-tile-icon" aria-hidden="true">
                                            {pkg.icon}
                                        </span>
                                        <span className={`badge package-tile-badge ${isSelected ? 'bg-primary text-white' : 'bg-light text-dark border'}`}>
                                            {isSelected ? 'Selected ✓' : pkg.badge}
                                        </span>
                                    </div>
                                    <h6 className="package-tile-title">{pkg.title}</h6>
                                    <p className="package-tile-desc mb-0">{pkg.description}</p>
                                </div>
                            );
                        })}
                    </div>

                    {/* Package Description and Quantity */}
                    <div className="row g-3 p-3 bg-light rounded border mb-4">
                        <div className="col-md-9">
                            <label className="form-label small fw-semibold">
                                Package Contents / Description <span className="text-danger">*</span>
                            </label>
                            <input
                                name="item_description"
                                className="form-control"
                                placeholder="What is inside? e.g. Shoes, Legal documents, Smartphone, Groceries..."
                                value={form.item_description}
                                onChange={handleChange}
                                required
                            />
                        </div>

                        <div className="col-md-3">
                            <label className="form-label small fw-semibold">Quantity</label>
                            <input
                                name="item_quantity"
                                type="number"
                                min="1"
                                max="50"
                                className="form-control"
                                value={form.item_quantity}
                                onChange={handleChange}
                            />
                        </div>
                    </div>

                    {/* Simple Handling Options */}
                    <label className="form-label small fw-bold text-uppercase tracking-wider text-muted mb-2">
                        Special Handling (Optional):
                    </label>
                    <div className="handling-options-grid mb-4">
                        <label className={`handling-card ${form.is_fragile ? 'active' : ''}`}>
                            <input
                                name="is_fragile"
                                type="checkbox"
                                className="form-check-input"
                                checked={form.is_fragile}
                                onChange={handleChange}
                            />
                            <div>
                                <div className="d-flex align-items-center gap-2 mb-1">
                                    <strong className="text-danger">⚠️ Fragile Cargo</strong>
                                </div>
                                <small className="text-muted d-block">
                                    Handled with extra care for delicate items, glassware, or electronics.
                                </small>
                            </div>
                        </label>

                        <label className={`handling-card ${form.is_perishable ? 'active-perishable' : ''}`}>
                            <input
                                name="is_perishable"
                                type="checkbox"
                                className="form-check-input"
                                checked={form.is_perishable}
                                onChange={handleChange}
                            />
                            <div>
                                <div className="d-flex align-items-center gap-2 mb-1">
                                    <strong className="text-warning-emphasis">🧊 Perishable / Express</strong>
                                </div>
                                <small className="text-muted d-block">
                                    Fast direct dispatch for fresh food, groceries, pharmaceuticals, or urgent deliveries.
                                </small>
                            </div>
                        </label>
                    </div>

                    {/* Special Driver Instructions */}
                    <div className="mb-4">
                        <label className="form-label small fw-semibold">Special Driver Instructions (Optional)</label>
                        <input
                            name="special_instructions"
                            className="form-control"
                            placeholder="e.g. Call upon arrival at gate, leave with security, ring the bell..."
                            value={form.special_instructions}
                            onChange={handleChange}
                        />
                    </div>

                    <div className="form-actions mt-4 d-flex justify-content-between align-items-center">
                        <button
                            type="button"
                            className="btn btn-outline-secondary px-4"
                            style={{ minHeight: '48px' }}
                            onClick={() => setStep(1)}
                        >
                            ← Back to Locations
                        </button>
                        <button
                            type="button"
                            className="btn btn-primary px-4 fw-bold d-flex align-items-center gap-2"
                            style={{ minHeight: '48px' }}
                            disabled={loading || !form.item_description?.trim()}
                            onClick={calculatePrice}
                        >
                            {loading ? (
                                <>
                                    <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                                    Calculating Price...
                                </>
                            ) : (
                                'Review Delivery Price →'
                            )}
                        </button>
                    </div>
                </div>
            )}

            {/* ── STEP 3: Confirm Delivery Price (Simple Fare Quote) ─────────────────── */}
            {step === 3 && priceBreakdown && (
                <div className="delivery-request-step">
                    <h5 className="fw-bold mb-2 d-flex align-items-center gap-2">
                        <span>💰</span>
                        <span>Step 3: Confirm Delivery Price</span>
                    </h5>
                    <p className="text-muted small mb-4">
                        Review your delivery details and transparent price quote before dispatch.
                    </p>

                    <div className="row g-4">
                        {/* Delivery Summary Card */}
                        <div className="col-md-6">
                            <div className="bg-light p-3 rounded border h-100">
                                <h6 className="fw-bold mb-3 border-bottom pb-2">📋 Delivery Summary</h6>

                                <div className="mb-3">
                                    <small className="text-muted d-block">📍 Pickup Origin</small>
                                    <strong className="d-block text-dark">{form.pickup_address}</strong>
                                    <small className="text-muted">Sender: {form.pickup_contact_name} ({form.pickup_contact_phone})</small>
                                </div>

                                <div className="mb-3">
                                    <small className="text-muted d-block">🏁 Destination</small>
                                    <strong className="d-block text-dark">{form.delivery_address}</strong>
                                    <small className="text-muted">Recipient: {form.delivery_contact_name} ({form.delivery_contact_phone})</small>
                                </div>

                                <hr className="my-2" />

                                <div className="small">
                                    <div className="d-flex justify-content-between py-1">
                                        <span className="text-muted">Package:</span>
                                        <strong className="text-dark">
                                            {PACKAGE_SIZES.find(p => p.id === selectedSize)?.title || 'Small Package'}
                                            {form.item_quantity > 1 ? ` (${form.item_quantity} items)` : ''}
                                        </strong>
                                    </div>
                                    <div className="d-flex justify-content-between py-1">
                                        <span className="text-muted">Contents:</span>
                                        <strong className="text-dark">{form.item_description}</strong>
                                    </div>
                                    <div className="mt-2">
                                        {form.is_fragile && (
                                            <span className="badge bg-danger me-1">⚠️ Fragile Handling</span>
                                        )}
                                        {form.is_perishable && (
                                            <span className="badge bg-warning text-dark me-1">🧊 Express / Perishable</span>
                                        )}
                                    </div>
                                    {form.special_instructions && (
                                        <div className="mt-2 p-2 rounded bg-white border text-muted fst-italic">
                                            Note for rider: "{form.special_instructions}"
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {/* Clean Fare Quote Card */}
                        <div className="col-md-6">
                            <div className="card border-primary p-4 shadow-sm bg-white live-quote-card h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div className="d-flex justify-content-between align-items-center mb-3">
                                        <h6 className="fw-bold text-primary mb-0">Delivery Price Quote</h6>
                                        <span className="badge bg-success-subtle text-success border border-success">All Inclusive</span>
                                    </div>

                                    <div className="text-center py-3 bg-light rounded border mb-3">
                                        <span className="text-muted small d-block mb-1">Total Delivery Fare</span>
                                        <span className="display-6 fw-bold text-success">
                                            ₦{Number(priceBreakdown.total_cost || 0).toLocaleString()}
                                        </span>
                                    </div>

                                    <ul className="list-unstyled small text-muted mb-0">
                                        <li className="d-flex align-items-center gap-2 mb-2">
                                            <span className="text-success fw-bold">✓</span>
                                            <span>Fixed transparent price — no hidden surcharges</span>
                                        </li>
                                        <li className="d-flex align-items-center gap-2 mb-2">
                                            <span className="text-success fw-bold">✓</span>
                                            <span>City-wide Kano Metropolis dispatch</span>
                                        </li>
                                        <li className="d-flex align-items-center gap-2 mb-2">
                                            <span className="text-success fw-bold">✓</span>
                                            <span>Assigned verified rider with live GPS map tracking</span>
                                        </li>
                                        <li className="d-flex align-items-center gap-2">
                                            <span className="text-success fw-bold">✓</span>
                                            <span>Secure recipient confirmation code (OTP) at handover</span>
                                        </li>
                                    </ul>
                                </div>

                                <div className="mt-4 pt-3 border-top">
                                    <small className="text-muted d-block mb-3 text-center">
                                        🔒 Handover OTP will be issued immediately upon dispatch confirmation.
                                    </small>
                                    <div className="d-flex gap-2">
                                        <button
                                            type="button"
                                            className="btn btn-outline-secondary w-50"
                                            style={{ minHeight: '48px' }}
                                            onClick={() => setStep(2)}
                                        >
                                            ← Modify
                                        </button>
                                        <button
                                            type="button"
                                            className="btn btn-success w-50 fw-bold d-flex align-items-center justify-content-center gap-2 shadow-sm"
                                            style={{ minHeight: '48px' }}
                                            disabled={loading}
                                            onClick={submitDelivery}
                                        >
                                            {loading ? (
                                                <>
                                                    <span className="spinner-border spinner-border-sm" role="status" aria-hidden="true" />
                                                    Dispatching...
                                                </>
                                            ) : (
                                                'Confirm & Dispatch 🚀'
                                            )}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* ── STEP 4: Success & Instant Confirmation Screen ──────────────────────── */}
            {step === 4 && (
                <div className="text-center py-5 delivery-success-state">
                    <div className="fs-1 text-success mb-3">🎉</div>
                    <h3 className="fw-bold mb-2">Delivery Order Confirmed!</h3>
                    <p className="text-muted mb-3">
                        Your delivery has been placed into the broadcast pool and offered to active riders in Kano.
                    </p>

                    {createdTrackingNumber && (
                        <div className="d-inline-block bg-light p-3 rounded border mb-4">
                            <span className="text-muted small d-block">Tracking Reference Number</span>
                            <strong className="fs-4 text-primary user-select-all">#{createdTrackingNumber}</strong>
                        </div>
                    )}

                    <div className="form-actions form-actions--center mt-3 d-flex justify-content-center gap-3">
                        <button
                            type="button"
                            className="btn btn-primary px-4 py-2 fw-bold"
                            style={{ minHeight: '48px' }}
                            onClick={onCreated}
                        >
                            View My Orders & Live Track
                        </button>
                        <button
                            type="button"
                            className="btn btn-outline-secondary px-4 py-2"
                            style={{ minHeight: '48px' }}
                            onClick={() => {
                                setStep(1);
                                setPriceBreakdown(null);
                                setCreatedTrackingNumber('');
                            }}
                        >
                            + Book Another Delivery
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default BookDeliveryTab;
