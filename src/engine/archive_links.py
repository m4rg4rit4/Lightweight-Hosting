"""Make internal application links portable without following external links."""
from pathlib import Path
import os
import sys


def normalize_links(source, destination):
    source = Path(source).resolve(strict=True)
    destination = Path(destination).resolve(strict=True)
    if source.is_relative_to(destination) or destination.is_relative_to(source):
        raise ValueError('Archive copy and original application must be separate')
    for directory, directories, files in os.walk(destination, followlinks=False):
        for name in directories + files:
            copied = Path(directory) / name
            if not copied.is_symlink():
                continue
            original = source / copied.relative_to(destination)
            target = Path(os.readlink(copied))
            if not target.is_absolute():
                target = original.parent / target
            target = target.resolve(strict=False)
            if not target.is_relative_to(source):
                raise ValueError('Application archive contains an external symbolic link')
            portable_target = destination / target.relative_to(source)
            relative = os.path.relpath(portable_target, start=copied.parent)
            copied.unlink()
            copied.symlink_to(relative)


if __name__ == '__main__':
    normalize_links(sys.argv[1], sys.argv[2])
