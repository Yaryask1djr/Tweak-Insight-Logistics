import { responseItems } from './response';

test.each([
    { data: [{ id: 1 }], pagination: { current_page: 1 } },
    { data: { deliveries: [{ id: 1 }], next_cursor: 1 } },
    { data: { items: [{ id: 1 }], next_cursor: 1 } },
])('accepts a supported paginated API envelope: %j', response => {
    expect(responseItems(response)).toEqual([{ id: 1 }]);
});

test.each([undefined, { status: 'error' }, { data: {} }, { data: null }])('handles a missing list: %j', response => {
    expect(responseItems(response)).toEqual([]);
});
