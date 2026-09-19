import React, { useEffect, useMemo, useRef } from 'react';
import { Circle, MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './LiveLocationMap.css';

const DEFAULT_ZOOM = 14;

const toPoint = (value) => {
    if (!value) return null;
    const latitude = Number(value.latitude ?? value.lat);
    const longitude = Number(value.longitude ?? value.lng ?? value.lon);
    return Number.isFinite(latitude) && Number.isFinite(longitude) ? [latitude, longitude] : null;
};

const pointIcon = (className, label) => L.divIcon({
    className: 'live-map-icon-wrapper',
    html: `<span class="${className}" aria-label="${label}"></span>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
});

const driverIcon = (heading) => L.divIcon({
    className: 'live-map-icon-wrapper',
    html: `<span class="live-map-driver" style="--bearing:${heading}deg" aria-label="Driver heading ${Math.round(heading)} degrees">➤</span>`,
    iconSize: [36, 36],
    iconAnchor: [18, 18],
});

/** Keeps the Leaflet instance stable while live GPS samples update. */
const FollowLiveLocation = ({ position, route }) => {
    const map = useMap();
    const initialised = useRef(false);

    useEffect(() => {
        if (!position) return;
        if (!initialised.current) {
            initialised.current = true;
            const points = route.length ? [...route, position] : [position];
            if (points.length > 1) map.fitBounds(points, { padding: [32, 32], maxZoom: DEFAULT_ZOOM });
            else map.setView(position, DEFAULT_ZOOM);
            return;
        }
        // Pan without changing zoom; the user can freely inspect the map between GPS updates.
        map.panTo(position, { animate: true, duration: 0.8 });
    }, [map, position, route]);

    return null;
};

/**
 * Interactive OpenStreetMap view. `pickup` and `destination` accept either
 * { latitude, longitude } or { lat, lng }; when both are supplied a planned
 * route line is drawn without re-creating the map during GPS refreshes.
 */
const LiveLocationMap = ({ location, pickup, destination, height = 260 }) => {
    const driverPosition = toPoint(location);
    const pickupPosition = toPoint(pickup ?? location?.pickup);
    const destinationPosition = toPoint(destination ?? location?.destination);
    const route = useMemo(
        () => pickupPosition && destinationPosition ? [pickupPosition, destinationPosition] : [],
        [pickupPosition?.[0], pickupPosition?.[1], destinationPosition?.[0], destinationPosition?.[1]]
    );
    const heading = Number(location?.heading_degrees ?? location?.heading);
    const bearing = Number.isFinite(heading) && heading >= 0 && heading <= 360 ? heading : 0;

    if (!driverPosition) {
        return <div className="live-location-map live-location-map--empty border rounded bg-light d-flex align-items-center justify-content-center text-muted" style={{ '--live-map-height': `${height}px` }}>Awaiting the first GPS location.</div>;
    }

    return (
        <div className="live-location-map border rounded overflow-hidden" style={{ '--live-map-height': `${height}px` }}>
            <MapContainer center={driverPosition} zoom={DEFAULT_ZOOM} scrollWheelZoom className="live-location-map__canvas">
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />
                <FollowLiveLocation position={driverPosition} route={route} />
                {route.length > 0 && <Polyline positions={route} pathOptions={{ color: '#c9182b', weight: 4, opacity: 0.8, dashArray: '8 8' }} />}
                {pickupPosition && <Marker position={pickupPosition} icon={pointIcon('live-map-point live-map-point--pickup', 'Pickup')}><Tooltip direction="top">Pickup</Tooltip></Marker>}
                {destinationPosition && <Marker position={destinationPosition} icon={pointIcon('live-map-point live-map-point--destination', 'Destination')}><Tooltip direction="top">Destination</Tooltip></Marker>}
                {location?.accuracy_m > 0 && <Circle center={driverPosition} radius={location.accuracy_m} pathOptions={{ color: '#2563eb', fillOpacity: 0.08, weight: 1 }} />}
                <Marker position={driverPosition} icon={driverIcon(bearing)} zIndexOffset={1000}><Tooltip direction="top">Driver{Number.isFinite(heading) ? ` · heading ${Math.round(bearing)}°` : ''}</Tooltip></Marker>
            </MapContainer>
        </div>
    );
};

export default LiveLocationMap;
