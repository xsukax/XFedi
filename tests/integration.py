#!/usr/bin/env python3
"""Isolated HTTP tests using PHP's development server, no external accounts."""
from contextlib import contextmanager
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
@contextmanager
def connect(path):
    db=sqlite3.connect(path)
    try:
        with db: yield db
    finally: db.close()
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class HTTPTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory(prefix='xfedi-http-')
        with socket.socket() as s:
            s.bind(('127.0.0.1', 0)); cls.port = s.getsockname()[1]
        cls.origin = f'http://127.0.0.1:{cls.port}'
        cls.env = dict(os.environ, XFEDI_URL='https://social.example/index.php', XFEDI_DATA_DIR=cls.tmp.name,
                       XFEDI_ALLOW_INSECURE_HTTP='1', XFEDI_LOCAL_ORIGIN=cls.origin,
                       XFEDI_SETUP_TOKEN='test-only-setup-token-1234567890')
        cls.log = tempfile.TemporaryFile()
        cls.server = subprocess.Popen(['php', '-S', f'127.0.0.1:{cls.port}', str(ROOT/'tests/router.php')], env=cls.env, cwd=ROOT, stdout=cls.log, stderr=cls.log)
        cls.cookies = http.cookiejar.CookieJar()
        cls.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cls.cookies), NoRedirect())
        for _ in range(60):
            try:
                cls.request('/healthz'); break
            except (ConnectionError, urllib.error.URLError): time.sleep(0.1)
        else: raise RuntimeError('Local PHP server failed to start')
        cls.setup_account()

    @classmethod
    def request(cls, path, data=None, headers=None):
        req=urllib.request.Request(cls.origin+path, data=data if isinstance(data,bytes) else (urllib.parse.urlencode(data).encode() if data is not None else None), headers=headers or {})
        try: res=cls.client.open(req, timeout=10)
        except urllib.error.HTTPError as e: res=e
        try: return res.code, res.read().decode(), res.headers
        finally: res.close()

    @classmethod
    def token(cls,path='/'):
        status,html,_=cls.request(path)
        if status != 200: raise AssertionError(f'GET {path}: {status}')
        match=re.search(r'name="_csrf" value="([a-f0-9]+)"',html)
        if not match and path=='/': return cls.token('/auth/sign_in')
        if not match: raise AssertionError('Missing CSRF token')
        return match.group(1)

    @classmethod
    def post(cls,action,**fields):
        data={'action':action,'_csrf':cls.token(),**fields}
        return cls.request('/',data)

    @classmethod
    def setup_account(cls):
        status,_,_=cls.post('setup',username='alice',password='test-password-1234',setup_token='incorrect')
        if status != 200: raise AssertionError('Setup error should be displayed')
        status,_,_=cls.post('setup',username='alice',password='test-password-1234',setup_token=cls.env['XFEDI_SETUP_TOKEN'])
        if status != 303: raise AssertionError('Account setup failed')
        status,_,_=cls.post('post',content='Hello <world> & friends! #Welcome')
        if status != 303: raise AssertionError('Initial publication failed')

    @classmethod
    def tearDownClass(cls):
        cls.server.terminate(); cls.server.wait(timeout=10); cls.log.close(); cls.tmp.cleanup()

    def test_01_routes_and_private_files(self):
        for path in ['/', '/@alice','/@alice/1','/tags/Welcome','/auth/sign_in','/notifications','/settings','/bookmarks','/boosts','/queue','/search','/following','/followers','/public','/public/local','/instances']:
            with self.subTest(path=path): self.assertEqual(self.request(path)[0],200)
        for path in ['/.env','/.git/config','/social.sqlite','/social.sqlite-wal','/app/core.php','/worker.py','/Dockerfile','/compose.yaml','/index.php.bak','/assets/missing.css','/@nobody','/@nobody/1','/@alice/9999']:
            with self.subTest(path=path): self.assertEqual(self.request(path)[0],404)
        self.assertEqual(self.request('/assets/theme.css')[2].get_content_type(),'text/css')
        self.assertEqual(self.request('/assets/ui.js')[0],200)

    def test_02_discovery(self):
        status,body,headers=self.request('/.well-known/webfinger?resource=acct:alice@social.example')
        self.assertEqual(status,200); self.assertEqual(headers.get_content_type(),'application/jrd+json')
        self.assertEqual(json.loads(body)['links'][0]['href'],'https://social.example/index.php?ap=actor')
        self.assertEqual(self.request('/.well-known/webfinger?resource=acct:nobody@social.example')[0],404)
        node=json.loads(self.request('/index.php?ap=nodeinfo')[1]);self.assertEqual(node['software']['name'],'xfedi')
        actor=json.loads(self.request('/@alice',headers={'Accept':'application/activity+json'})[1]);self.assertEqual(actor['id'],'https://social.example/index.php?ap=actor')
        note=json.loads(self.request('/@alice/1',headers={'Accept':'application/activity+json'})[1]);self.assertEqual(note['id'],'https://social.example/index.php?ap=note&id=1')
        self.assertNotIn('private_key',self.request('/index.php?ap=actor')[1])

    def test_03_feeds_and_sitemap(self):
        status,body,headers=self.request('/feed.rss');self.assertEqual(status,200);self.assertEqual(headers.get_content_type(),'application/rss+xml')
        root=ET.fromstring(body);self.assertEqual(root.find('channel/item/title').text,'Hello <world> & friends! #Welcome')
        tag=ET.fromstring(self.request('/tags/Welcome.rss')[1]);self.assertEqual(len(tag.findall('channel/item')),1)
        self.assertEqual(len(ET.fromstring(self.request('/tags/Other.rss')[1]).findall('channel/item')),0)
        ns={'s':'http://www.sitemaps.org/schemas/sitemap/0.9'}
        index=ET.fromstring(self.request('/sitemap.xml')[1]);self.assertEqual(len(index.findall('s:sitemap',ns)),1)
        urls=ET.fromstring(self.request('/sitemap.xml?p=1')[1]);self.assertIn(self.origin+'/@alice/1',[e.text for e in urls.findall('s:url/s:loc',ns)])
        self.assertIn('/sitemap.xml',self.request('/robots.txt')[1])

    def test_04_csrf_and_inbox_auth(self):
        self.assertEqual(self.request('/',{'action':'delete','id':'1','_csrf':'bad'})[0],403)
        self.assertEqual(self.request('/index.php?ap=inbox',{'type':'Like'},headers={'Host':'social.example'})[0],401)
        self.assertEqual(self.request('/index.php?ap=actor',{})[0],404)

    def test_05_bookmark_boost_like_and_undo(self):
        note='https://social.example/index.php?ap=note&id=1'
        for action,table in [('bookmark','bookmarks'),('boost','boosts_local'),('like','likes_local')]:
            self.assertEqual(self.post(action,note_id=note)[0],303)
            self.assertEqual(self.post(action,note_id=note)[0],303)
            with connect(Path(self.tmp.name)/'social.sqlite') as db:self.assertEqual(db.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0],1)
        for action,table in [('unbookmark','bookmarks'),('unboost','boosts_local'),('unlike','likes_local')]:
            self.assertEqual(self.post(action,note_id=note)[0],303)
            with connect(Path(self.tmp.name)/'social.sqlite') as db:self.assertEqual(db.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0],0)

    def test_06_notifications_read_unread(self):
        with connect(Path(self.tmp.name)/'social.sqlite') as db:
            db.execute('INSERT INTO notifications(kind,actor_id,object_id,created_at) VALUES(?,?,?,?)',('mention','https://remote.example/users/bob','https://social.example/index.php?ap=note&id=1',int(time.time())))
            nid=db.execute('SELECT MAX(id) FROM notifications').fetchone()[0]
        status,html,_=self.request('/notifications');self.assertEqual(status,200);self.assertIn('View conversation',html)
        with connect(Path(self.tmp.name)/'social.sqlite') as db:self.assertEqual(db.execute('SELECT is_read FROM notifications WHERE id=?',(nid,)).fetchone()[0],0)
        self.assertEqual(self.post('notification_read',id=nid)[0],303)
        with connect(Path(self.tmp.name)/'social.sqlite') as db:self.assertEqual(db.execute('SELECT is_read FROM notifications WHERE id=?',(nid,)).fetchone()[0],1)
        self.assertEqual(self.post('notification_unread',id=nid)[0],303)

    def test_07_profile_timezone_verification(self):
        self.assertEqual(self.post('profile',name='Alice',bio='Hello',timezone='Africa/Addis_Ababa',website='https://example.com')[0],303)
        with connect(Path(self.tmp.name)/'social.sqlite') as db:
            u=db.execute('SELECT timezone,website_verified_at FROM users').fetchone();self.assertEqual(u,('Africa/Addis_Ababa',0))
        self.assertNotIn('✓ Verified website',self.request('/@alice')[1])
        self.assertEqual(self.post('profile',name='Alice',bio='Hello',timezone='Invalid/Zone',website='')[0],200)
        self.assertEqual(self.post('profile',name='Alice',bio='Hello',timezone='UTC',website='https://127.0.0.1')[0],200)

    def test_08_quotes_and_deletions(self):
        note='https://social.example/index.php?ap=note&id=1'
        self.assertEqual(self.post('quote',content='My commentary',quote_of=note)[0],303)
        with connect(Path(self.tmp.name)/'social.sqlite') as db:pid=db.execute('SELECT MAX(id) FROM posts').fetchone()[0]
        self.assertEqual(json.loads(self.request('/index.php?ap=note&id='+str(pid))[1])['quoteUrl'],note)
        self.assertEqual(self.post('edit',id=pid,content='Revised commentary #Updated')[0],303)
        self.assertIn('Revised commentary',self.request('/feed.rss')[1])
        self.assertEqual(self.post('delete',id=pid)[0],303)
        status,body,_=self.request('/index.php?ap=note&id='+str(pid));self.assertEqual(status,410);self.assertEqual(json.loads(body)['type'],'Tombstone')
        self.assertNotIn('Revised commentary',self.request('/feed.rss')[1])
        self.assertEqual(self.request('/@alice/'+str(pid))[0],404)

    def test_09_signed_inbox_and_worker(self):
        signed=json.loads(subprocess.check_output(['php',str(ROOT/'tests/sign_request.php')],env=self.env))
        self.assertEqual(self.request(signed['target'],signed['body'].encode(),headers=signed['headers'])[0],202)
        self.assertEqual(self.request(signed['target'],signed['body'].encode(),headers=signed['headers'])[0],202)
        self.assertEqual(self.request(signed['target'],(signed['body']+' ').encode(),headers=signed['headers'])[0],401)
        with connect(Path(self.tmp.name)/'social.sqlite') as db: db.execute("UPDATE users SET website='',website_verified_at=0")
        result=subprocess.run(['python3',str(ROOT/'worker.py'),'--once'],env=self.env,capture_output=True,text=True,timeout=60)
        self.assertEqual(result.returncode,0,result.stderr)
        with connect(Path(self.tmp.name)/'social.sqlite') as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM inbox_jobs WHERE status='processed'").fetchone()[0],1)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM likes_remote').fetchone()[0],1)
        self.assertIn('favourited your post',self.request('/notifications')[1])

    def test_10_remote_presentation_and_instances(self):
        actor='https://remote.example/users/bob';original='https://remote.example/notes/gallery';quote='https://remote.example/notes/quote'
        media=json.dumps([{'url':'https://cdn.example/one.webp','type':'image','alt':'First photo'},{'url':'https://cdn.example/two.avif','type':'image','alt':'Second photo'}])
        with connect(Path(self.tmp.name)/'social.sqlite') as db:
            db.execute('UPDATE remote_actors SET cover=?,name=? WHERE id=?',('https://cdn.example/header.jpg','Remote Bob',actor))
            for nid,content,quoted,images in [(original,'A remote gallery','',media),(quote,'A remote quote',original,'[]')]:
                db.execute('INSERT INTO remote_notes(id,author_id,content,content_html,summary,url,image,in_reply_to,created_at,updated_at,quote_of,media_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',(nid,actor,content,'','',nid,'https://cdn.example/one.webp' if images!='[]' else '','',int(time.time()),int(time.time()),quoted,images))
        profile=self.request('/accounts?actor='+urllib.parse.quote(actor,safe=''))[1]
        self.assertIn('https://cdn.example/header.jpg',profile);self.assertIn('Refresh profile and header',profile)
        federated=self.request('/public')[1]
        self.assertIn('First photo',federated);self.assertIn('Second photo',federated);self.assertIn('Quoted post',federated)
        self.assertIn('Hide this remote post locally',federated)
        self.assertEqual(self.post('hide_remote',note_id=original,return_page='federated')[0],303)
        # The quote may still refer to the original; hiding does not destroy public data.
        with connect(Path(self.tmp.name)/'social.sqlite') as db:
            self.assertEqual(db.execute('SELECT deleted FROM remote_notes WHERE id=?',(original,)).fetchone()[0],0)
            self.assertEqual(db.execute('SELECT COUNT(*) FROM hidden_posts WHERE note_id=?',(original,)).fetchone()[0],1)
        self.assertEqual(self.post('unhide_posts')[0],303)
        self.assertEqual(self.post('subscribe_instance',domain='remote.example')[0],303)
        self.assertIn('remote.example',self.request('/instances')[1])
        self.assertEqual(self.post('subscribe_instance',domain='127.0.0.1')[0],200)
        self.assertEqual(self.post('unsubscribe_instance',domain='remote.example')[0],303)
        self.assertNotIn('Add this link to your website',self.request('/settings')[1])
        self.assertEqual(self.post('post',content='Delete directly from the timeline')[0],303)
        with connect(Path(self.tmp.name)/'social.sqlite') as db:pid=db.execute('SELECT MAX(id) FROM posts').fetchone()[0]
        self.assertIn('Delete your post',self.request('/')[1])
        status,_,headers=self.post('delete',id=pid,return_page='home')
        self.assertEqual(status,303);self.assertEqual(headers['Location'],self.origin+'/')
        self.assertEqual(self.request('/@alice/'+str(pid))[0],404)

    def test_99_signout_and_private_views(self):
        self.assertEqual(self.post('logout')[0],303)
        for path in ['/bookmarks','/notifications','/settings','/queue','/boosts']:
            self.assertIn('Sign in',self.request(path)[1])
        self.assertIn('Sign In',self.request('/')[1])
        self.assertEqual(self.request('/',{'action':'bookmark','_csrf':self.token(),'note_id':'https://social.example/index.php?ap=note&id=1'})[0],403)
        self.assertEqual(self.post('login',username='alice',password='incorrect')[0],200)
        self.assertEqual(self.post('login',username='alice',password='test-password-1234')[0],303)

if __name__=='__main__': unittest.main(verbosity=2)
