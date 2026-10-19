#!/usr/bin/env python3
"""Restore a validated XFedi backup into a stopped installation's private volume."""
import argparse
import os
from pathlib import Path
import re
import shutil
import sqlite3
import tarfile
import tempfile
import time


def restore(archive, data, replace=False):
    data.mkdir(mode=0o700, parents=True, exist_ok=True)
    target=data/'social.sqlite'
    if target.exists() and not replace:
        raise ValueError('Database exists. Stop all app services and use --replace for an intentional restore.')
    with tempfile.TemporaryDirectory(prefix='.restore-',dir=data) as tmp:
        stage=Path(tmp)
        with tarfile.open(archive,'r:gz') as tar:
            names=set()
            for member in tar:
                name=member.name.removeprefix('./')
                if member.isdir() and name in ('','.'): continue
                if not member.isfile() or not re.fullmatch(r'social\.sqlite|[0-9a-f]{32}\.(jpg|png|gif|webp)',name):
                    raise ValueError(f'Unexpected archive entry: {member.name}')
                if name in names: raise ValueError('Duplicate archive entry')
                names.add(name)
                source=tar.extractfile(member)
                with source, (stage/name).open('xb') as destination:
                    shutil.copyfileobj(source,destination)
                (stage/name).chmod(0o600)
        if 'social.sqlite' not in names: raise ValueError('Backup has no database')
        incoming=sqlite3.connect(stage/'social.sqlite')
        try:
            if incoming.execute('PRAGMA integrity_check').fetchone()[0]!='ok': raise ValueError('Database integrity check failed')
            if incoming.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='metadata'").fetchone():
                row=incoming.execute("SELECT value FROM metadata WHERE key='identity_url'").fetchone()
                if row and row[0]!=os.environ.get('XFEDI_URL'): raise ValueError('Backup federation identity differs from XFEDI_URL')
        finally: incoming.close()
        if target.exists():
            previous=data/f'.pre-restore-{time.time_ns()}.sqlite'
            old=sqlite3.connect(target); snapshot=sqlite3.connect(previous)
            try: old.backup(snapshot)
            finally: snapshot.close();old.close()
            previous.chmod(0o600)
            print(f'Previous database retained privately at {previous.name}')
        # Uploaded filenames are immutable random IDs; restore them before swapping DB.
        for image in stage.iterdir():
            if image.name!='social.sqlite': os.replace(image,data/image.name)
        for suffix in ('-wal','-shm'):
            (data/('social.sqlite'+suffix)).unlink(missing_ok=True)
        os.replace(stage/'social.sqlite',target)
        print('Database and uploads restored. Start the matching release to run its migration.')


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('archive',type=Path)
    parser.add_argument('--replace',action='store_true')
    args=parser.parse_args()
    restore(args.archive,Path(os.environ.get('XFEDI_DATA_DIR','/var/lib/xsukax-fediverse')),args.replace)

if __name__=='__main__': main()
