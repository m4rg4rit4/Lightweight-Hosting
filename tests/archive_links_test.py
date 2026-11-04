"""Linux integration test of the same link normalization used by backups."""
from pathlib import Path
import importlib.util
import shutil
import tarfile
import tempfile

project = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('archive_links', project/'src/engine/archive_links.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
temporary = project/'temp'
temporary.mkdir(exist_ok=True)
with tempfile.TemporaryDirectory(dir=temporary, prefix='archive-links-') as directory:
    root = Path(directory)
    original = root/'original'
    (original/'public').mkdir(parents=True)
    (original/'storage/app/public').mkdir(parents=True)
    (original/'storage/app/public/image.txt').write_text('asset')
    link = original/'public/storage'
    link.symlink_to(original/'storage/app/public', target_is_directory=True)
    copied = root/'copy'
    shutil.copytree(original, copied, symlinks=True)
    module.normalize_links(original, copied)
    assert link.readlink().is_absolute(), 'Original application was changed'
    assert not (copied/'public/storage').readlink().is_absolute()
    assert (copied/'public/storage/image.txt').read_text() == 'asset'
    archive = root/'application.tar.gz'
    with tarfile.open(archive, 'w:gz', dereference=False) as output:
        output.add(copied, arcname='webroot')
    restored = root/'restored'
    with tarfile.open(archive) as source:
        source.extractall(restored, filter='data')
    assert (restored/'webroot/public/storage/image.txt').read_text() == 'asset'
    external = root/'outside.txt'
    external.write_text('outside')
    (copied/'external').symlink_to(external)
    try:
        module.normalize_links(original, copied)
    except ValueError:
        pass
    else:
        raise AssertionError('External link was accepted')
print('Internal links, original preservation, restore and external-link rejection passed')
