"""Exercise the real installer with local archives and controlled downloads."""

import hashlib
import io
import os
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path

INSTALLER = Path(__file__).resolve().parents[1] / "scripts/install-ci-tool.sh"


class InstallerTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        self.archive = self.root / "release.tar.gz"
        payload = b'#!/bin/sh\nprintf executed >> "$EXECUTION_MARKER"\n'
        with tarfile.open(self.archive, "w:gz") as archive:
            member = tarfile.TarInfo("tool")
            member.size = len(payload)
            member.mode = 0o755
            archive.addfile(member, io.BytesIO(payload))
        self.digest = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        self.destination = self.root / "installed-tool"
        self.extraction = self.root / "extraction"
        self.execution = self.root / "execution"
        self.env = dict(os.environ, PATH=f"{self.bin}:{os.environ['PATH']}",
                        FIXTURE=str(self.archive), EXTRACTION_MARKER=str(self.extraction),
                        EXECUTION_MARKER=str(self.execution), REAL_TAR=shutil.which("tar"),
                        TMPDIR=str(self.root))
        self.write_executable("curl", """#!/usr/bin/env python3
import os
import shutil
import sys
target = sys.argv[sys.argv.index('-o') + 1]
shutil.copyfile(os.environ['FIXTURE'], target)
sys.exit(int(os.environ.get('DOWNLOAD_STATUS', '0')))
""")
        self.write_executable("tar", """#!/bin/sh
printf extracted >> "$EXTRACTION_MARKER"
exec "$REAL_TAR" "$@"
""")

    def write_executable(self, name, content):
        path = self.bin / name
        path.write_text(content)
        path.chmod(0o755)

    def install(self, digest=None, member="tool"):
        return subprocess.run(
            ["bash", str(INSTALLER), "https://example.invalid/release.tar.gz",
             digest or self.digest, member, str(self.destination)],
            env=self.env, text=True, capture_output=True, check=False,
        )

    def assert_rejected_before_extraction(self, result):
        self.assertNotEqual(0, result.returncode)
        self.assertFalse(self.extraction.exists())
        self.assertFalse(self.execution.exists())
        self.assertEqual([], list(self.root.glob("tmp.*")))

    def test_valid_archive_installs_executable_without_executing_it(self):
        result = self.install()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue(self.extraction.exists())
        self.assertFalse(self.execution.exists())
        self.assertEqual(0o755, self.destination.stat().st_mode & 0o777)
        subprocess.run([str(self.destination)], env=self.env, check=True)
        self.assertEqual("executed", self.execution.read_text())
        self.assertEqual([], list(self.root.glob("tmp.*")))

    def test_nested_archive_member_is_installed(self):
        with tarfile.open(self.archive, "r:gz") as archive:
            payload = archive.extractfile("tool").read()
        with tarfile.open(self.archive, "w:gz") as archive:
            member = tarfile.TarInfo("release/tool")
            member.size = len(payload)
            member.mode = 0o755
            archive.addfile(member, io.BytesIO(payload))
        digest = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        result = self.install(digest, "release/tool")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertTrue(self.destination.is_file())
        self.assertTrue(self.extraction.exists())
        self.assertFalse(self.execution.exists())

    def test_modified_archive_is_rejected_before_extraction(self):
        with self.archive.open("ab") as archive:
            archive.write(b"tampered")
        self.assert_rejected_before_extraction(self.install())
        self.assertFalse(self.destination.exists())

    def test_wrong_expected_checksum_preserves_existing_installation(self):
        self.destination.write_text("previous installation")
        self.assert_rejected_before_extraction(self.install("0" * 64))
        self.assertEqual("previous installation", self.destination.read_text())

    def test_failed_download_is_rejected_before_extraction(self):
        self.env["DOWNLOAD_STATUS"] = "22"
        self.assert_rejected_before_extraction(self.install())
        self.assertFalse(self.destination.exists())


if __name__ == "__main__":
    unittest.main()
