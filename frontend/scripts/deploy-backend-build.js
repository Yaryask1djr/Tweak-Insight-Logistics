/* Prepare immutable releases; activation is a separate, explicit operation. */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { spawnSync } = require('child_process');

const validId = id => /^[0-9]{14}-[a-f0-9]{12}$/.test(id);
const sha = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
function files(root, directory = root) {
    return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        // The PHP host owns its server configuration; never copy the CRA template.
        if (directory === root && entry.name === '.htaccess') return [];
        const full = path.join(directory, entry.name);
        if (entry.isSymbolicLink()) throw new Error('Release assets must not be symlinks.');
        if (entry.isDirectory()) return files(root, full);
        const name = path.relative(root, full).split(path.sep).join('/');
        if (entry.name.startsWith('.') || /\.(php\d*|phtml|phar|cgi|pl)$/i.test(entry.name)) throw new Error('Executable or hidden build file rejected.');
        return [name];
    });
}
function prepare(build, publicDir, id) {
    if (!validId(id)) throw new Error('Invalid release ID.');
    const html = fs.readFileSync(path.join(build, 'index.html'), 'utf8');
    const prefix = '/releases/' + id + '/';
    const manifest = JSON.parse(fs.readFileSync(path.join(build, 'asset-manifest.json'), 'utf8'));
    if (!html.includes(prefix) || !Object.values(manifest.files || {}).every(url => url.startsWith(prefix))) {
        throw new Error('Build PUBLIC_URL does not match this immutable release.');
    }
    for (const url of Object.values(manifest.files || {})) {
        const relative = url.slice(prefix.length);
        if (relative.includes('..') || path.isAbsolute(relative) || !fs.statSync(path.join(build, relative)).isFile()) throw new Error('Build references a missing or unsafe asset.');
    }
    const relativeFiles = files(build);
    const releases = path.join(publicDir, 'releases');
    fs.mkdirSync(releases, { recursive: true });
    const target = path.join(releases, id);
    const staging = path.join(releases, '.preparing-' + id);
    if (fs.existsSync(target) || fs.existsSync(staging)) throw new Error('Release already exists.');
    fs.mkdirSync(staging);
    try {
        for (const name of relativeFiles) {
            fs.mkdirSync(path.dirname(path.join(staging, name)), { recursive: true });
            fs.copyFileSync(path.join(build, name), path.join(staging, name), fs.constants.COPYFILE_EXCL);
        }
        const hashes = Object.fromEntries(relativeFiles.map(name => [name, sha(path.join(staging, name))]));
        fs.writeFileSync(path.join(staging, 'release.json'), JSON.stringify({ id, createdAt: new Date().toISOString(), hashes }));
        fs.renameSync(staging, target);
    } catch (error) { fs.rmSync(staging, { recursive: true, force: true }); throw error; }
    return target;
}
function verify(publicDir, id) {
    if (!validId(id)) throw new Error('Invalid release ID.');
    const root = path.join(publicDir, 'releases', id);
    const meta = JSON.parse(fs.readFileSync(path.join(root, 'release.json'), 'utf8'));
    if (meta.id !== id || !meta.hashes?.['index.html']) throw new Error('Release metadata is incomplete.');
    for (const [name, hash] of Object.entries(meta.hashes)) {
        if (name.includes('..') || path.isAbsolute(name) || path.resolve(root, name).startsWith(root + path.sep) === false) throw new Error('Unsafe manifest path.');
        if (fs.lstatSync(path.join(root, name)).isSymbolicLink() || sha(path.join(root, name)) !== hash) throw new Error('Release integrity check failed: ' + name);
    }
    return meta;
}
function locked(publicDir, action) {
    fs.mkdirSync(publicDir, { recursive: true });
    const lock = path.join(publicDir, '.release.lock');
    const fd = fs.openSync(lock, 'wx');
    try { fs.writeFileSync(fd, String(process.pid)); return action(); }
    finally { fs.closeSync(fd); fs.unlinkSync(lock); }
}
function activate(publicDir, id) {
    return locked(publicDir, () => {
        verify(publicDir, id);
        const pointer = path.join(publicDir, '.active-release.json');
        const previous = fs.existsSync(pointer) ? JSON.parse(fs.readFileSync(pointer, 'utf8')).current : null;
        const temporary = pointer + '.' + crypto.randomBytes(6).toString('hex') + '.tmp';
        const fd = fs.openSync(temporary, 'wx', 0o644);
        try {
            fs.writeFileSync(fd, JSON.stringify({ current: id, previous, activatedAt: new Date().toISOString() }));
            fs.fsyncSync(fd);
        } finally { fs.closeSync(fd); }
        try { fs.renameSync(temporary, pointer); }
        catch (error) { fs.unlinkSync(temporary); throw error; } // Never delete a live pointer as a fallback.
    });
}
function prune(publicDir, days = 30) {
    if (!Number.isInteger(days) || days < 7) throw new Error('Retention must be at least seven days.');
    return locked(publicDir, () => {
        const active = JSON.parse(fs.readFileSync(path.join(publicDir, '.active-release.json'), 'utf8'));
        for (const id of fs.readdirSync(path.join(publicDir, 'releases'))) {
            if (!validId(id) || [active.current, active.previous].includes(id)) continue;
            const meta = verify(publicDir, id);
            if (Date.parse(meta.createdAt) < Date.now() - days * 86400000) fs.rmSync(path.join(publicDir, 'releases', id), { recursive: true });
        }
    });
}
if (require.main === module) {
    const frontend = path.resolve(__dirname, '..');
    const publicDir = path.resolve(frontend, '..', 'backend', 'public');
    const arg = process.argv[2];
    if (arg === '--prepare') {
        locked(publicDir, () => {
            const id = new Date().toISOString().replace(/[^0-9]/g, '').slice(0, 14) + '-' + crypto.randomBytes(6).toString('hex');
            const build = spawnSync(process.platform === 'win32' ? 'npm.cmd' : 'npm', ['run', 'build'], {
                cwd: frontend, stdio: 'inherit', env: { ...process.env, PUBLIC_URL: '/releases/' + id }, shell: process.platform === 'win32',
            });
            if (build.status !== 0) throw new Error('Build failed; active release is unchanged.');
            prepare(path.join(frontend, 'build'), publicDir, id);
            console.log('Prepared ' + id + '. After review, activate with: node scripts/deploy-backend-build.js --activate=' + id);
        });
    } else if (arg?.startsWith('--activate=') || arg?.startsWith('--rollback=')) {
        activate(publicDir, arg.split('=')[1]);
        console.log('Release pointer switched atomically.');
    } else if (arg === '--prune') {
        prune(publicDir, Number(process.env.TIL_RELEASE_RETENTION_DAYS || 30));
    } else {
        throw new Error('Use --prepare, --activate=ID, --rollback=ID or --prune.');
    }
}
module.exports = { prepare, verify, activate, prune };
