import React, { useState, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { MapContainer, TileLayer, Polygon, Marker, Popup, Tooltip, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import Navbar from './landing/Navbar';
import Footer from './landing/Footer';
import { useAuth } from './AuthContext';

// Active Kano Coverage Zones (Fagge, Dala, Gwale, Tarauni, Nasarawa, Kumbotso)
const COVERAGE_ZONES = [
    {
        id: 'fagge',
        name: 'Fagge',
        lga: 'Fagge LGA',
        subtitle: 'Commercial Wholesale & Textile Corridor',
        color: '#0284c7', // Sky Blue
        center: [12.0150, 8.5320],
        eta: '15–25 min',
        fleet: '40+ Motorbikes & Tricycles',
        description: 'West Africa’s primary textile and commerce capital, including Kantin Kwari, Sabon Gari, and France Road commercial districts.',
        landmarks: ['Kantin Kwari Market', 'Sabon Gari Market', 'France Road', 'IBB Way', 'Fagge D2'],
        polygon: [
            [12.0240, 8.5140],
            [12.0290, 8.5330],
            [12.0230, 8.5520],
            [12.0080, 8.5490],
            [12.0010, 8.5300],
            [12.0070, 8.5140],
        ],
    },
    {
        id: 'dala',
        name: 'Dala',
        lga: 'Dala LGA',
        subtitle: 'Historic High-Density Residential & Markets',
        color: '#d97706', // Amber
        center: [12.0220, 8.4980],
        eta: '20–35 min',
        fleet: '24 Motorbikes',
        description: 'Historic trade quarters and high-density neighborhood retail centers spanning Dala Hill, Gwammaja, Koki, and Yalwa.',
        landmarks: ['Dala Hill', 'Gwammaja Commercials', 'Koki Wholesale Market', 'Yalwa', 'Dandinshe'],
        polygon: [
            [12.0360, 8.4870],
            [12.0400, 8.5140],
            [12.0200, 8.5180],
            [12.0060, 8.5020],
            [12.0110, 8.4790],
            [12.0260, 8.4750],
        ],
    },
    {
        id: 'gwale',
        name: 'Gwale',
        lga: 'Gwale LGA',
        subtitle: 'Academic Corridor & Dense Urban Settlements',
        color: '#7c3aed', // Purple
        center: [11.9930, 8.4920],
        eta: '20–35 min',
        fleet: '28 Motorbikes & Tricycles',
        description: 'Rapid fulfillment across academic campuses, printing hubs, and residential zones including BUK Old Campus and Goron Dutse.',
        landmarks: ['Goron Dutse Hill', 'BUK Old Campus', 'Dorayi Babba', 'Mandawari', 'Kabuga Gate'],
        polygon: [
            [12.0100, 8.4800],
            [12.0120, 8.5090],
            [11.9830, 8.5150],
            [11.9710, 8.4910],
            [11.9830, 8.4680],
            [12.0030, 8.4710],
        ],
    },
    {
        id: 'tarauni',
        name: 'Tarauni',
        lga: 'Tarauni LGA',
        subtitle: 'Commercial Tech & Telecoms Axis',
        color: '#059669', // Emerald
        center: [11.9670, 8.5450],
        eta: '20–30 min',
        fleet: '35 Motorbikes & Tricycles',
        description: 'Central tech hub, gadget accessories, retail shopping complexes, and residential estates stretching along Zoo Road and Farm Centre.',
        landmarks: ['Farm Centre Telecoms Market', 'Zoo Road Shopping Belt', 'Gyadi-Gyadi', 'Court Road', 'Hotoro GRA North'],
        polygon: [
            [11.9830, 8.5280],
            [11.9890, 8.5630],
            [11.9540, 8.5730],
            [11.9430, 8.5420],
            [11.9570, 8.5200],
            [11.9750, 8.5210],
        ],
    },
    {
        id: 'nasarawa',
        name: 'Nasarawa',
        lga: 'Nasarawa LGA',
        subtitle: 'Government, Corporate & Manufacturing Zone',
        color: '#dc2626', // TIL Brand Red
        center: [12.0120, 8.5750],
        eta: '15–30 min',
        fleet: '42 Motorbikes, Cargo Tricycles & Vans',
        description: 'Corporate headquarters, state secretariats, executive residences in Nasarawa GRA, and heavy freight distribution in Bompai Industrial Area.',
        landmarks: ['Nasarawa GRA', 'Bompai Industrial Area', 'State Road Secretariats', 'Giginyu', 'Kaura Goje'],
        polygon: [
            [12.0310, 8.5450],
            [12.0420, 8.5870],
            [12.0020, 8.6090],
            [11.9810, 8.5680],
            [12.0020, 8.5430],
            [12.0190, 8.5430],
        ],
    },
    {
        id: 'kumbotso',
        name: 'Kumbotso',
        lga: 'Kumbotso LGA',
        subtitle: 'Heavy Industrial & Educational Logistics Belt',
        color: '#0891b2', // Cyan
        center: [11.9260, 8.4900],
        eta: '25–45 min',
        fleet: '30 Vans, Trucks & Heavy Tricycles',
        description: 'Industrial manufacturing parks, bulk commodities, BUK New Campus logistics gateway, and Challawa industrial freight distribution.',
        landmarks: ['Challawa Industrial Estate', 'BUK New Campus', 'Panshekara Road', 'Mariri Freight Corridor', 'Yan Lemo Market'],
        polygon: [
            [11.9570, 8.4540],
            [11.9630, 8.5250],
            [11.9040, 8.5390],
            [11.8850, 8.4810],
            [11.9110, 8.4470],
            [11.9390, 8.4440],
        ],
    },
];

// Operating Hub Locations with exact coordinates and operational metadata
const OPERATING_HUBS = [
    {
        id: 'hub-kantin-kwari',
        name: 'Kantin Kwari High-Density Hub',
        zoneId: 'fagge',
        zoneName: 'Fagge',
        address: 'Baban Layi, Kantin Kwari Market, Fagge, Kano',
        type: 'market',
        symbol: '🏪',
        coordinates: [12.0115, 8.5335],
        eta: '15–25 min',
        fleetCapacity: '18 Motorcycles, 6 Cargo Tricycles',
        specialty: 'High-speed textile packages, garments, lightweight retail parcels',
    },
    {
        id: 'hub-sabon-gari',
        name: 'Sabon Gari Central Dispatch Station',
        zoneId: 'fagge',
        zoneName: 'Fagge',
        address: 'France Road, Sabon Gari, Kano',
        type: 'market',
        symbol: '📦',
        coordinates: [12.0195, 8.5420],
        eta: '15–30 min',
        fleetCapacity: '22 Motorcycles, 8 Tricycles, 2 Vans',
        specialty: 'Electronics, consumer goods, documents, and fragile merchandise',
    },
    {
        id: 'hub-nasarawa-gra',
        name: 'Nasarawa Executive Courier Post',
        zoneId: 'nasarawa',
        zoneName: 'Nasarawa',
        address: 'State Road by Government House Axis, Nasarawa GRA, Kano',
        type: 'corporate',
        symbol: '🏢',
        coordinates: [12.0020, 8.5580],
        eta: '20–35 min',
        fleetCapacity: '14 Express Motorcycles, 4 Executive Vans',
        specialty: 'Corporate contracts, confidential legal documents, priority courier',
    },
    {
        id: 'hub-bompai',
        name: 'Bompai Heavy Freight Terminal',
        zoneId: 'nasarawa',
        zoneName: 'Nasarawa',
        address: 'Bompai Industrial Area, Club Road, Kano',
        type: 'industrial',
        symbol: '🏭',
        coordinates: [12.0280, 8.5720],
        eta: '25–45 min',
        fleetCapacity: '8 Heavy Cargo Vans, 4 Light Trucks, 10 Tricycles',
        specialty: 'Factory freight, bulk cartons, raw materials & palletized stock',
    },
    {
        id: 'hub-farm-centre',
        name: 'Farm Centre Tech Commerce Hub',
        zoneId: 'tarauni',
        zoneName: 'Tarauni',
        address: 'Zoo Road / Farm Centre Junction, Tarauni, Kano',
        type: 'market',
        symbol: '📱',
        coordinates: [11.9720, 8.5410],
        eta: '20–30 min',
        fleetCapacity: '16 Motorcycles, 5 Tricycles',
        specialty: 'Smartphones, computing accessories, consumer goods & retail parcels',
    },
    {
        id: 'hub-dala-gwale',
        name: 'Dala & Goron Dutse Dispatch Post',
        zoneId: 'dala',
        zoneName: 'Dala',
        address: 'Aminu Kano Way, near Goron Dutse, Dala, Kano',
        type: 'neighborhood',
        symbol: '📍',
        coordinates: [12.0080, 8.4980],
        eta: '20–35 min',
        fleetCapacity: '12 Agile Motorcycles',
        specialty: 'Dense urban lanes, residential drop-offs, neighborhood retail',
    },
    {
        id: 'hub-challawa',
        name: 'Challawa Industrial Distribution Park',
        zoneId: 'kumbotso',
        zoneName: 'Kumbotso',
        address: 'Challawa Industrial Estate, Kumbotso, Kano',
        type: 'industrial',
        symbol: '🚚',
        coordinates: [11.9320, 8.4920],
        eta: '30–55 min',
        fleetCapacity: '6 Freight Vans, 10 Cargo Tricycles',
        specialty: 'Heavy industrial machinery parts, bulk consumer packaged goods',
    },
    {
        id: 'hub-buk-gateway',
        name: 'BUK New Campus Logistics Gateway',
        zoneId: 'kumbotso',
        zoneName: 'Kumbotso',
        address: 'Gwarzo Road by BUK New Campus Gate, Kano',
        type: 'campus',
        symbol: '🎓',
        coordinates: [11.9510, 8.4580],
        eta: '25–40 min',
        fleetCapacity: '10 Motorcycles, 4 Tricycles',
        specialty: 'Academic transcripts, book supplies, student parcels & express items',
    },
];

const KANO_DEFAULT_CENTER = [11.9950, 8.5250];
const KANO_DEFAULT_ZOOM = 12;

/**
 * Controller to programmatically adjust Leaflet viewport on zone or hub selection
 */
const MapViewController = ({ targetCenter, targetZoom, targetBounds }) => {
    const map = useMap();

    useEffect(() => {
        if (targetBounds) {
            map.fitBounds(targetBounds, { padding: [40, 40], maxZoom: 14, animate: true, duration: 0.8 });
        } else if (targetCenter) {
            map.flyTo(targetCenter, targetZoom || 13, { duration: 0.8 });
        }
    }, [map, targetCenter, targetZoom, targetBounds]);

    return null;
};

const createHubIcon = (symbol, isSelected) => L.divIcon({
    className: 'hub-map-marker-wrapper',
    html: `
        <div class="hub-marker-pin ${isSelected ? 'active' : ''}">
            <span class="hub-marker-symbol">${symbol}</span>
        </div>
    `,
    iconSize: [36, 36],
    iconAnchor: [18, 36],
    popupAnchor: [0, -32],
});

const CoveragePage = () => {
    const { user } = useAuth();
    const navigate = useNavigate();

    const [selectedZoneId, setSelectedZoneId] = useState('all');
    const [selectedHubId, setSelectedHubId] = useState(null);
    const [mapView, setMapView] = useState({
        center: KANO_DEFAULT_CENTER,
        zoom: KANO_DEFAULT_ZOOM,
        bounds: null,
    });

    const handleRequestDelivery = () => {
        if (!user) {
            navigate('/login?redirect=/dashboard');
        } else if (user.role === 'admin') {
            navigate('/admin');
        } else {
            navigate('/dashboard');
        }
    };

    const activeZone = COVERAGE_ZONES.find(z => z.id === selectedZoneId);

    const filteredHubs = selectedZoneId === 'all'
        ? OPERATING_HUBS
        : OPERATING_HUBS.filter(h => h.zoneId === selectedZoneId);

    const handleSelectZone = (zoneId) => {
        setSelectedZoneId(zoneId);
        setSelectedHubId(null);

        if (zoneId === 'all') {
            setMapView({ center: KANO_DEFAULT_CENTER, zoom: KANO_DEFAULT_ZOOM, bounds: null });
        } else {
            const zone = COVERAGE_ZONES.find(z => z.id === zoneId);
            if (zone) {
                setMapView({
                    center: zone.center,
                    zoom: 13,
                    bounds: zone.polygon,
                });
            }
        }
    };

    const handleFocusHub = (hub) => {
        setSelectedHubId(hub.id);
        setSelectedZoneId(hub.zoneId);
        setMapView({
            center: hub.coordinates,
            zoom: 14,
            bounds: null,
        });
    };

    return (
        <div className="min-vh-100 d-flex flex-column bg-light">
            <Navbar onRequestDelivery={handleRequestDelivery} />

            <main id="main-content" className="flex-grow-1 py-5">
                <div className="page-container" style={{ maxWidth: '1200px' }}>
                    {/* Header */}
                    <div className="text-center mb-4" style={{ maxWidth: '820px', margin: '0 auto' }}>
                        <span className="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-2 rounded-pill fw-bold mb-3">
                            Kano Metropolitan Operations Network
                        </span>
                        <h1 className="fw-bold display-6 text-dark mt-2 mb-3">
                            Interactive Hub & Coverage Zone Map
                        </h1>
                        <p className="text-muted fs-6">
                            Explore active delivery coverage polygons across <strong>Fagge, Dala, Gwale, Tarauni, Nasarawa, and Kumbotso</strong> alongside our strategic operating dispatch hubs.
                        </p>
                    </div>

                    {/* Zone Filter Chips */}
                    <div className="d-flex flex-wrap justify-content-center gap-2 mb-4">
                        <button
                            type="button"
                            className={`coverage-zone-chip ${selectedZoneId === 'all' ? 'active' : ''}`}
                            onClick={() => handleSelectZone('all')}
                        >
                            <span>🗺️ All Zones ({COVERAGE_ZONES.length})</span>
                        </button>
                        {COVERAGE_ZONES.map(zone => {
                            const isSelected = selectedZoneId === zone.id;
                            return (
                                <button
                                    key={zone.id}
                                    type="button"
                                    className={`coverage-zone-chip ${isSelected ? 'active' : ''}`}
                                    onClick={() => handleSelectZone(zone.id)}
                                >
                                    <span className="zone-dot" style={{ backgroundColor: zone.color }} />
                                    <span>{zone.name}</span>
                                </button>
                            );
                        })}
                    </div>

                    {/* Interactive Leaflet Map Card */}
                    <div className="coverage-map-wrapper mb-4">
                        <div className="p-3 bg-white border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div className="d-flex align-items-center gap-2">
                                <span className="badge bg-success-subtle text-success border border-success px-2 py-1">
                                    ● 6 Active Operational Polygons
                                </span>
                                <span className="text-muted small">
                                    {selectedZoneId === 'all' ? 'Showing all Kano coverage zones and 8 operating hubs' : `Focusing on ${activeZone?.name} LGA Zone`}
                                </span>
                            </div>
                            <div className="d-flex align-items-center gap-2 small text-muted">
                                <span>Tip: Click any zone polygon or hub marker for details</span>
                            </div>
                        </div>

                        <MapContainer
                            center={KANO_DEFAULT_CENTER}
                            zoom={KANO_DEFAULT_ZOOM}
                            scrollWheelZoom={false}
                            className="coverage-map-canvas"
                        >
                            <TileLayer
                                attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                                url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                            />

                            <MapViewController
                                targetCenter={mapView.center}
                                targetZoom={mapView.zoom}
                                targetBounds={mapView.bounds}
                            />

                            {/* Zone Polygons */}
                            {COVERAGE_ZONES.map(zone => {
                                const isSelected = selectedZoneId === zone.id;
                                const isDimmed = selectedZoneId !== 'all' && !isSelected;

                                return (
                                    <Polygon
                                        key={`polygon-${zone.id}`}
                                        positions={zone.polygon}
                                        pathOptions={{
                                            color: zone.color,
                                            weight: isSelected ? 3 : 2,
                                            fillColor: zone.color,
                                            fillOpacity: isSelected ? 0.35 : (isDimmed ? 0.08 : 0.22),
                                        }}
                                        eventHandlers={{
                                            click: () => handleSelectZone(zone.id),
                                        }}
                                    >
                                        <Tooltip sticky direction="center" className="fw-bold">
                                            <div>
                                                <strong>{zone.name} LGA</strong>
                                                <div className="small text-muted">{zone.subtitle}</div>
                                                <div className="small text-success">⏱ Avg ETA: {zone.eta}</div>
                                            </div>
                                        </Tooltip>
                                    </Polygon>
                                );
                            })}

                            {/* Operating Hub Markers */}
                            {OPERATING_HUBS.map(hub => {
                                const isSelected = selectedHubId === hub.id;
                                const icon = createHubIcon(hub.symbol, isSelected);

                                return (
                                    <Marker
                                        key={hub.id}
                                        position={hub.coordinates}
                                        icon={icon}
                                        eventHandlers={{
                                            click: () => handleFocusHub(hub),
                                        }}
                                    >
                                        <Popup>
                                            <div className="p-1" style={{ maxWidth: '240px' }}>
                                                <div className="d-flex align-items-center gap-2 mb-1">
                                                    <span className="fs-5">{hub.symbol}</span>
                                                    <strong className="fs-6 text-dark">{hub.name}</strong>
                                                </div>
                                                <span className="badge bg-danger mb-2">⏱ {hub.eta}</span>
                                                <div className="small text-muted mb-1">
                                                    📍 {hub.address}
                                                </div>
                                                <div className="small text-dark mb-1">
                                                    <strong>Fleet:</strong> {hub.fleetCapacity}
                                                </div>
                                                <div className="small text-muted mb-3 fst-italic">
                                                    {hub.specialty}
                                                </div>
                                                <button
                                                    type="button"
                                                    className="btn btn-primary btn-sm w-100 fw-bold"
                                                    onClick={handleRequestDelivery}
                                                >
                                                    Book Dispatch to Hub →
                                                </button>
                                            </div>
                                        </Popup>
                                    </Marker>
                                );
                            })}
                        </MapContainer>
                    </div>

                    {/* Zone Information Banner (if single zone selected) */}
                    {activeZone && (
                        <div className="card border-0 shadow-sm custom-card p-4 mb-4" style={{ borderLeft: `6px solid ${activeZone.color}` }}>
                            <div className="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <h4 className="fw-bold text-dark mb-1">
                                        📍 {activeZone.name} Coverage Zone ({activeZone.lga})
                                    </h4>
                                    <span className="text-muted small">{activeZone.subtitle}</span>
                                </div>
                                <div className="d-flex gap-2">
                                    <span className="badge bg-primary px-3 py-2">
                                        ⏱ {activeZone.eta} Turnaround
                                    </span>
                                    <span className="badge bg-success px-3 py-2">
                                        🚀 {activeZone.fleet}
                                    </span>
                                </div>
                            </div>
                            <p className="text-muted mb-3">{activeZone.description}</p>
                            <div>
                                <strong className="small text-dark d-block mb-1">Key Landmarks & Commerce Points:</strong>
                                <div className="d-flex flex-wrap gap-1">
                                    {activeZone.landmarks.map((lm, i) => (
                                        <span key={i} className="badge bg-light text-dark border me-1">
                                            ✓ {lm}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Operating Hubs Grid */}
                    <div className="mb-5">
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <h5 className="fw-bold mb-0">
                                🏢 Operating Hubs ({filteredHubs.length})
                            </h5>
                            {selectedZoneId !== 'all' && (
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    onClick={() => handleSelectZone('all')}
                                >
                                    Reset to All Hubs
                                </button>
                            )}
                        </div>

                        <div className="row g-3">
                            {filteredHubs.map(hub => {
                                const isSelected = selectedHubId === hub.id;
                                return (
                                    <div key={hub.id} className="col-md-6 col-lg-3">
                                        <div
                                            className={`coverage-hub-card h-100 d-flex flex-column justify-content-between ${isSelected ? 'active' : ''}`}
                                            onClick={() => handleFocusHub(hub)}
                                            role="button"
                                            tabIndex={0}
                                        >
                                            <div>
                                                <div className="d-flex justify-content-between align-items-start mb-2">
                                                    <span className="fs-4">{hub.symbol}</span>
                                                    <span className="badge bg-danger text-white small">
                                                        ⏱ {hub.eta}
                                                    </span>
                                                </div>
                                                <h6 className="fw-bold text-dark mb-1">{hub.name}</h6>
                                                <span className="badge bg-light text-secondary border mb-2">
                                                    Zone: {hub.zoneName}
                                                </span>
                                                <p className="text-muted small mb-2 lh-sm">
                                                    📍 {hub.address}
                                                </p>
                                                <div className="text-dark small fw-semibold mb-2">
                                                    🚀 {hub.fleetCapacity}
                                                </div>
                                                <small className="text-muted d-block fst-italic">
                                                    {hub.specialty}
                                                </small>
                                            </div>
                                            <div className="mt-3 pt-2 border-top d-flex justify-content-between align-items-center">
                                                <span className="text-primary small fw-bold">
                                                    Focus on Map 📍
                                                </span>
                                                <span className="text-muted small">Select →</span>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>

                    {/* CTA Box */}
                    <div className="card border-0 shadow-sm custom-card text-center p-5 bg-white">
                        <h4 className="fw-bold text-dark mb-2">Ready to Book a Delivery Across Any of These Zones?</h4>
                        <p className="text-muted mb-4">Our central operations dispatch team coordinates verified drivers across Fagge, Dala, Gwale, Tarauni, Nasarawa, and Kumbotso.</p>
                        <div className="d-flex justify-content-center gap-3 flex-wrap">
                            <button onClick={handleRequestDelivery} className="btn btn-primary px-4 py-2 fw-semibold">
                                Request Delivery Now →
                            </button>
                            <Link to="/" className="btn btn-outline-secondary px-4 py-2">
                                ← Back to Home
                            </Link>
                        </div>
                    </div>
                </div>
            </main>

            <Footer onRequestDelivery={handleRequestDelivery} />
        </div>
    );
};

export default CoveragePage;