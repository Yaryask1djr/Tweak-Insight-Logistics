import { useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { apiClient } from '../api/client';
import logoWebp from '../assets/logo.webp';
import logoPng from '../assets/logo.png';

import { showToast } from './common/Toast';
import { fieldClassName, ValidationFeedback, validators } from './common/formValidation';

const RegisterClient = () => {
    const [form, setForm] = useState({ full_name: '', email: '', phone: '', password: '', address: '' });
    const [message, setMessage] = useState('');
    const [isError, setIsError] = useState(false);
    const [loading, setLoading] = useState(false);
    const [touched, setTouched] = useState({});
    const navigate = useNavigate();

    const errors = {
        full_name: validators.fullName(form.full_name),
        email: validators.email(form.email),
        phone: validators.phone(form.phone),
        password: validators.password(form.password),
        address: validators.kanoAddress(form.address),
    };
    const handleChange = (e) => {
        setForm({ ...form, [e.target.name]: e.target.value });
        touch(e.target.name);
    };
    const touch = (field) => setTouched(current => ({ ...current, [field]: true }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        setMessage('');
        setIsError(false);
        setTouched({ full_name: true, email: true, phone: true, password: true, address: true });
        if (Object.values(errors).some(Boolean)) {
            const errMsg = 'Please correct the highlighted fields.';
            setIsError(true);
            setMessage(errMsg);
            showToast.error(errMsg);
            return;
        }
        setLoading(true);
        try {
            await apiClient.post('/auth/register-client', form);
            const succMsg = 'Registration successful! Redirecting to login...';
            setMessage(succMsg);
            showToast.success(succMsg);
            setTimeout(() => navigate('/login'), 1500);
        } catch (err) {
            const errMsg = err.response?.data?.message || 'Registration failed. Please try again.';
            setIsError(true);
            setMessage(errMsg);
            showToast.error(errMsg);
        } finally {
            setLoading(false);
        }
    };

    return (
        <main className="auth-page min-vh-100 d-flex align-items-center justify-content-center bg-light py-5">
            <div className="auth-card auth-card--wide card border-0 shadow-sm custom-card p-4">
                <div className="text-center mb-4">
                    <picture>
                        <source srcSet={logoWebp} type="image/webp" />
                        <img src={logoPng} alt="Tweak Insight Logistics" decoding="async" style={{ height: '64px', width: 'auto', objectFit: 'contain', margin: '0 auto 12px', display: 'block' }} />
                    </picture>
                    <h3 className="fw-bold mt-2">Client Registration</h3>
                    <p className="text-muted small">Create an account to request package deliveries across Kano</p>
                </div>

                {message && (
                    <div className={`alert ${isError ? 'alert-danger' : 'alert-success'} py-2 small`}>
                        {message}
                    </div>
                )}

                <form onSubmit={handleSubmit}>
                    <div className="mb-3">
                        <label className="form-label small fw-semibold text-muted">Full Name</label>
                        <input name="full_name" className={fieldClassName('form-control', form.full_name, touched.full_name, errors.full_name)} placeholder="John Doe" value={form.full_name} onChange={handleChange} onBlur={() => touch('full_name')} aria-invalid={Boolean(touched.full_name && errors.full_name)} required />
                        <ValidationFeedback touched={touched.full_name} error={errors.full_name} />
                    </div>
                    <div className="mb-3">
                        <label className="form-label small fw-semibold text-muted">Email Address</label>
                        <input name="email" type="email" className={fieldClassName('form-control', form.email, touched.email, errors.email)} placeholder="name@example.com" value={form.email} onChange={handleChange} onBlur={() => touch('email')} aria-invalid={Boolean(touched.email && errors.email)} required />
                        <ValidationFeedback touched={touched.email} error={errors.email} />
                    </div>
                    <div className="mb-3">
                        <label className="form-label small fw-semibold text-muted">Phone Number</label>
                        <input name="phone" type="tel" inputMode="tel" autoComplete="tel" className={fieldClassName('form-control', form.phone, touched.phone, errors.phone)} placeholder="+234 800 000 0000" value={form.phone} onChange={handleChange} onBlur={() => touch('phone')} aria-invalid={Boolean(touched.phone && errors.phone)} required />
                        <ValidationFeedback touched={touched.phone} error={errors.phone} />
                    </div>
                    <div className="mb-3">
                        <label className="form-label small fw-semibold text-muted">Password</label>
                        <input name="password" type="password" className={fieldClassName('form-control', form.password, touched.password, errors.password)} placeholder="••••••••" value={form.password} onChange={handleChange} onBlur={() => touch('password')} aria-invalid={Boolean(touched.password && errors.password)} required />
                        <ValidationFeedback touched={touched.password} error={errors.password} validMessage="Password length looks good." />
                    </div>
                    <div className="mb-3">
                        <label className="form-label small fw-semibold text-muted">Default Address in Kano</label>
                        <textarea name="address" className={fieldClassName('form-control', form.address, touched.address, errors.address)} rows="2" placeholder="Street, Area, Kano" value={form.address} onChange={handleChange} onBlur={() => touch('address')} aria-invalid={Boolean(touched.address && errors.address)}></textarea>
                        <div className="form-text">Service is currently available within Kano only.</div>
                        <ValidationFeedback touched={touched.address} error={errors.address} validMessage="Kano address recognised." />
                    </div>
                    <button className="btn btn-primary w-100 py-2 fw-semibold d-flex align-items-center justify-content-center" disabled={loading}>
                        {loading ? (
                            <>
                                <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                Creating Account...
                            </>
                        ) : (
                            'Register Account'
                        )}
                    </button>
                </form>

                <div className="text-center mt-4 border-top pt-3">
                    <p className="small text-muted mb-0">Already have an account? <Link to="/login" className="fw-semibold">Sign In</Link></p>
                </div>
            </div>
        </main>
    );
};

export default RegisterClient;
