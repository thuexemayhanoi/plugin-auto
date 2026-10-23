#!/usr/bin/env python3
"""Read-only export of a public WordPress site's published content through its public REST API
(CI only, not shipped). Used by the read-only pilot to analyse a disposable COPY of the content.

GET only, sequential, paused, identified by its User-Agent. Only what any anonymous visitor can
read is requested (published posts, pages and categories). The export stays on the CI runner and
is never uploaded: the pilot publishes aggregate numbers only.

Usage: python -I tests/site/export_public.py --site https://blog.rentbikehanoi.com --out site.json
"""
import argparse
import json
import sys
import time
import urllib.error
import urllib.request

UA = "SemanticSEO-Engine-ReadOnly-Pilot/1.0 (+https://github.com/thuexemayhanoi/plugin-auto; owner-authorized, GET only)"
PAUSE = 1.0
FIELDS = {
    "categories": "id,name,slug,parent,description,link,count",
    "posts": "id,date_gmt,modified_gmt,slug,status,type,link,title,content,excerpt,categories,yoast_head_json",
    "pages": "id,date_gmt,modified_gmt,slug,status,type,link,title,content,excerpt,parent,menu_order,yoast_head_json",
}


def fetch_all(site, coll, log):
    out = []
    page = 1
    while True:
        time.sleep(PAUSE)
        url = "%s/wp-json/wp/v2/%s?per_page=100&page=%d&orderby=id&order=asc&_fields=%s" % (site, coll, page, FIELDS[coll])
        req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=120) as res:
                items = json.load(res)
                total_pages = int(res.headers.get("X-WP-TotalPages", "1") or 1)
        except urllib.error.HTTPError as e:
            if e.code == 400 and page > 1:
                break
            raise
        log("%s page %d/%d: %d items" % (coll, page, total_pages, len(items)))
        out.extend(items)
        if page >= total_pages or not items:
            break
        page += 1
    return out


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--site", required=True)
    p.add_argument("--out", required=True)
    args = p.parse_args()
    site = args.site.rstrip("/")
    log = lambda m: print(m, flush=True)  # noqa: E731
    data = {"site": site, "when": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}
    for coll in ("categories", "posts", "pages"):
        data[coll] = fetch_all(site, coll, log)
    with open(args.out, "w") as f:
        json.dump(data, f)
    print("exported: %d categories, %d posts, %d pages" % (len(data["categories"]), len(data["posts"]), len(data["pages"])))
    return 0


if __name__ == "__main__":
    sys.exit(main())
