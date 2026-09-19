import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';

/**
 * ProtectedRoute
 * Enforces authentication and role-based access control at the route level.
 *
 * Props:
 *   allowedRoles {string[]} - e.g. ['admin'] or ['client','delivery']
 *                            Leave empty [] to require only authentication.
 * Usage:
 *   <ProtectedRoute allowedRoles={['admin']}>
 *       <AdminDashboard />
 *   </ProtectedRoute>
 */
const ProtectedRoute = ({ children, allowedRoles = [] }) => {
    const { user, isRestoring } = useAuth();
    const location = useLocation();

    if (isRestoring) {
        return <div className="dashboard-route-loader" role="status" aria-live="polite"><span className="visually-hidden">Restoring your session…</span></div>;
    }

    // 1. Not logged in → redirect to /login preserving the intended destination
    if (!user) {
        return (
            <Navigate
                to={`/login?redirect=${encodeURIComponent(location.pathname)}`}
                replace
            />
        );
    }

    // 2. Logged in but wrong role → redirect to correct dashboard
    if (allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
        if (user.role === 'admin') return <Navigate to="/admin" replace />;
        return <Navigate to="/dashboard" replace />;
    }

    // 3. Authenticated and authorised
    return children;
};

export default ProtectedRoute;
