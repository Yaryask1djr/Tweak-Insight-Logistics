import { useState } from 'react';
import { useNavigate, useLocation, Link } from 'react-router-dom';
import { useAuth } from './AuthContext';
import { apiClient } from '../api/client';
import logoWebp from '../assets/logo.webp';
import logoPng from '../assets/logo.png';

import { showToast } from './common/Toast';
import { fieldClassName, ValidationFeedback, validators } from './common/formValidation';

const Login = () => {
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [touched, setTouched] = useState({});
    const [loading, setLoading] = useState(false);
    const { login } = useAuth();
    const navigate = useNavigate();
    const location = useLocation();

    const searchParams = new URLSearchParams(location.search);
    const redirectPath = searchParams.get('redirect');
    const errors = {
        email: validators.email(email),
        password: validators.requiredPassword(password),
    };

    const touch = (field) => setTouched(current => ({ ...current, [field]: true }));

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');
        setTouched({ email: true, password: true });
        if (Object.values(errors).some(Boolean)) {
            showToast.error('Please correct the highlighted fields.');
            return;
        }
        setLoading(true);
        try {
            const res = await apiClient.post('/auth/login', {
                email, password
            });
            const session = res.data.data;
            login(session.user, session.access_token);
            showToast.success(`Welcome back, ${session.user.full_name}!`);

            if (redirectPath) {
                navigate(redirectPath);
            } else if (session.user.role === 'admin') {
                navigate('/admin');
            } else {
                navigate('/dashboard');
            }
        } catch (err) {
            const errMsg = err.response?.data?.message || 'Login failed. Please check your credentials.';
            setError(errMsg);
            showToast.error(errMsg);
        } finally {
            setLoading(false);
        }
    };

    return (
        <main className="auth-page min-vh-100 d-flex align-items-center justify-content-center bg-light">
            <div className="auth-card card border-0 shadow-sm custom-card p-4">
                <div className="text-center mb-4">
                    <picture>
                        <source srcSet={logoWebp} type="image/webp" />
                        <img src={logoPng} alt="Tweak Insight Logistics" decoding="async" style={{ height: '64px', width: 'auto', objectFit: 'contain', margin: '0 auto 12px', display: 'block' }} />
                    </picture>
                    <h3 className="fw-bold mt-2">Welcome Back</h3>
                    <p className="text-muted small">Sign in to your Tweak Insight Logistics account</p>
                </div>

                {error && <div className="alert alert-danger py-2 small">{error}</div>}

                <form onSubmit={handleSubmit}>
                    <div className="mb-3">
                        <label htmlFor="login-email" className="form-label small fw-semibold text-muted">Email Address</label>
                        <input 
                            id="login-email"
                            type="email" 
                            placeholder="name@example.com"
                            value={email} 
                            onChange={e => { setEmail(e.target.value); touch('email'); }}
                            onBlur={() => touch('email')}
                            className={fieldClassName('form-control py-2', email, touched.email, errors.email)}
                            aria-invalid={Boolean(touched.email && errors.email)}
                            required 
                        />
                        <ValidationFeedback touched={touched.email} error={errors.email} />
                    </div>
                    <div className="mb-3">
                        <label htmlFor="login-password" className="form-label small fw-semibold text-muted">Password</label>
                        <input 
                            id="login-password"
                            type="password" 
                            placeholder="••••••••"
                            value={password} 
                            onChange={e => { setPassword(e.target.value); touch('password'); }}
                            onBlur={() => touch('password')}
                            className={fieldClassName('form-control py-2', password, touched.password, errors.password)}
                            aria-invalid={Boolean(touched.password && errors.password)}
                            required 
                        />
                        <ValidationFeedback touched={touched.password} error={errors.password} validMessage="Password entered." />
                    </div>
                    <button className="btn btn-primary w-100 py-2 fw-semibold d-flex align-items-center justify-content-center" disabled={loading}>
                        {loading ? (
                            <>
                                <span className="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                Signing In...
                            </>
                        ) : (
                            'Sign In'
                        )}
                    </button>
                </form>

                <div className="text-center mt-4 border-top pt-3">
                    <p className="small text-muted mb-1">Don't have an account?</p>
                    <div className="d-flex justify-content-center gap-3">
                        <Link to="/register-client" className="small text-decoration-none fw-semibold">Register as Client</Link>
                        <span className="text-muted">•</span>
                        <Link to="/register-delivery" className="small text-decoration-none fw-semibold">Become a Partner</Link>
                    </div>
                </div>
            </div>
        </main>
    );
};

export default Login;
