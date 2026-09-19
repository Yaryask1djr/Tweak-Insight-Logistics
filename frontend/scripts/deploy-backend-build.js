/*
 * Copies the compiled React SPA into the PHP public directory.
 * Source stays in frontend/src; backend/public only receives build artefacts.
 */
const fs = require('fs');
const path = require('path');

const buildDirectory = path.resolve(__dirname, '..', 'build');
const publicDirectory = path.resolve(__dirname, '..', '..', 'backend', 'public');

if (!fs.existsSync(buildDirectory)) {
    throw new Error('React build output was not found. Run the build step first.');
}

// Fingerprinted chunks must be deployed as one replacement set. Keeping old
// chunks leaves retired application code reachable at predictable URLs and
// makes security review of the public document root ambiguous. Only this
// generated directory is removed; PHP entry points and .htaccess stay intact.
const staticTarget = path.join(publicDirectory, 'static');
if (fs.existsSync(staticTarget)) {
    fs.rmSync(staticTarget, { recursive: true, force: true });
}

for (const entry of fs.readdirSync(buildDirectory)) {
    // backend/public/.htaccess is server configuration, not a React asset. It
    // owns API routing and the production CSP and must never be replaced by
    // the frontend template copied into build/ by react-scripts.
    if (entry === '.htaccess') continue;

    fs.cpSync(
        path.join(buildDirectory, entry),
        path.join(publicDirectory, entry),
        { recursive: true, force: true }
    );
}

console.log(`React build deployed to ${publicDirectory}`);
