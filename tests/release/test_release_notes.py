import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest


SPEC = importlib.util.spec_from_file_location(
    'release_notes', Path(__file__).resolve().parents[2] / 'scripts/release-notes.py')
NOTES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(NOTES)


class ReleaseNotesTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'repo'
        self.root.mkdir()
        self.git('init', '-b', 'main')
        self.git('config', 'user.name', 'Release Test')
        self.git('config', 'user.email', 'release@example.invalid')
        self.git('config', 'commit.gpgsign', 'false')
        self.git('config', 'tag.gpgsign', 'false')

    def git(self, *args):
        return NOTES.git(self.root, *args)

    def commit(self, message):
        self.git('commit', '--allow-empty', '-m', message)
        return self.git('rev-parse', 'HEAD')

    def notes(self, tag):
        self.git('tag', '-a', tag, '-m', tag)
        return NOTES.generate(self.root, tag, 'owner/guidelink')

    def test_initial_release_includes_root_commit_and_groups_changes(self):
        sha = self.commit('feat(aliases): Share alias sets')
        self.commit('fix: Preserve +1 matching')
        self.commit('docs: Explain updates')
        self.commit('Improve source parsing')
        notes = self.notes('v0.1.0')
        for title in ['Features', 'Bug Fixes', 'Documentation', 'Other Changes']:
            self.assertIn('### ' + title, notes)
        self.assertIn('/commit/' + sha, notes)
        self.assertIn('Share alias sets', notes)
        self.assertIn('Initial release', notes)

    def test_release_range_deduplicates_and_skips_version_only_commits(self):
        self.commit('feat: Old feature')
        self.git('tag', 'v0.1.0')
        self.commit('fix: Repair aliases')
        self.commit('fix: Repair aliases')
        self.commit('Version bump 0.2.0')
        self.commit('chore(release): v0.2.0')
        self.commit('Prepare v0.2.0')
        notes = self.notes('v0.2.0')
        self.assertNotIn('Old feature', notes)
        self.assertEqual(notes.count('Repair aliases'), 1)
        self.assertNotIn('Version bump', notes)
        self.assertNotIn('Prepare', notes)
        self.assertNotIn('### Maintenance', notes)
        self.assertIn('/compare/v0.1.0...v0.2.0', notes)

    def test_stable_release_includes_prerelease_changes_and_breaking_changes(self):
        self.commit('Initial source')
        self.git('tag', 'v0.1.0')
        self.commit('feat!: Change profile format')
        self.git('tag', 'v0.2.0-rc.1')
        self.commit('fix: Change IDs\n\nBREAKING CHANGE: Guides must be reselected.')
        notes = self.notes('v0.2.0')
        self.assertIn('### Breaking Changes', notes)
        self.assertIn('Change profile format', notes)
        self.assertIn('Change IDs', notes)
        self.assertIn('/compare/v0.1.0...v0.2.0', notes)

    def test_merged_side_branch_tag_does_not_hide_changes(self):
        self.commit('Initial source')
        self.git('tag', 'v0.1.0')
        self.git('checkout', '-b', 'feature')
        self.commit('feat: Branch feature')
        self.git('tag', 'v0.1.1')
        self.git('checkout', 'main')
        self.commit('fix: Main fix')
        self.git('merge', '--no-ff', 'feature', '-m', 'Merge feature')
        notes = self.notes('v0.2.0')
        self.assertIn('Branch feature', notes)
        self.assertIn('Main fix', notes)
        self.assertNotIn('Merge feature', notes)
        self.assertIn('/compare/v0.1.0...v0.2.0', notes)

    def test_subjects_are_plain_text_and_empty_release_is_explained(self):
        self.commit('fix: Handle [labels](bad) and <tags> | safely')
        notes = self.notes('v0.1.0')
        self.assertIn(r'\[labels\]\(bad\)', notes)
        self.assertIn('&lt;tags&gt;', notes)
        self.commit('chore: v0.1.1')
        notes = self.notes('v0.1.1')
        self.assertIn('No user-facing changes', notes)

    def test_shallow_history_and_invalid_inputs_fail_clearly(self):
        self.commit('feat: First feature')
        self.git('tag', 'v0.1.0')
        shallow = Path(self.temp.name) / 'shallow'
        subprocess.run(['git', 'clone', '--quiet', '--depth=1', self.root.as_uri(), str(shallow)], check=True)
        with self.assertRaisesRegex(ValueError, 'full Git history'):
            NOTES.generate(shallow, 'v0.1.0', 'owner/repo')
        with self.assertRaisesRegex(ValueError, 'version tag'):
            NOTES.generate(self.root, 'main', 'owner/repo')
        with self.assertRaisesRegex(ValueError, 'OWNER/REPO'):
            NOTES.generate(self.root, 'v0.1.0', 'bad repository')


if __name__ == '__main__':
    unittest.main()
