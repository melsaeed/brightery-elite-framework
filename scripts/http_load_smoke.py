#!/usr/bin/env python3
"""Small local-only HTTP concurrency smoke test, not a throughput benchmark or DDoS tool."""
import argparse
import concurrent.futures
import json
import math
import statistics
import time
import urllib.error
import urllib.request
from urllib.parse import urlparse


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', default='http://127.0.0.1:8787/health')
    parser.add_argument('--requests', type=int, default=80)
    parser.add_argument('--workers', type=int, default=8)
    parser.add_argument('--max-p95-ms', type=float)
    args = parser.parse_args()
    url = urlparse(args.url)
    if url.scheme != 'http' or url.hostname not in ('localhost', '127.0.0.1', '::1') or url.username or url.password:
        parser.error('Only HTTP loopback URLs are permitted (not production hosts)')
    if not 1 <= args.requests <= 500 or not 1 <= args.workers <= 16:
        parser.error('requests must be 1..500 and workers 1..16')
    if args.max_p95_ms is not None and not 1 <= args.max_p95_ms <= 120000:
        parser.error('maximum p95 must be 1..120000 ms')

    def fetch(_):
        start = time.perf_counter()
        try:
            request = urllib.request.Request(args.url, method='GET', headers={'User-Agent': 'BrighteryCI/1.0'})
            with urllib.request.urlopen(request, timeout=4) as response:
                status = response.status
                request_id = response.headers.get('X-Request-ID', '')
                response.read(4096)
                ok = status == 200 and len(request_id) == 32
                return (time.perf_counter() - start) * 1000, ok, status
        except (urllib.error.URLError, TimeoutError, OSError) as exc:
            return (time.perf_counter() - start) * 1000, False, type(exc).__name__

    started = time.perf_counter()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
        results = list(executor.map(fetch, range(args.requests)))
    latencies = sorted(t[0] for t in results)
    percentile = lambda frac: round(latencies[max(0, math.ceil(len(latencies)*frac)-1)], 2)
    errors = [t[2] for t in results if not t[1]]
    report = {'scope': 'localhost_http_smoke', 'requests': args.requests,
              'workers': args.workers, 'failed': len(errors),
              'p50_ms': percentile(.5), 'p95_ms': percentile(.95),
              'elapsed_seconds': round(time.perf_counter() - started, 3)}
    print(json.dumps(report, indent=2))
    if errors or (args.max_p95_ms is not None and percentile(.95) > args.max_p95_ms):
        raise SystemExit(1)


if __name__ == '__main__':
    main()
