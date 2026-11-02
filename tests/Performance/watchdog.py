#!/usr/bin/env python3
"""Hard external wall-clock limit; run fixtures separately to exclude loading."""
import os
import signal
import subprocess
import sys

if len(sys.argv) < 3:
    sys.exit("Usage: python3 watchdog.py SECONDS COMMAND [ARGS...]")
seconds = float(sys.argv[1])
if seconds <= 0:
    sys.exit("SECONDS must be positive")
process = subprocess.Popen(sys.argv[2:], start_new_session=True)
try:
    sys.exit(process.wait(timeout=seconds))
except subprocess.TimeoutExpired:
    os.killpg(process.pid, signal.SIGKILL)
    process.wait()
    sys.exit("FAIL: external watchdog exceeded %s seconds" % seconds)
