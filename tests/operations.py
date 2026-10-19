#!/usr/bin/env python3
"""Backup/restore checks with disposable databases and hostile archive fixtures."""
import importlib.util
import io
import os
from pathlib import Path
import sqlite3
import subprocess
import tarfile
import tempfile
import unittest

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('xfedi_restore',ROOT/'bin/restore.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class OperationsTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory(prefix='xfedi-ops-');self.base=Path(self.tmp.name)
        self.source=self.base/'source';self.source.mkdir();self.target=self.base/'target'
        self.old_url=os.environ.get('XFEDI_URL');os.environ['XFEDI_URL']='https://social.example/index.php'
        db=sqlite3.connect(self.source/'social.sqlite')
        try:
            db.executescript("CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT); CREATE TABLE posts(id INTEGER PRIMARY KEY,content TEXT);")
            db.execute('INSERT INTO metadata VALUES(?,?)',('identity_url',os.environ['XFEDI_URL']))
            db.execute('INSERT INTO posts VALUES(1,?)',('Preserved post',));db.commit()
        finally: db.close()
        self.image='a'*32+'.png';(self.source/self.image).write_bytes(b'immutable fixture')
        self.archive=self.base/'backup.tar.gz'
        with self.archive.open('wb') as out:
            subprocess.run(['php',str(ROOT/'bin/backup.php')],env=dict(os.environ,XFEDI_DATA_DIR=str(self.source)),stdout=out,check=True)
    def tearDown(self):
        if self.old_url is None:os.environ.pop('XFEDI_URL',None)
        else:os.environ['XFEDI_URL']=self.old_url
        self.tmp.cleanup()
    def test_backup_and_restore(self):
        with tarfile.open(self.archive) as tar:self.assertEqual(set(tar.getnames()),{'.','./social.sqlite','./'+self.image})
        module.restore(self.archive,self.target)
        db=sqlite3.connect(self.target/'social.sqlite')
        try:self.assertEqual(db.execute('SELECT content FROM posts').fetchone()[0],'Preserved post')
        finally:db.close()
        self.assertEqual((self.target/self.image).read_bytes(),b'immutable fixture')
    def test_existing_database_requires_replace(self):
        module.restore(self.archive,self.target)
        with self.assertRaises(ValueError):module.restore(self.archive,self.target)
        module.restore(self.archive,self.target,replace=True)
        self.assertEqual(len(list(self.target.glob('.pre-restore-*.sqlite'))),1)
    def test_identity_mismatch(self):
        os.environ['XFEDI_URL']='https://other.example/index.php'
        with self.assertRaises(ValueError):module.restore(self.archive,self.target)
        self.assertFalse((self.target/'social.sqlite').exists())
    def test_traversal_and_symlink(self):
        for symlink in (False,True):
            bad=self.base/f'hostile-{symlink}.tar.gz'
            with tarfile.open(bad,'w:gz') as tar:
                member=tarfile.TarInfo('a'*32+'.png' if symlink else '../escape')
                if symlink:member.type=tarfile.SYMTYPE;member.linkname='/etc/passwd';tar.addfile(member)
                else:member.size=3;tar.addfile(member,io.BytesIO(b'bad'))
            with self.assertRaises(ValueError):module.restore(bad,self.target)
        self.assertFalse((self.base/'escape').exists())

if __name__=='__main__':unittest.main(verbosity=2)
