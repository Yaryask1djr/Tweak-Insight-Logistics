export const toPoint = value => {
    if (!value) return null;
    const latitude = value.latitude ?? value.lat;
    const longitude = value.longitude ?? value.lng ?? value.lon;
    if ([latitude, longitude].some(coordinate =>
        coordinate == null || typeof coordinate === 'boolean'
        || (typeof coordinate === 'string' && coordinate.trim() === '')
    )) return null;
    const lat = Number(latitude);
    const lng = Number(longitude);
    return Number.isFinite(lat) && Number.isFinite(lng)
        && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? [lat, lng] : null;
};
