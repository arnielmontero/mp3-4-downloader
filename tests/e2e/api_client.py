"""Tiny stdlib-only HTTP client shared by the end-to-end test scripts."""
from __future__ import annotations

import http.cookiejar
import json
import time
import urllib.error
import urllib.request


class Api:
    def __init__(self, base: str):
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, method: str, path: str, body=None, raw: bytes | None = None, headers=None):
        """Return (status, headers, bytes)."""
        data = raw
        hdrs = dict(headers or {})
        if body is not None:
            data = json.dumps(body).encode()
            hdrs.setdefault("Content-Type", "application/json")
        req = urllib.request.Request(self.base + path, data=data, method=method, headers=hdrs)
        try:
            with self.opener.open(req, timeout=60) as resp:
                return resp.status, resp.headers, resp.read()
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read()

    def json(self, method: str, path: str, body=None, **kw):
        status, headers, raw = self.request(method, path, body, **kw)
        try:
            return status, json.loads(raw.decode() or "null"), headers
        except ValueError:
            return status, None, headers

    def wait_for(self, job_id: str, states=("completed", "failed", "cancelled"), timeout=240, on_poll=None):
        deadline = time.time() + timeout
        last = None
        while time.time() < deadline:
            status, doc, _ = self.json("GET", f"/api/download/{job_id}")
            assert status == 200, (status, doc)
            last = doc["data"]
            if on_poll:
                on_poll(last)
            if last["status"] in states:
                return last
            time.sleep(0.5)
        raise TimeoutError(f"job {job_id} did not reach {states}; last={last}")
