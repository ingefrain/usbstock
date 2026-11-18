"""Check the configured corporate key without printing it or passing it to a process."""
import json
import re
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[2]
matches = re.findall(r'^CORPORATE_CONTACTS_KEY=(.*)$', (root / '.env').read_text(encoding='utf-8'), re.M)
if len(matches) != 1:
    raise SystemExit('Expected one local corporate key definition.')
key = matches[0].strip().strip('"').strip("'").encode()
if len(key) < 8:
    raise SystemExit('Corporate configuration is incomplete.')
git = ['git', '-c', 'safe.directory=' + root.as_posix()]
files = subprocess.run(git + ['ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=root, capture_output=True, check=True).stdout.split(b'\0')
tracked = subprocess.run(git + ['ls-files', '--error-unmatch', '.env'], cwd=root, capture_output=True).returncode == 0
if tracked:
    raise SystemExit('Local environment must not be tracked.')
paths = {root / f.decode('utf-8') for f in files if f}
paths.update((root.parent / 'xtras/docs').glob('*.md'))
for directory in ['public/build', 'storage/logs', 'storage/app/private/taxonomy-20261118/corporate-pricing']:
    paths.update(p for p in (root / directory).rglob('*') if p.is_file())
leaks = []
checked = 0
for path in paths:
    if path.is_file() and path != root / '.env':
        checked += 1
        if key in path.read_bytes():
            leaks.append(str(path.relative_to(root.parent)))
print(json.dumps({'files_checked': checked, 'env_tracked': tracked, 'corporate_key_occurrences_outside_env': len(leaks), 'affected_paths': leaks}))
raise SystemExit(bool(leaks))
