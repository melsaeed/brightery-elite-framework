#!/usr/bin/env python3
"""Bounded HTTP baseline sampler, *not* a substitute for Nginx/PHP-FPM load testing."""
import argparse
import concurrent.futures
import json
import statistics
import time
import urllib.error
import urllib.request

parser=argparse.ArgumentParser()
parser.add_argument('--url',default='http://127.0.0.1:8787/health')
parser.add_argument('--requests',type=int,default=80)
parser.add_argument('--workers',type=int,default=8)
args=parser.parse_args()
if args.requests<1 or args.requests>10000 or args.workers<1 or args.workers>64:
    parser.error('Use 1-10000 requests and 1-64 workers')
if not args.url.startswith(('http://127.0.0.1:', 'http://localhost:', 'https://127.0.0.1:', 'https://localhost:')):
    parser.error('Only localhost URLs permitted by default (avoids accidentally load-testing external services)')

def request(_):
    start=time.perf_counter()
    try:
        with urllib.request.urlopen(urllib.request.Request(args.url,headers={'Accept':'application/json'}),timeout=5) as response:
            response.read(10240)
            return response.status,(time.perf_counter()-start)*1000
    except urllib.error.HTTPError as error:
        return error.code,(time.perf_counter()-start)*1000
    except (TimeoutError, OSError):
        return 0,(time.perf_counter()-start)*1000

def percentile(sorted_values,p):
    return sorted_values[max(0,min(len(sorted_values)-1,round((len(sorted_values)-1)*p)))]
start=time.perf_counter()
with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as executor:
    results=list(executor.map(request,range(args.requests)))
elapsed=time.perf_counter()-start
latencies=sorted(ms for _,ms in results)
statuses={}
for code,_ in results: statuses[str(code)]=statuses.get(str(code),0)+1
summary={'requests':args.requests,'workers':args.workers,'duration_s':round(elapsed,3),'rps_observed':round(args.requests/max(elapsed,1e-8),2),'p50_ms':round(statistics.median(latencies),2),'p95_ms':round(percentile(latencies,.95),2),'p99_ms':round(percentile(latencies,.99),2),'statuses':statuses}
print(json.dumps(summary,indent=2))
if any(code!=200 for code,_ in results): raise SystemExit(1)
