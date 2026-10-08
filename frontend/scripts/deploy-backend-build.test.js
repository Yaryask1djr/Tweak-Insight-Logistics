const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { prepare, activate, verify } = require('./deploy-backend-build');

test('immutable releases preserve old chunks, rollback, and reject incomplete activation', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'til-release-test-'));
    const publicDir = path.join(tmp, 'public');
    const build = path.join(tmp, 'build');
    const first = '20260920100000-123456abcdef';
    const second = '20260920110000-abcdef123456';
    fs.mkdirSync(build); fs.mkdirSync(path.join(build, 'static'));
    const fixture = id => {
        fs.writeFileSync(path.join(build, 'index.html'), `<script src="/releases/${id}/static/app.js"></script>`);
        fs.writeFileSync(path.join(build, 'static/app.js'), `console.log('${id}')`);
        fs.writeFileSync(path.join(build, 'asset-manifest.json'), JSON.stringify({ files: { main: `/releases/${id}/static/app.js` } }));
    };
    const pointer = () => JSON.parse(fs.readFileSync(path.join(publicDir, '.active-release.json'), 'utf8'));
    try {
        fixture(first); prepare(build, publicDir, first); activate(publicDir, first);
        assert.equal(pointer().current, first);
        fixture(second); prepare(build, publicDir, second);
        assert.equal(pointer().current, first, 'Preparation must not deploy.');
        activate(publicDir, second);
        assert.equal(pointer().previous, first);
        assert.ok(fs.existsSync(path.join(publicDir, 'releases', first, 'static/app.js')));
        activate(publicDir, first);
        assert.equal(pointer().current, first, 'Rollback must switch the pointer.');
        fs.unlinkSync(path.join(publicDir, 'releases', second, 'static/app.js'));
        assert.throws(() => activate(publicDir, second));
        assert.equal(pointer().current, first, 'Failed activation altered live pointer.');
        assert.throws(() => prepare(build, publicDir, first));
        assert.throws(() => verify(publicDir, '../unsafe'));
        fs.writeFileSync(path.join(publicDir, '.release.lock'), 'existing deployment');
        assert.throws(() => activate(publicDir, first), 'Concurrent deployment must be refused.');
    } finally { fs.rmSync(tmp, { recursive: true, force: true }); }
});
