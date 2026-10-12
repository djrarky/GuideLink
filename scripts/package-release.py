#!/usr/bin/env python3
"""Build a deterministic GuideLink ZIP and checksums from the current source tree."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile


TOP_LEVEL = ('plugin.json', 'Plugin.php', 'README.md', 'LICENSE.md', 'CONTRIBUTING.md')
TREES = {'src': {'.php', '.json'}, 'docs': {'.md'},
         'tests': {'.php', '.html', '.py'}, 'scripts': {'.php', '.py'}}


def build_release(root: Path, output: Path) -> Path:
    manifest = json.loads((root / 'plugin.json').read_text())
    version = manifest.get('version', '')
    if manifest.get('id') != 'guidelink' or not re.fullmatch(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?', version):
        raise ValueError('Expected plugin id guidelink and a release version in plugin.json')

    files = [root / name for name in TOP_LEVEL]
    for directory, extensions in TREES.items():
        tree = root / directory
        if tree.is_symlink() or not tree.is_dir():
            raise ValueError(f'Missing or symlinked source directory: {directory}')
        for path in tree.rglob('*'):
            if path.is_symlink():
                raise ValueError(f'Release source contains a symlink: {path}')
            if any(part.startswith('.') or part == '__pycache__' for part in path.relative_to(root).parts):
                continue
            if path.is_file() and path.suffix in extensions:
                files.append(path)

    # Read everything before writing, so missing files never produce a partial ZIP.
    contents = {}
    for path in sorted(files):
        if path.is_symlink() or not path.is_file():
            raise ValueError(f'Missing or symlinked release file: {path}')
        contents[path.relative_to(root).as_posix()] = path.read_bytes()

    output.mkdir(parents=True, exist_ok=True)
    archive = output / 'guidelink.zip'
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
        for name, data in contents.items():
            info = zipfile.ZipInfo('guidelink/' + name, date_time=(1980, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            bundle.writestr(info, data, compresslevel=9)

    inventory = output / 'release.json'
    inventory.write_text(json.dumps({
        'plugin_id': 'guidelink', 'version': version,
        'source': 'current working tree; verify against the release commit before publishing',
        'files': {name: hashlib.sha256(data).hexdigest() for name, data in contents.items()},
    }, indent=2, sort_keys=True) + '\n')
    (output / 'SHA256SUMS').write_text(''.join(
        f'{hashlib.sha256(path.read_bytes()).hexdigest()}  {path.name}\n'
        for path in (archive, inventory)
    ))
    return archive


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='Defaults to dist/v<manifest version>')
    args = parser.parse_args()
    source = Path(__file__).resolve().parent.parent
    version = json.loads((source / 'plugin.json').read_text())['version']
    destination = args.output or source / 'dist' / ('v' + version)
    print(build_release(source, destination))
