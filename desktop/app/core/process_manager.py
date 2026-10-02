"""Tracks child processes by job id and stops exactly the right process tree.

yt-dlp spawns FFmpeg, so cancelling must kill the whole tree of *that job only* (never every
yt-dlp/ffmpeg on the machine). Windows: ``taskkill /T /F /PID``; POSIX: process-group signals.
"""
from __future__ import annotations

import logging
import os
import signal
import subprocess
import sys
import threading

log = logging.getLogger("ytd.process")

_WINDOWS = sys.platform == "win32"
_CREATE_NO_WINDOW = 0x08000000
_CREATE_NEW_PROCESS_GROUP = 0x00000200


class ProcessManager:
    def __init__(self) -> None:
        self._procs: dict[str, subprocess.Popen] = {}
        self._lock = threading.Lock()

    def start(self, job_id: str, command: list[str], *, cwd: str | None = None, env: dict[str, str] | None = None) -> subprocess.Popen:
        """Start a process from an argument list (never a shell string). stderr is merged into stdout."""
        kwargs: dict = {}
        if _WINDOWS:
            kwargs["creationflags"] = _CREATE_NO_WINDOW | _CREATE_NEW_PROCESS_GROUP
        else:
            kwargs["start_new_session"] = True
        proc = subprocess.Popen(  # noqa: S603 - argument list, shell=False
            command,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            cwd=cwd,
            env=env,
            shell=False,
            **kwargs,
        )
        with self._lock:
            self._procs[job_id] = proc
        return proc

    def is_running(self, job_id: str) -> bool:
        with self._lock:
            proc = self._procs.get(job_id)
        return proc is not None and proc.poll() is None

    def active_ids(self) -> list[str]:
        with self._lock:
            return [j for j, p in self._procs.items() if p.poll() is None]

    def terminate(self, job_id: str) -> bool:
        """Kill the process tree of one job. Returns True if a running process was signalled."""
        with self._lock:
            proc = self._procs.get(job_id)
        if proc is None or proc.poll() is not None:
            return False
        self._kill_tree(proc)
        return True

    def release(self, job_id: str) -> None:
        with self._lock:
            proc = self._procs.pop(job_id, None)
        if proc is not None and proc.stdout is not None:
            try:
                proc.stdout.close()
            except OSError:
                pass

    def kill_all(self) -> None:
        """Called on application exit: no orphaned processes may survive us."""
        for job_id in self.active_ids():
            self.terminate(job_id)
        for job_id in list(self._procs):
            self.release(job_id)

    @staticmethod
    def _kill_tree(proc: subprocess.Popen) -> None:
        try:
            if _WINDOWS:
                subprocess.run(  # noqa: S603,S607 - fixed program, numeric pid
                    ["taskkill", "/T", "/F", "/PID", str(proc.pid)],
                    stdout=subprocess.DEVNULL,
                    stderr=subprocess.DEVNULL,
                    creationflags=_CREATE_NO_WINDOW,
                    check=False,
                    timeout=15,
                )
            else:
                os.killpg(os.getpgid(proc.pid), signal.SIGKILL)
        except (OSError, subprocess.SubprocessError, ProcessLookupError):
            try:
                proc.kill()
            except OSError:
                pass
        try:
            proc.wait(timeout=10)
        except subprocess.TimeoutExpired:
            log.warning("process %s did not exit after kill", proc.pid)
