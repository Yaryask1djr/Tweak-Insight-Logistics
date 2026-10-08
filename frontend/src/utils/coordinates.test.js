import { toPoint } from './coordinates';

test.each([
    null,
    { latitude: null, longitude: null },
    { latitude: '', longitude: ' ' },
    { lat: false, lng: true },
    { lat: 91, lng: 8 },
    { lat: 12, lng: 181 },
    { lat: 'not a number', lng: 8 },
])('does not plot a missing or invalid coordinate: %j', value => {
    expect(toPoint(value)).toBeNull();
});

test('accepts numeric API strings and a legitimate zero coordinate', () => {
    expect(toPoint({ latitude: '12.0022', longitude: '8.5385' })).toEqual([12.0022, 8.5385]);
    expect(toPoint({ lat: 0, lng: 0 })).toEqual([0, 0]);
});
