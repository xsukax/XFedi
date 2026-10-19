#!/usr/bin/env python3
"""XFedi job supervisor. PHP owns protocol validation and all network requests.

No Python dependencies, duplicated signing code, or second public HTTP service.
Each bounded PHP run is isolated; SIGTERM gracefully stops the current run.
"""
import argparse
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent
stopping = False
current = None

def stop(_signal, _frame):
    global stopping
    stopping = True
    if current is not None:
        current.terminate()

def run_once(refresh=False):
    global current
    command = [os.environ.get('PHP_BINARY', 'php'), str(ROOT / 'index.php'), 'worker']
    if refresh:
        command.append('refresh')
    current = subprocess.Popen(command)
    try:
        return current.wait(timeout=600)
    except subprocess.TimeoutExpired:
        current.terminate()
        try:
            current.wait(timeout=10)
        except subprocess.TimeoutExpired:
            current.kill()
            current.wait()
        print(json.dumps({'event': 'worker_timeout'}), file=sys.stderr, flush=True)
        return 1
    finally:
        current = None

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--interval', type=float, default=10)
    args = parser.parse_args()
    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    last_refresh = -600.0
    while not stopping:
        refresh = time.monotonic() - last_refresh >= 600
        status = run_once(refresh)
        if args.once:
            return status
        if refresh:
            last_refresh = time.monotonic()
        if status:
            print(json.dumps({'event': 'worker_failed', 'exit': status}), file=sys.stderr, flush=True)
        else:
            data = Path(os.environ.get('XFEDI_DATA_DIR', '/var/lib/xsukax-fediverse'))
            heartbeat = data / 'worker-heartbeat'
            heartbeat.write_text(str(int(time.time())), encoding='ascii')
            heartbeat.chmod(0o600)
        deadline = time.monotonic() + max(1, args.interval)
        while not stopping and time.monotonic() < deadline:
            time.sleep(min(0.5, max(0, deadline - time.monotonic())))
    return 0

if __name__ == '__main__':
    sys.exit(main())
