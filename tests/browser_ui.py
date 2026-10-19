#!/usr/bin/env python3
"""Actual Chromium interaction smoke for the UI builder; no privileged fixture/login bypass."""
from pathlib import Path
import os
import subprocess
import tempfile
import shutil
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from threading import Thread
from functools import partial
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parents[1]
html = subprocess.check_output(['php', str(root/'scripts/render-form-preview.php')], cwd=root)
with tempfile.TemporaryDirectory(prefix='bf-browser-') as tmp:
    fixture = Path(tmp)/'form.html'
    html = html.replace(('file://'+str(root/'public/assets/css/app.css')).encode(), b'/app.css')
    html = html.replace(('file://'+str(root/'public/assets/js/form-studio.js')).encode(), b'/form-studio.js')
    fixture.write_bytes(html)
    shutil.copy2(root/'public/assets/css/app.css',Path(tmp)/'app.css')
    shutil.copy2(root/'public/assets/js/form-studio.js',Path(tmp)/'form-studio.js')
    handler=partial(SimpleHTTPRequestHandler,directory=tmp)
    httpd=ThreadingHTTPServer(('127.0.0.1',0),handler)
    thread=Thread(target=httpd.serve_forever,daemon=True)
    thread.start()
    with sync_playwright() as browser:
        chrome = browser.chromium.launch(headless=True, **({'executable_path': os.environ['BF_CHROMIUM']} if os.environ.get('BF_CHROMIUM') else {}), args=['--no-sandbox'])
        page = chrome.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(f'http://127.0.0.1:{httpd.server_port}/form.html')
        page.locator('[data-bf-editor-row]').first.wait_for()
        assert page.locator('[data-bf-editor-row]').count() == 2
        page.locator('[data-bf-add-field]').click()
        assert page.locator('[data-bf-editor-row]').count() == 3
        page.locator('[data-bf-editor-row]').nth(2).locator('[data-bf-name]').fill('phone')
        page.locator('[data-bf-editor-row]').nth(2).locator('[data-bf-label]').fill('Phone Number')
        # Prevent navigating from the disposable fixture, but still exercise submit handler.
        page.evaluate("document.querySelector('[data-bf-studio-form]').addEventListener('submit',e=>e.preventDefault(),true)")
        page.locator('[data-bf-studio-form] button[type=submit]').click()
        schema = page.locator('[data-bf-schema-output]').input_value()
        assert '"name":"phone"' in schema, schema
        # A second import rehydrates the editor without interpreting untrusted HTML.
        page.locator('[data-bf-form-json]').fill('{"title":"A <img src=x onerror=alert(1)>","fields":[{"name":"safe","label":"Safe","type":"text","required":true}]}')
        page.locator('[data-bf-import-json]').click()
        assert page.locator('[data-bf-editor-row]').count() == 1
        assert page.locator('[data-bf-form-title]').input_value().startswith('A <img')
        assert not errors, errors
        assert page.locator('body').count() == 1
        chrome.close()
    httpd.shutdown()
print('PASS: Chromium form builder add, edit, submit, JSON import, and DOM safety')
