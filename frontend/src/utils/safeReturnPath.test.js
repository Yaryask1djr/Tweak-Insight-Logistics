import { safeReturnPath } from './safeReturnPath';

test.each(['/dashboard', '/admin/deliveries', '/delivery/active', '/client/history?status=delivered', '/payment/return?reference=TIL-123'])('accepts an internal destination %s', path => {
    expect(safeReturnPath(path)).toBe(path);
});
test.each([null, '', 'https://evil.test', '//evil.test', '/\\evil.test', '/admin/../../evil', '/admin/%2e%2e/login', '/admin%2f%2fevil', '/admin\n/evil', '/login', '/unknown', '/payment/return/evil'])('rejects an unsafe or unknown destination %s', path => {
    expect(safeReturnPath(path)).toBeNull();
});
