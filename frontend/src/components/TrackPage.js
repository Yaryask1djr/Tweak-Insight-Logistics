import React from 'react';
import { useNavigate } from 'react-router-dom';
import Navbar from './landing/Navbar';
import Footer from './landing/Footer';
import TrackingWidget from './landing/TrackingWidget';
import { useAuth } from './AuthContext';

const TrackPage = () => {
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

            <main id="main-content" className="flex-grow-1 py-4">
                <TrackingWidget />
            </main>

            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

export default TrackPage;
