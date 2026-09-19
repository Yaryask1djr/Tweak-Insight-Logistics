import { lazy, Suspense } from 'react';
import { useAuth } from './AuthContext';
import { Navigate } from 'react-router-dom';

// A client never needs the driver console (and vice versa), so keep their
// dashboard bundles separate even after the authenticated route has loaded.
const ClientDashboard = lazy(() => import('./ClientDashboard'));
const DeliveryPersonDashboard = lazy(() => import('./DeliveryPersonDashboard'));

const DashboardLoader = () => (
    <div className="dashboard-route-loader" role="status" aria-live="polite">
        <div className="skeleton-box dashboard-loader-title" />
        <div className="skeleton-box dashboard-loader-content" />
        <span className="visually-hidden">Loading dashboard…</span>
    </div>
);

const Dashboard = () => {
    const { user, isRestoring } = useAuth();

    if (isRestoring) return <DashboardLoader />;

    if (!user) {
        return <Navigate to="/login" replace />;
    }

    if (user.role === 'admin') {
        return <Navigate to="/admin" replace />;
    }

    if (user.role === 'client') {
        return <Suspense fallback={<DashboardLoader />}><ClientDashboard /></Suspense>;
    }

    if (user.role === 'delivery') {
        return <Suspense fallback={<DashboardLoader />}><DeliveryPersonDashboard /></Suspense>;
    }

    return (
        <div className="container mt-5 text-center">
            <div className="alert alert-warning">
                <h4>Unknown User Role</h4>
                <p>Your account role "{user.role}" is not recognized.</p>
            </div>
        </div>
    );
};

export default Dashboard;
