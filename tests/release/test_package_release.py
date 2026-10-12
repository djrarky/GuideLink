import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
import zipfile


SPEC = importlib.util.spec_from_file_location(
    'package_release', Path(__file__).resolve().parents[2] / 'scripts/package-release.py')
PACKAGE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE)


class PackageReleaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'source'
        self.root.mkdir()
        for name in PACKAGE.TOP_LEVEL:
            (self.root / name).write_text('fixture\n')
        (self.root / 'plugin.json').write_text(json.dumps({'id': 'guidelink', 'version': '0.1.0'}))
        for name in PACKAGE.TREES:
            (self.root / name).mkdir()
        (self.root / 'src/Example.php').write_text('<?php\n')

    def test_reproducible_archive_excludes_local_files_and_records_source_hashes(self):
        (self.root / '.env').write_text('SECRET=never-package')
        (self.root / 'src/.env').write_text('SECRET=never-package')
        (self.root / 'src/cache.zip').write_bytes(b'cache')
        first = PACKAGE.build_release(self.root, Path(self.temp.name) / 'first')
        second = PACKAGE.build_release(self.root, Path(self.temp.name) / 'second')
        self.assertEqual(first.read_bytes(), second.read_bytes())
        with zipfile.ZipFile(first) as bundle:
            self.assertIn('guidelink/src/Example.php', bundle.namelist())
            self.assertEqual(len(PACKAGE.TOP_LEVEL) + 1, len(bundle.namelist()))
            self.assertNotIn(b'never-package', b''.join(bundle.read(name) for name in bundle.namelist()))
        inventory = json.loads((first.parent / 'release.json').read_text())
        self.assertEqual(inventory['files']['src/Example.php'], PACKAGE.hashlib.sha256(b'<?php\n').hexdigest())
        self.assertIn(PACKAGE.hashlib.sha256(first.read_bytes()).hexdigest(), (first.parent / 'SHA256SUMS').read_text())

    def test_symlinks_are_rejected(self):
        (self.root / 'src/linked.php').symlink_to(self.root / 'Plugin.php')
        with self.assertRaisesRegex(ValueError, 'symlink'):
            PACKAGE.build_release(self.root, Path(self.temp.name) / 'output')

    def test_missing_required_file_is_rejected(self):
        (self.root / 'LICENSE.md').unlink()
        with self.assertRaisesRegex(ValueError, 'Missing'):
            PACKAGE.build_release(self.root, Path(self.temp.name) / 'output')


if __name__ == '__main__':
    unittest.main()
