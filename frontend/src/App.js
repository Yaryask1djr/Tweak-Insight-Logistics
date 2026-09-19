import React, { Suspense, lazy } from 'react';
import { BrowserRouter, Routes, Route, Link, useNavigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './components/AuthContext';
import ProtectedRoute from './components/ProtectedRoute';

// Modular Landing Page Components (Eagerly loaded for optimal First Contentful Paint)
import Navbar from './components/landing/Navbar';
import HeroSection from './components/landing/HeroSection';
import TrustStrip from './components/landing/TrustStrip';
import ServicesSection from './components/landing/ServicesSection';
import HowItWorksSection from './components/landing/HowItWorksSection';
import CoverageSection from './components/landing/CoverageSection';
import ReadyToShipCTA from './components/landing/ReadyToShipCTA';
import Footer from './components/landing/Footer';

// Code-split route components with React.lazy() to reduce initial bundle size
const Login = React.lazy(() => import('./components/Login'));
const RegisterClient = React.lazy(() => import('./components/RegisterClient'));
const RegisterDelivery = React.lazy(() => import('./components/RegisterDelivery'));
const AdminDashboard = React.lazy(() => import('./components/AdminDashboard'));
const DeliveryPersonDashboard = React.lazy(() => import('./components/DeliveryPersonDashboard'));
const ClientDashboard = React.lazy(() => import('./components/ClientDashboard'));
const Dashboard = React.lazy(() => import('./components/Dashboard'));
const TermsAndConditions = React.lazy(() => import('./components/TermsAndConditions'));
const CoveragePage = React.lazy(() => import('./components/CoveragePage'));
const AboutPage = React.lazy(() => import('./components/AboutPage'));
const CareersPage = React.lazy(() => import('./components/CareersPage'));
const ContactPage = React.lazy(() => import('./components/ContactPage'));
const TrackPage = React.lazy(() => import('./components/TrackPage'));

import ToastNotification from './components/common/Toast';
import ErrorBoundary from './components/common/ErrorBoundary';
import './App.css';

// Sleek branded loading fallback for route transitions
const PageLoader = () => (
    <div className="min-vh-100 d-flex flex-column align-items-center justify-content-center bg-light" style={{ minHeight: '60vh' }}>
        <div className="spinner-border text-danger mb-3" style={{ width: '2.5rem', height: '2.5rem' }} role="status">
            <span className="visually-hidden">Loading...</span>
        </div>
        <p className="text-muted fw-semibold small">Loading Tweak Insight Logistics...</p>
    </div>
);

const NotFound = () => (
    <main id="main-content" className="min-vh-100 d-flex flex-column align-items-center justify-content-center bg-light px-4 text-center">
        <p className="section-eyebrow mb-2"><span>Page Not Found</span></p>
        <h1 className="display-6 fw-bold text-dark mb-3">That page does not exist.</h1>
        <p className="text-muted mb-4">The address may be outdated or typed incorrectly.</p>
        <Link to="/" className="btn-brand-primary">Return Home</Link>
    </main>
);

const Home = () => {
    const { user } = useAuth();
    const navigate = useNavigate();

    const handleRequestDelivery = () => {
        if (!user) {
            navigate('/login?redirect=/dashboard');
        } else if (user.role === 'admin') {
            navigate('/admin');
        } else {
            navigate('/dashboard');
        }
    };

    return (
        <div className="min-vh-100 d-flex flex-column bg-light">
            <Navbar onRequestDelivery={handleRequestDelivery} />
            <main id="main-content" className="flex-grow-1">
                <HeroSection onRequestDelivery={handleRequestDelivery} />
                <TrustStrip />
                <ServicesSection onRequestDelivery={handleRequestDelivery} />
                <HowItWorksSection />
                <CoverageSection />
                <ReadyToShipCTA onRequestDelivery={handleRequestDelivery} />
            </main>
            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

function App() {
    return (
        <AuthProvider>
            <ToastNotification />
            <BrowserRouter>
                <ErrorBoundary
                    fallbackTitle="Page Display Error"
                    fallbackMessage="An unexpected error occurred while loading this page. Click below to reload or return to home."
                    secondaryAction={{ label: 'Return Home', onClick: () => window.location.href = '/' }}
                >
                    <Suspense fallback={<PageLoader />}>
                        <Routes>
                            {/* Public Routes */}
                            <Route path="/" element={<Home />} />
                            <Route path="/about" element={<AboutPage />} />
                            <Route path="/careers" element={<CareersPage />} />
                            <Route path="/contact" element={<ContactPage />} />
                            <Route path="/track" element={<TrackPage />} />
                            <Route path="/coverage" element={<CoveragePage />} />
                            <Route path="/terms" element={<TermsAndConditions />} />
                            <Route path="/login" element={<Login />} />
                            <Route path="/register-client" element={<RegisterClient />} />
                            <Route path="/register-delivery" element={<RegisterDelivery />} />

                            {/* Protected: Client & Delivery Partner (role: client | delivery) */}
                            <Route
                                path="/dashboard/*"
                                element={
                                    <ProtectedRoute allowedRoles={['client', 'delivery', 'admin']}>
                                        <Dashboard />
                                    </ProtectedRoute>
                                }
                            />

                            {/* Protected: Direct Role-Specific Routes */}
                            <Route
                                path="/client/*"
                                element={
                                    <ProtectedRoute allowedRoles={['client']}>
                                        <ClientDashboard />
                                    </ProtectedRoute>
                                }
                            />
                            <Route
                                path="/driver/*"
                                element={
                                    <ProtectedRoute allowedRoles={['delivery']}>
                                        <DeliveryPersonDashboard />
                                    </ProtectedRoute>
                                }
                            />
                            <Route
                                path="/delivery/*"
                                element={
                                    <ProtectedRoute allowedRoles={['delivery']}>
                                        <DeliveryPersonDashboard />
                                    </ProtectedRoute>
                                }
                            />

                            {/* Protected: Admin Dashboard — role: admin ONLY */}
                            <Route
                                path="/admin/*"
                                element={
                                    <ProtectedRoute allowedRoles={['admin']}>
                                        <AdminDashboard />
                                    </ProtectedRoute>
                                }
                            />
                            <Route path="*" element={<NotFound />} />
                        </Routes>
                    </Suspense>
                </ErrorBoundary>
            </BrowserRouter>
        </AuthProvider>
    );
}

export default App;
