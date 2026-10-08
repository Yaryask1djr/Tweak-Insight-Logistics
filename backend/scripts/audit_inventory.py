#!/usr/bin/env python3
"""Read-only repository inventory; writes evidence files under backend/docs/audit.

Only project files known to git or new non-ignored files are read. Generated
evidence, patches, dependencies and local configuration are excluded. This is
an inventory/triage aid, not proof of reachability or absence of vulnerabilities.
"""
from pathlib import Path
from collections import Counter, defaultdict
import hashlib
import json
import re
import subprocess

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'backend/docs/audit'
OUT.mkdir(parents=True, exist_ok=True)
names = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode().split('\0')
names = sorted({name for name in names if name and not name.startswith('backend/docs/audit/') and not name.endswith('.patch')})
records, texts, duplicates = [], {}, defaultdict(list)
for name in names:
    path = ROOT / name
    if not path.is_file():
        continue
    raw = path.read_bytes()
    digest = hashlib.sha256(raw).hexdigest()
    category = ('generated-frontend' if name.startswith('backend/public/static/') or name in ['backend/public/index.html', 'backend/public/asset-manifest.json'] else
                'test' if '/tests/' in name or '.test.' in name else
                'documentation' if path.suffix == '.md' else
                'asset' if path.suffix in ['.png', '.webp', '.ico', '.svg'] else
                'source-or-configuration')
    try:
        content = raw.decode('utf-8')
        texts[name] = content
        lines = len(content.splitlines())
    except UnicodeDecodeError:
        lines = None
    records.append({'path': name, 'bytes': len(raw), 'lines': lines, 'sha256': digest, 'category': category})
    duplicates[digest].append(name)

imports, unresolved = defaultdict(list), []
for name, text in texts.items():
    if not name.startswith('frontend/src/') or not name.endswith('.js'):
        continue
    for target in re.findall(r"(?:from\s*|import\s*\(|import\s*)['\"]([^'\"]+)['\"]", text):
        if not target.startswith('.'):
            continue
        base = (ROOT / name).parent / target
        choices = [base, Path(str(base) + '.js'), Path(str(base) + '.css'), base / 'index.js']
        found = next((p for p in choices if p.is_file()), None)
        if found:
            imports[str(found.resolve().relative_to(ROOT))].append(name)
        else:
            unresolved.append({'path': name, 'import': target})
unreferenced = [name for name in texts if name.startswith('frontend/src/') and name.endswith('.js') and '.test.' not in name
                and name not in imports and name not in ['frontend/src/index.js', 'frontend/src/setupTests.js']]

routes = []
router = texts['backend/api/index.php']
for match in re.finditer(r'^    case (.*?):\s*\n(.*?)(?=^    case |^    default:)', router, re.M | re.S):
    condition, body = match.groups()
    routes.append({'line': router[:match.start()].count('\n') + 1, 'condition': condition.strip(),
                   'handlers': sorted(set(re.findall(r'([A-Za-z]+Controller::[A-Za-z]+)', body))),
                   'permissions': re.findall(r"verifyPermission\([^,]+,\s*'([^']+)'", body),
                   'rate_limit_buckets': re.findall(r"RateLimiter::check\('([^']+)'", body)})

schema = texts['backend/database/schema.sql']
tables = []
for name, body in re.findall(r'CREATE TABLE IF NOT EXISTS ([a-z_]+)\s*\((.*?)\) ENGINE=InnoDB', schema, re.S):
    tables.append({'table': name,
                   'references': re.findall(r'FOREIGN KEY \((.*?)\) REFERENCES ([a-z_]+)\((.*?)\) ON DELETE ([A-Z ]+)', body),
                   'indexes': [line.strip().rstrip(',') for line in body.splitlines() if re.search(r'INDEX |UNIQUE KEY |PRIMARY KEY', line)]})

patterns = {
    'raw-json-input': r'json_decode\(file_get_contents',
    'best-effort-audit-call': r'OperationalRecords::(?:audit|statusTransition)\(',
    'html-or-dynamic-execution': r'dangerouslySetInnerHTML|innerHTML\s*=|\beval\(|\bunserialize\(',
    'unbounded-fetch-review': r'fetchAll\(',
    'local-browser-storage': r'localStorage\.|sessionStorage\.',
    'placeholder-implementation': r'\b(?:TODO|FIXME|stub|placeholder)\b',
}
triage = []
for name, text in texts.items():
    if not (name.endswith('.php') or (name.startswith('frontend/src/') and name.endswith('.js'))) or '/tests/' in name or '.test.' in name:
        continue
    for label, pattern in patterns.items():
        for match in re.finditer(pattern, text, re.I):
            triage.append({'path': name, 'line': text[:match.start()].count('\n') + 1, 'review_trigger': label})

inventory = {'head': subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip(),
             'scope': 'Local working tree, not a remote HEAD verification. All included bytes hashed; static patterns are review candidates.',
             'file_count': len(records), 'categories': dict(Counter(row['category'] for row in records)), 'files': records,
             'exact_duplicate_groups': [paths for paths in duplicates.values() if len(paths) > 1],
             'frontend_unreferenced_module_candidates': unreferenced, 'unresolved_relative_imports': unresolved}
for filename, payload in [('file-inventory.json', inventory), ('api-route-inventory.json', routes), ('database-inventory.json', tables), ('static-review-triggers.json', triage)]:
    (OUT / filename).write_text(json.dumps(payload, indent=2) + '\n')
print(json.dumps({'files': len(records), 'route_cases': len(routes), 'tables': len(tables), 'unresolved_imports': unresolved, 'unreferenced_candidates': unreferenced, 'duplicate_groups': inventory['exact_duplicate_groups']}, indent=2))
