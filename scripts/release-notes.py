#!/usr/bin/env python3
"""Generate grouped GitHub release notes from the commits leading to a version tag."""

import argparse
import html
from pathlib import Path
import re
import subprocess
from urllib.parse import quote


VERSION_TAG = re.compile(r'v(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?')
CATEGORIES = {
    'breaking': 'Breaking Changes',
    'feat': 'Features',
    'fix': 'Bug Fixes',
    'perf': 'Performance',
    'refactor': 'Refactoring',
    'docs': 'Documentation',
    'chore': 'Maintenance',
    'other': 'Other Changes',
}


def git(root: Path, *args: str) -> str:
    return subprocess.check_output(['git', '-C', str(root), *args], text=True).strip()


def previous_tag(root: Path, tag: str, commit: str) -> str | None:
    """Choose the closest version tag on the release's first-parent history."""
    current = VERSION_TAG.fullmatch(tag)
    history = {sha: i for i, sha in enumerate(git(root, 'rev-list', '--first-parent', commit).splitlines())}
    candidates = []
    for name in git(root, 'tag', '--merged', commit, '--sort=-version:refname').splitlines():
        version = VERSION_TAG.fullmatch(name)
        if name == tag or not version:
            continue
        # Stable releases include changes since the preceding stable version.
        if current[4] is None and version[4] is not None:
            continue
        if tuple(map(int, version.group(1, 2, 3))) > tuple(map(int, current.group(1, 2, 3))):
            continue
        sha = git(root, 'rev-parse', f'refs/tags/{name}^{{commit}}')
        if sha in history:
            candidates.append((history[sha], name))
    return min(candidates, key=lambda item: item[0])[1] if candidates else None


def escape_markdown(text: str) -> str:
    return re.sub(r'([\\`*_{}\[\]()#+!|])', r'\\\1', html.escape(text, quote=False))


def generate(root: Path, tag: str, repository: str) -> str:
    if not VERSION_TAG.fullmatch(tag):
        raise ValueError('Expected a version tag such as v0.1.0')
    if not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise ValueError('Expected a GitHub repository in OWNER/REPO form')
    if git(root, 'rev-parse', '--is-shallow-repository') == 'true':
        raise ValueError('Release notes require full Git history and tags (fetch-depth: 0)')
    commit = git(root, 'rev-parse', f'refs/tags/{tag}^{{commit}}')
    previous = previous_tag(root, tag, commit)
    revision = f'refs/tags/{previous}..{commit}' if previous else commit
    log = git(root, 'log', '--no-merges', '--reverse', '--format=%H%x00%s%x00%b%x00', revision)
    fields = log.split('\0')
    groups = {key: [] for key in CATEGORIES}
    seen = set()
    url = f'https://github.com/{repository}'
    for i in range(0, len(fields) - 2, 3):
        sha, subject, body = fields[i].strip(), fields[i + 1].strip(), fields[i + 2]
        conventional = re.match(r'^(\w+)(?:\([^)]*\))?(!)?:\s*(.+)$', subject)
        kind = conventional[1].lower() if conventional else 'other'
        message = conventional[3] if conventional else subject
        breaking = bool(conventional and conventional[2]) or bool(re.search(r'^BREAKING[ -]CHANGE:', body, re.M))
        if not breaking and re.fullmatch(
            r'(?:version bump\b.*|prepare v?\d+\.\d+\.\d+.*|v?\d+\.\d+\.\d+(?:-[\w.-]+)?)',
            message, re.I,
        ):
            continue
        category = 'breaking' if breaking else kind if kind in CATEGORIES else 'other'
        key = (category, ' '.join(message.casefold().split()))
        if key in seen:
            continue
        seen.add(key)
        groups[category].append(f'- {escape_markdown(message)} ([{sha[:7]}]({url}/commit/{sha}))')

    lines = ["## What's Changed", '']
    for category, title in CATEGORIES.items():
        if groups[category]:
            lines.extend([f'### {title}', '', *groups[category], ''])
    if not seen:
        lines.extend(['No user-facing changes were identified in the commit messages.', ''])
    if previous:
        lines.append(f'**Full comparison:** [{previous}...{tag}]({url}/compare/{quote(previous)}...{quote(tag)})')
    else:
        lines.append(f'**Initial release:** [commit history]({url}/commits/{quote(tag)})')
    return '\n'.join(lines) + '\n'


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--tag', required=True)
    parser.add_argument('--repository', required=True, help='GitHub OWNER/REPO')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    args.output.write_text(generate(Path(__file__).resolve().parent.parent, args.tag, args.repository))
