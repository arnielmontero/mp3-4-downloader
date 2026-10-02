"""End-to-end tests against the running Docker stack with the fake yt-dlp engine.

    WEB_PORT=18080 docker compose -p ytd-test -f docker-compose.yml -f docker-compose.test.yml up -d --build
    python -m unittest discover -s tests/e2e -p "test_e2e.py" -v        (E2E_BASE=http://localhost:18080)

No YouTube access is needed: the worker runs tests/fixtures/fake-yt-dlp (see its header for the
video-id conventions). Tests that need to inspect/kill processes use `docker compose exec`.
"""
from __future__ import annotations

import json
import os
import random
import re
import subprocess
import time
import unittest
from pathlib import Path

from api_client import Api

ROOT = Path(__file__).resolve().parents[2]
BASE = os.environ.get("E2E_BASE", "http://localhost:18080")
PROJECT = os.environ.get("E2E_PROJECT", "ytd-test")
COMPOSE = ["docker", "compose", "-p", PROJECT, "-f", str(ROOT / "docker-compose.yml"), "-f", str(ROOT / "docker-compose.test.yml")]
ENV = {**os.environ, "WEB_PORT": BASE.rsplit(":", 1)[-1]}


def compose(*args: str, check=True, timeout=120) -> subprocess.CompletedProcess:
    return subprocess.run(COMPOSE + list(args), capture_output=True, text=True, cwd=ROOT, env=ENV, check=check, timeout=timeout)


def exec_in(service: str, *cmd: str) -> str:
    return compose("exec", "-T", service, *cmd, check=False).stdout


def fake_ip() -> str:
    return f"198.51.100.{random.randint(1, 250)}"


def client() -> Api:
    """A fresh browser: own cookie jar and own (spoofed, proxy-trusted) client IP for rate limiting."""
    api = Api(BASE)
    ip = fake_ip()
    original = api.request

    def request(method, path, body=None, raw=None, headers=None):
        return original(method, path, body, raw, {"X-Forwarded-For": ip, **(headers or {})})

    api.request = request  # type: ignore[assignment]
    return api


def analyze_and_download(api: Api, video: str, fmt="mp4", **extra) -> str:
    status, doc, _ = api.json("POST", "/api/download", {"url": f"https://www.youtube.com/watch?v={video}", "format": fmt, **extra})
    assert status == 202, (status, doc)
    return doc["job_id"]


def wait_status(api: Api, job_id: str, wanted: str, timeout=40):
    deadline = time.time() + timeout
    last = None
    while time.time() < deadline:
        _, doc, _ = api.json("GET", f"/api/download/{job_id}")
        last = doc["data"]
        if last["status"] == wanted:
            return last
        if last["status"] in ("failed", "completed", "cancelled") and wanted not in ("failed", "completed", "cancelled"):
            break
        time.sleep(0.3)
    raise AssertionError(f"job {job_id} never reached {wanted}: {last}")


def temp_dirs() -> list[str]:
    out = exec_in("worker", "sh", "-c", "ls /var/www/storage/temp")
    return [n for n in out.split() if re.fullmatch(r"[a-f0-9]{32}", n)]


# ===================================================================================== tests

class TestAvailability(unittest.TestCase):
    def test_01_containers_are_healthy(self):  # acceptance 1
        out = compose("ps", "--format", "{{.Service}} {{.Status}}").stdout
        for service in ("app", "web", "worker"):
            line = next((l for l in out.splitlines() if l.startswith(service + " ")), "")
            self.assertIn("(healthy)", line, out)

    def test_02_ui_loads(self):  # acceptance 2
        api = client()
        status, headers, body = api.request("GET", "/")
        html = body.decode()
        self.assertEqual(200, status)
        self.assertIn("YouTube Downloader", html)
        self.assertIn('id="searchInput"', html)
        self.assertIn('id="jobList"', html)  # right-hand downloads sidebar
        for asset in ("/assets/vendor/bootstrap.min.css", "/assets/vendor/bootstrap.bundle.min.js", "/assets/js/app.js", "/assets/js/api.js", "/assets/css/app.css"):
            self.assertEqual(200, api.request("GET", asset)[0], asset)

    def test_03_health_endpoint(self):
        status, doc, _ = client().json("GET", "/api/health")
        self.assertEqual(200, status)
        self.assertEqual("ok", doc["status"])
        self.assertTrue(doc["yt_dlp"])
        self.assertTrue(doc["ffmpeg"])
        self.assertTrue(doc["worker"]["alive"])

    def test_04_about_reports_versions(self):
        _, doc, _ = client().json("GET", "/api/about")
        self.assertTrue(doc["success"])
        self.assertEqual("1.0.0", doc["data"]["version"])
        self.assertEqual("2099.01.01", doc["data"]["yt_dlp_version"])  # fake engine
        self.assertTrue(doc["data"]["ffmpeg_version"])

    def test_05_security_headers_and_hidden_paths(self):
        api = client()
        _, headers, _ = api.request("GET", "/")
        self.assertEqual("nosniff", headers["X-Content-Type-Options"])
        self.assertEqual("DENY", headers["X-Frame-Options"])
        self.assertIn("default-src 'self'", headers["Content-Security-Policy"])
        self.assertNotIn("X-Powered-By", headers)
        _, h2, _ = api.request("GET", "/api/health")
        self.assertEqual("nosniff", h2["X-Content-Type-Options"])
        self.assertNotIn("X-Powered-By", h2)
        self.assertNotIn("nginx/", h2.get("Server", ""))
        for path in ("/storage/", "/storage/jobs/", "/_protected_downloads/", "/_protected_downloads/x/y.mp4", "/backend/composer.json",
                     "/application/config/config.php", "/vendor/autoload.php", "/.env", "/.git/config", "/backend/public/index.php"):
            self.assertIn(api.request("GET", path)[0], (403, 404), path)


class TestAnalyze(unittest.TestCase):
    def test_valid_url_returns_metadata(self):  # acceptance 3
        api = client()
        status, doc, _ = api.json("POST", "/api/video/info", {"url": "https://youtu.be/okvideo0001"})
        self.assertEqual(200, status)
        d = doc["data"]
        self.assertTrue(doc["success"])
        self.assertEqual("Fake video okvideo0001", d["title"])
        self.assertEqual("Fake Channel", d["uploader"])
        self.assertEqual(30, d["duration"])
        self.assertEqual("0:30", d["duration_formatted"])
        self.assertTrue(d["thumbnail"].startswith("https://i.ytimg.com/"))
        self.assertEqual({"mp4": True, "mp3": True}, d["formats"])
        self.assertEqual(["best", "1080p", "720p", "480p", "360p", "240p", "144p"], d["qualities"])
        self.assertEqual([128, 192, 256, 320], d["mp3_bitrates"])
        self.assertEqual("https://www.youtube.com/watch?v=okvideo0001", d["webpage_url"])

    def test_analysis_downloads_nothing(self):
        api = client()
        api.json("POST", "/api/video/info", {"url": "https://youtu.be/okvideo0007"})
        calls = exec_in("app", "cat", "/tmp/fake-ytdlp-calls.log")
        self.assertEqual([], temp_dirs())
        self.assertEqual([], [c for c in calls.splitlines() if "okvideo0007" in c and '"-o"' in c])

    def test_invalid_urls_get_friendly_errors(self):  # acceptance 7
        api = client()
        status, doc, _ = api.json("POST", "/api/video/info", {"url": ""})
        self.assertEqual(400, status)
        self.assertFalse(doc["success"])
        self.assertEqual("INVALID_URL", doc["error"]["code"])
        self.assertEqual("Please enter a valid YouTube URL.", doc["error"]["message"])
        for bad in ("not a url", "https://www.youtube.com/watch?v=short", "https://www.youtube.com/playlist?list=PL123", {"x": 1}, None, 123):
            status, doc, _ = api.json("POST", "/api/video/info", {"url": bad})
            self.assertEqual(400, status, bad)
            self.assertEqual("INVALID_URL", doc["error"]["code"], bad)

    def test_unsupported_domains_and_ssrf_are_rejected(self):  # acceptance 9
        api = client()
        for url in ("https://vimeo.com/12345", "http://localhost/", "http://127.0.0.1:9000/", "http://0.0.0.0/", "http://10.0.0.1/",
                    "http://192.168.0.1/", "http://169.254.169.254/latest/meta-data/", "http://app:9000/", "http://[::1]/",
                    "https://www.youtube.com.evil.example/watch?v=dQw4w9WgXcQ", "https://www.youtube.com@evil.example/watch?v=dQw4w9WgXcQ"):
            status, doc, _ = api.json("POST", "/api/video/info", {"url": url})
            self.assertEqual(400, status, url)
            self.assertIn(doc["error"]["code"], ("UNSUPPORTED_DOMAIN", "INVALID_URL"), url)
        for url in ("file:///etc/passwd", "ftp://youtube.com/x", "data:text/html,hi", "javascript:alert(1)"):
            status, doc, _ = api.json("POST", "/api/video/info", {"url": url})
            self.assertEqual(400, status, url)
            self.assertEqual("INVALID_URL", doc["error"]["code"], url)

    def test_shell_metacharacters_never_reach_the_engine(self):
        api = client()
        before = exec_in("app", "cat", "/tmp/fake-ytdlp-calls.log").count("\n")
        for url in ("https://youtu.be/dQw4w9WgXcQ;id", "https://youtu.be/dQw4w9WgXcQ|id", "https://youtu.be/$(id)", "https://youtu.be/`id`", "--exec=id"):
            status, _, _ = api.json("POST", "/api/video/info", {"url": url})
            self.assertEqual(400, status, url)
        after = exec_in("app", "cat", "/tmp/fake-ytdlp-calls.log").count("\n")
        self.assertEqual(before, after, "yt-dlp must not have been started for rejected input")

    def test_excessive_duration_is_rejected(self):  # acceptance 10
        api = client()
        status, doc, _ = api.json("POST", "/api/video/info", {"url": "https://youtu.be/longvideo01"})
        self.assertEqual(422, status)
        self.assertEqual("VIDEO_TOO_LONG", doc["error"]["code"])
        # the analysis result is cached, so even a direct download request is refused immediately
        status, doc, _ = api.json("POST", "/api/download", {"url": "https://youtu.be/longvideo01", "format": "mp3"})
        self.assertEqual(422, status)
        self.assertEqual("VIDEO_TOO_LONG", doc["error"]["code"])

    def test_unavailable_and_live(self):
        api = client()
        status, doc, _ = api.json("POST", "/api/video/info", {"url": "https://youtu.be/privvideo01"})
        self.assertEqual(422, status)
        self.assertEqual("VIDEO_UNAVAILABLE", doc["error"]["code"])
        self.assertEqual("The requested video could not be downloaded.", doc["error"]["message"])
        status, doc, _ = api.json("POST", "/api/video/info", {"url": "https://youtu.be/livevideo01"})
        self.assertEqual(422, status)
        self.assertEqual("VIDEO_UNAVAILABLE", doc["error"]["code"])


class TestSearch(unittest.TestCase):
    def test_search_returns_downloadable_results(self):
        api = client()
        status, doc, _ = api.json("POST", "/api/search", {"query": "  never   gonna  "})
        self.assertEqual(200, status)
        self.assertEqual("never gonna", doc["data"]["query"])
        results = doc["data"]["results"]
        self.assertEqual(4, len(results), "the live stream result is filtered out")
        first = results[0]
        for key in ("video_id", "title", "uploader", "duration", "duration_formatted", "thumbnail", "webpage_url"):
            self.assertIn(key, first)
        self.assertEqual("Result 1 for never gonna", first["title"])
        self.assertEqual("3:00", first["duration_formatted"])
        self.assertTrue(first["thumbnail"].startswith("https://i.ytimg.com/vi/"))
        self.assertEqual("https://www.youtube.com/watch?v=okvideo0001", first["webpage_url"])
        self.assertNotIn("livevideo01", json.dumps(results))

    def test_a_result_can_be_downloaded_straight_away(self):
        api = client()
        result = api.json("POST", "/api/search", {"query": "song"})[1]["data"]["results"][0]
        job_id = analyze_and_download(api, result["video_id"], "mp3", bitrate=192)
        self.assertEqual("completed", api.wait_for(job_id)["status"])

    def test_empty_results_and_errors(self):
        api = client()
        self.assertEqual([], api.json("POST", "/api/search", {"query": "nothingfound"})[1]["data"]["results"])
        for bad in ("", "   ", None, 5, "x" * 101):
            status, doc, _ = api.json("POST", "/api/search", {"query": bad})
            self.assertEqual(400, status, bad)
            self.assertEqual("INVALID_QUERY", doc["error"]["code"])
        status, doc, _ = api.json("POST", "/api/search", {"query": "failsearch"})
        self.assertEqual(502, status)
        self.assertEqual("Unable to connect. Please check your internet connection.", doc["error"]["message"])
        self.assertEqual(404, api.json("GET", "/api/search")[0])

    def test_search_is_rate_limited_and_never_leaks_option_injection(self):
        api = client()
        before = exec_in("app", "cat", "/tmp/fake-ytdlp-calls.log").count("\n")
        status, doc, _ = api.json("POST", "/api/search", {"query": "--exec id; $(id)"})
        self.assertEqual(200, status)
        calls = [json.loads(l) for l in exec_in("app", "cat", "/tmp/fake-ytdlp-calls.log").splitlines()[before:]]
        args = calls[-1]["args"] if calls else []
        self.assertEqual("--", args[-2])
        self.assertEqual("ytsearch10:--exec id; $(id)", args[-1])
        codes = [api.json("POST", "/api/search", {"query": "q"})[0] for _ in range(21)]
        self.assertEqual(429, codes[-1])


class TestRequestHandling(unittest.TestCase):
    def test_body_size_content_type_and_json(self):
        api = client()
        status, doc, _ = api.json("POST", "/api/video/info", None, raw=json.dumps({"url": "x" * 9000}).encode(), headers={"Content-Type": "application/json"})
        self.assertEqual(413, status)
        self.assertEqual("REQUEST_TOO_LARGE", doc["error"]["code"])
        status, _, _ = api.request("POST", "/api/video/info", raw=b"y" * 40000, headers={"Content-Type": "application/json"})
        self.assertEqual(413, status, "nginx rejects bodies above 16k before PHP sees them")
        status, doc, _ = api.json("POST", "/api/video/info", None, raw=b'{"url":"x"}', headers={"Content-Type": "text/plain"})
        self.assertEqual(415, status)
        status, doc, _ = api.json("POST", "/api/video/info", None, raw=b"{not json", headers={"Content-Type": "application/json"})
        self.assertEqual(400, status)
        self.assertEqual("INVALID_REQUEST", doc["error"]["code"])
        status, doc, _ = api.json("POST", "/api/video/info", None, raw=b"[1,2]", headers={"Content-Type": "application/json"})
        self.assertEqual(400, status)

    def test_unknown_routes_and_wrong_methods_return_json_errors(self):
        api = client()
        for method, path in (("GET", "/api/nope"), ("GET", "/api/video/info"), ("DELETE", "/api/download"), ("PUT", "/api/health"), ("POST", "/api/health")):
            status, doc, headers = api.json(method, path)
            self.assertIn(status, (404, 405), (method, path))
            self.assertFalse(doc["success"])
            self.assertTrue(headers["Content-Type"].startswith("application/json"))

    def test_errors_never_leak_internals(self):
        api = client()
        for path in ("/api/download/..%2f..%2fetc%2fpasswd", "/api/download/%00", "/api/download/" + "a" * 5000):
            status, raw_headers, body = api.request("GET", path)
            text = body.decode(errors="replace")
            for needle in ("/var/www", "storage", "Stack trace", "Fatal error", "Warning:", ".php"):
                self.assertNotIn(needle, text, path)

    def test_rate_limit_on_analyze(self):
        api = client()
        codes = [api.json("POST", "/api/video/info", {"url": "https://youtu.be/okvideo0001"})[0] for _ in range(22)]
        self.assertEqual([200] * 20, codes[:20])
        self.assertEqual([429, 429], codes[20:])
        status, doc, headers = api.json("POST", "/api/video/info", {"url": "https://youtu.be/okvideo0001"})
        self.assertEqual("RATE_LIMITED", doc["error"]["code"])
        self.assertTrue(int(headers["Retry-After"]) >= 1)
        # another client is not affected
        self.assertEqual(200, client().json("POST", "/api/video/info", {"url": "https://youtu.be/okvideo0001"})[0])

    def test_rate_limit_on_download(self):
        api = client()
        ids = []
        for i in range(5):
            status, doc, _ = api.json("POST", "/api/download", {"url": "https://youtu.be/okvideo000" + str(i), "format": "mp3"})
            self.assertEqual(202, status)
            ids.append(doc["job_id"])
        status, doc, _ = api.json("POST", "/api/download", {"url": "https://youtu.be/okvideo0009", "format": "mp3"})
        self.assertEqual(429, status)
        self.assertEqual("RATE_LIMITED", doc["error"]["code"])
        for jid in ids:
            api.json("POST", f"/api/download/{jid}/cancel", {})

    def test_invalid_download_parameters(self):
        api = client()
        url = "https://youtu.be/okvideo0001"
        cases = [({"url": url}, "INVALID_FORMAT"), ({"url": url, "format": "exe"}, "INVALID_FORMAT"),
                 ({"url": url, "format": "mp4", "quality": "9999p"}, "INVALID_QUALITY"), ({"url": url, "format": "mp4", "quality": "720p;ls"}, "INVALID_QUALITY"),
                 ({"url": url, "format": "mp3", "bitrate": 500}, "INVALID_BITRATE"), ({"url": "", "format": "mp4"}, "INVALID_URL"),
                 ({"url": "http://localhost", "format": "mp4"}, "UNSUPPORTED_DOMAIN")]
        for body, code in cases:
            status, doc, _ = api.json("POST", "/api/download", body)
            self.assertEqual(400, status, body)
            self.assertEqual(code, doc["error"]["code"], body)

    def test_job_ids_are_validated_and_traversal_is_rejected(self):  # acceptance 8
        api = client()
        for bad in ("abc123", "..", "%2e%2e", "..%2f..%2fetc%2fpasswd", "....//....//etc/passwd", "A" * 32, "g" * 32):
            for suffix in ("", "/file", "/cancel"):
                method = "POST" if suffix == "/cancel" else "GET"
                status, doc, _ = api.json(method, f"/api/download/{bad}{suffix}", {} if method == "POST" else None)
                self.assertIn(status, (400, 403, 404), (bad, suffix, status))
                self.assertFalse(doc["success"] if doc else False)
        status, doc, _ = api.json("GET", "/api/download/" + "0" * 32)
        self.assertEqual(404, status)
        self.assertEqual("JOB_NOT_FOUND", doc["error"]["code"])
        status, doc, _ = api.json("GET", "/api/download/../../etc/passwd")
        self.assertIn(status, (400, 404))
        status, doc, _ = api.json("DELETE", "/api/download/history/..%2f..%2fetc")
        self.assertIn(status, (400, 404))


class TestDownloads(unittest.TestCase):
    def test_mp4_download_end_to_end(self):  # acceptance 4
        api = client()
        job_id = analyze_and_download(api, "weirdtitle1", "mp4", quality="720p")
        job = api.wait_for(job_id)
        self.assertEqual("completed", job["status"], job)
        self.assertEqual(100, job["progress"])
        self.assertEqual("My Video - Episode 1 - Test.mp4", job["filename"])
        status, headers, body = api.request("GET", f"/api/download/{job_id}/file")
        self.assertEqual(200, status)
        self.assertEqual("video/mp4", headers["Content-Type"])
        self.assertIn('attachment; filename="My Video - Episode 1 - Test.mp4"', headers["Content-Disposition"])
        self.assertEqual(len(body), int(headers["Content-Length"]))
        self.assertEqual(b"ftyp", body[4:8], "valid MP4 container")
        self.assertEqual([], [t for t in temp_dirs() if t == job_id], "temp dir removed after completion")
        calls = exec_in("worker", "cat", "/tmp/fake-ytdlp-calls.log")
        self.assertRegex(calls, r"res:720,vcodec:avc1")  # quality selection reached the engine

    def test_mp3_download_end_to_end(self):  # acceptance 5
        api = client()
        job_id = analyze_and_download(api, "okmp3video1", "mp3", bitrate=128)
        job = api.wait_for(job_id)
        self.assertEqual("completed", job["status"], job)
        self.assertTrue(job["filename"].endswith(".mp3"))
        status, headers, body = api.request("GET", f"/api/download/{job_id}/file")
        self.assertEqual(200, status)
        self.assertEqual("audio/mpeg", headers["Content-Type"])
        self.assertTrue(body[:3] == b"ID3" or body[0] == 0xFF, "MPEG audio data")
        self.assertIn("128K", exec_in("worker", "cat", "/tmp/fake-ytdlp-calls.log"))

    def test_status_progression_and_fields(self):
        api = client()
        job_id = analyze_and_download(api, "conc0000010", "mp4")
        seen, progress = set(), []

        def on_poll(job):
            seen.add(job["status"])
            progress.append(job["progress"])
            for key in ("job_id", "status", "progress", "speed", "eta", "downloaded", "total", "message", "indeterminate"):
                self.assertIn(key, job)
            self.assertNotIn("client_id", job)
            self.assertNotIn("pid", job)

        job = api.wait_for(job_id, on_poll=on_poll)
        self.assertEqual("completed", job["status"])
        self.assertIn("downloading", seen)
        self.assertEqual(sorted(progress), progress, "progress is monotonic")
        self.assertTrue(any(0 < p < 100 for p in progress), "real intermediate progress, not just 0 -> 100")

    def test_file_endpoint_authorisation(self):
        api = client()
        slow = analyze_and_download(api, "slowvideo11", "mp4")
        wait_status(api, slow, "downloading")
        status, doc, _ = api.json("GET", f"/api/download/{slow}/file")
        self.assertEqual(409, status)
        self.assertEqual("JOB_NOT_READY", doc["error"]["code"])
        api.json("POST", f"/api/download/{slow}/cancel", {})
        status, doc, _ = api.json("GET", f"/api/download/{slow}/file")
        self.assertEqual(409, status)
        self.assertEqual("JOB_CANCELLED", doc["error"]["code"])
        status, doc, _ = api.json("GET", "/api/download/" + "f" * 32 + "/file")
        self.assertEqual("JOB_NOT_FOUND", doc["error"]["code"])

    def test_failed_download_reports_friendly_error_and_cleans_up(self):
        api = client()
        job_id = analyze_and_download(api, "failvideo01", "mp4")
        job = api.wait_for(job_id)
        self.assertEqual("failed", job["status"])
        self.assertEqual("DOWNLOAD_FAILED", job["error"]["code"])
        self.assertEqual("The requested video could not be downloaded.", job["error"]["message"])
        self.assertNotIn("403", json.dumps(job))
        self.assertNotIn(job_id, temp_dirs())
        status, doc, _ = api.json("GET", f"/api/download/{job_id}/file")
        self.assertEqual(409, status)

    def test_size_limits(self):  # acceptance 11
        api = client()
        big = api.wait_for(analyze_and_download(api, "bigvideo001", "mp4"))
        self.assertEqual("failed", big["status"])
        self.assertEqual("FILE_TOO_LARGE", big["error"]["code"])
        unknown = api.wait_for(analyze_and_download(api, "unszvideo01", "mp4"), timeout=60)
        self.assertEqual("failed", unknown["status"])
        self.assertEqual("FILE_TOO_LARGE", unknown["error"]["code"])
        self.assertNotIn(unknown["job_id"], temp_dirs())
        self.assertNotIn("unszvideo01", exec_in("worker", "ps", "-eo", "args"))

    def test_worker_rejects_overlong_video_when_not_pre_analysed(self):
        api = client()
        # the info cache would catch this at create time; bypass by using a fresh id the cache has not seen
        job = api.wait_for(analyze_and_download(api, "longvideo02", "mp3"))
        self.assertEqual("failed", job["status"])
        self.assertEqual("VIDEO_TOO_LONG", job["error"]["code"])

    def test_concurrency_limit_and_queue(self):
        api = client()
        ids = [analyze_and_download(api, f"conc00000{i}1", "mp4") for i in range(3)]
        max_active, saw_queued = 0, False
        deadline = time.time() + 90
        while time.time() < deadline:
            states = [api.json("GET", f"/api/download/{i}")[1]["data"]["status"] for i in ids]
            max_active = max(max_active, sum(s in ("analyzing", "downloading", "processing") for s in states))
            saw_queued = saw_queued or "queued" in states
            if all(s == "completed" for s in states):
                break
            time.sleep(0.3)
        self.assertEqual(["completed"] * 3, states)
        self.assertEqual(2, max_active, "MAX_CONCURRENT_DOWNLOADS=2 in the test stack")
        self.assertTrue(saw_queued)


class TestCancellation(unittest.TestCase):
    def test_cancel_stops_processes_and_cleans_temp(self):  # acceptance 6
        api = client()
        a = analyze_and_download(api, "slowvideo21", "mp4")
        b = analyze_and_download(api, "slowvideo22", "mp4")
        wait_status(api, a, "downloading")
        wait_status(api, b, "downloading")
        time.sleep(1.5)
        ps = exec_in("worker", "ps", "-eo", "pid,args")
        self.assertEqual(2, ps.count("slowvideo2"), ps)
        self.assertEqual(2, ps.count("sleep 120"), ps)
        self.assertIn(a, temp_dirs())

        status, doc, _ = api.json("POST", f"/api/download/{a}/cancel", {})
        self.assertEqual(200, status)
        self.assertEqual("cancelled", doc["data"]["status"])

        ps = exec_in("worker", "ps", "-eo", "pid,args")
        self.assertEqual(1, ps.count("slowvideo2"), "only the targeted job's process is gone:\n" + ps)
        self.assertEqual(1, ps.count("sleep 120"), "no orphaned child process:\n" + ps)
        self.assertNotIn(a, temp_dirs())
        self.assertIn(b, temp_dirs())
        self.assertEqual("downloading", api.json("GET", f"/api/download/{b}")[1]["data"]["status"])

        api.json("POST", f"/api/download/{b}/cancel", {})
        ps = exec_in("worker", "ps", "-eo", "pid,args")
        self.assertEqual(0, ps.count("slowvideo2"))
        self.assertEqual(0, ps.count("sleep 120"))
        self.assertEqual([], temp_dirs())
        # idempotent
        self.assertEqual("cancelled", api.json("POST", f"/api/download/{b}/cancel", {})[1]["data"]["status"])

    def test_queued_job_can_be_cancelled(self):
        api = client()
        blockers = [analyze_and_download(api, "slowvideo31", "mp4"), analyze_and_download(api, "slowvideo32", "mp4")]
        for b in blockers:
            wait_status(api, b, "downloading")
        queued = analyze_and_download(api, "okvideo0003", "mp3")
        self.assertEqual("queued", api.json("GET", f"/api/download/{queued}")[1]["data"]["status"])
        status, doc, _ = api.json("POST", f"/api/download/{queued}/cancel", {})
        self.assertEqual("cancelled", doc["data"]["status"])
        for b in blockers:
            api.json("POST", f"/api/download/{b}/cancel", {})

    def test_completed_job_cannot_be_cancelled_and_file_is_preserved(self):
        api = client()
        job = api.wait_for(analyze_and_download(api, "okvideo0004", "mp3"))
        status, doc, _ = api.json("POST", f"/api/download/{job['job_id']}/cancel", {})
        self.assertEqual(409, status)
        self.assertEqual("JOB_NOT_CANCELLABLE", doc["error"]["code"])
        self.assertEqual(200, api.request("GET", f"/api/download/{job['job_id']}/file")[0])


class TestHistory(unittest.TestCase):
    def test_history_is_private_to_the_browser_and_deletable(self):
        mine, other = client(), client()
        job = mine.wait_for(analyze_and_download(mine, "okvideo0005", "mp3"))
        status, doc, _ = mine.json("GET", "/api/download/history")
        items = doc["data"]["items"]
        self.assertEqual([job["job_id"]], [i["job_id"] for i in items])
        item = items[0]
        for key in ("filename", "title", "format", "created_at", "status", "filesize", "file_available"):
            self.assertIn(key, item)
        self.assertTrue(item["file_available"])
        self.assertNotIn("/var/www", json.dumps(items))
        self.assertEqual([], other.json("GET", "/api/download/history")[1]["data"]["items"], "other browsers see nothing")

        status, doc, _ = other.json("DELETE", f"/api/download/history/{job['job_id']}")
        self.assertEqual(404, status, "cannot delete someone else's entry")
        self.assertEqual(200, mine.request("GET", f"/api/download/{job['job_id']}/file")[0])

        status, doc, _ = mine.json("DELETE", f"/api/download/history/{job['job_id']}")
        self.assertEqual(200, status)
        self.assertEqual(404, mine.json("GET", f"/api/download/{job['job_id']}/file")[0])
        self.assertEqual([], mine.json("GET", "/api/download/history")[1]["data"]["items"])

    def test_active_job_cannot_be_deleted_from_history(self):
        api = client()
        job_id = analyze_and_download(api, "slowvideo41", "mp3")
        wait_status(api, job_id, "downloading")
        status, doc, _ = api.json("DELETE", f"/api/download/history/{job_id}")
        self.assertEqual(409, status)
        self.assertEqual("JOB_ACTIVE", doc["error"]["code"])
        api.json("POST", f"/api/download/{job_id}/cancel", {})


class TestCleanupAndRecovery(unittest.TestCase):
    def test_scheduled_cleanup_removes_abandoned_data_but_keeps_completed_files(self):  # cleanup
        api = client()
        done = api.wait_for(analyze_and_download(api, "okvideo0006", "mp3"))
        orphan = "".join(random.choice("0123456789abcdef") for _ in range(32))
        script = (
            f"mkdir -p /var/www/storage/temp/{orphan} /var/www/storage/downloads/{orphan} && "
            f"echo x > /var/www/storage/temp/{orphan}/part.bin && echo x > /var/www/storage/downloads/{orphan}/x.mp4 && "
            f"touch -d '3 days ago' /var/www/storage/downloads/{orphan}"
        )
        exec_in("worker", "sh", "-c", script)
        self.assertIn(orphan, temp_dirs())
        deadline = time.time() + 30  # CLEANUP_INTERVAL_SECONDS=5 in the test stack
        while time.time() < deadline and orphan in temp_dirs():
            time.sleep(1)
        self.assertNotIn(orphan, temp_dirs(), "orphaned temp dir removed by the worker's scheduled cleanup")
        listing = exec_in("worker", "sh", "-c", "ls /var/www/storage/downloads")
        self.assertNotIn(orphan, listing, "orphaned download dir (no job record) removed")
        self.assertIn(done["job_id"], listing, "completed downloads are kept")
        self.assertEqual(200, api.request("GET", f"/api/download/{done['job_id']}/file")[0])
        log = exec_in("worker", "sh", "-c", "cat /var/www/storage/logs/app-*.log | tail -50")
        self.assertIn('"op":"cleanup"', log)

    def test_graceful_shutdown_requeues_running_jobs_and_leaves_nothing_behind(self):
        api = client()
        job_id = analyze_and_download(api, "slowvideo51", "mp4")
        wait_status(api, job_id, "downloading")
        started = time.time()
        compose("stop", "-t", "30", "worker", timeout=90)
        self.assertLess(time.time() - started, 25, "worker handled SIGTERM promptly")
        inspect = subprocess.run(["docker", "inspect", "-f", "{{.State.ExitCode}}", f"{PROJECT}-worker-1"], capture_output=True, text=True).stdout.strip()
        self.assertEqual("0", inspect, "clean exit on SIGTERM")
        job = api.json("GET", f"/api/download/{job_id}")[1]["data"]
        self.assertEqual("queued", job["status"], "interrupted job is queued again, not stuck")
        compose("start", "worker")
        job = wait_status(api, job_id, "downloading", timeout=60)
        self.assertEqual("downloading", job["status"], "job resumes after restart")
        api.json("POST", f"/api/download/{job_id}/cancel", {})

    def test_crash_recovery_after_sigkill(self):
        api = client()
        job_id = analyze_and_download(api, "slowvideo61", "mp4")
        wait_status(api, job_id, "downloading")
        compose("kill", "-s", "KILL", "worker")
        self.assertEqual("downloading", api.json("GET", f"/api/download/{job_id}")[1]["data"]["status"], "stuck until the worker restarts")
        compose("start", "worker")
        deadline = time.time() + 40
        status = None
        while time.time() < deadline:
            status = api.json("GET", f"/api/download/{job_id}")[1]["data"]["status"]
            if status in ("queued", "analyzing", "downloading"):
                break
            time.sleep(0.5)
        self.assertIn(status, ("queued", "analyzing", "downloading"), "job was recovered, not stuck forever")
        api.json("POST", f"/api/download/{job_id}/cancel", {})
        deadline = time.time() + 20
        while time.time() < deadline and temp_dirs():
            time.sleep(0.5)
        self.assertEqual([], temp_dirs())


if __name__ == "__main__":
    unittest.main(verbosity=2)
