#!/usr/bin/env python3
"""Read-only URL census of a public WordPress site (CI only, not shipped).

Counts the URLs the site itself publishes, using only public, cacheable GET requests:
robots.txt → XML sitemaps (index and children), and the totals of the public REST collections
(X-WP-Total of /wp/v2/posts, /pages, /categories). Nothing is written to the site; requests are
sequential with a pause between them and identify themselves in the User-Agent.

Usage: python -I tests/site/census.py --site https://blog.rentbikehanoi.com --out census.json
"""
import argparse
import collections
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

UA = "SemanticSEO-Engine-ReadOnly-Census/1.0 (+https://github.com/thuexemayhanoi/plugin-auto; owner-authorized, GET only)"
NS = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
PAUSE = 1.0


def get(url, log):
    time.sleep(PAUSE)
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "*/*"})
    try:
        with urllib.request.urlopen(req, timeout=60) as res:
            body = res.read(30 * 1024 * 1024)
            log("GET %s -> %s (%d bytes)" % (url, res.status, len(body)))
            return res.status, dict(res.headers), body
    except urllib.error.HTTPError as e:
        log("GET %s -> %s" % (url, e.code))
        return e.code, dict(e.headers or {}), b""
    except Exception as e:  # noqa: BLE001 - recorded
        log("GET %s -> error %s" % (url, e))
        return 0, {}, b""


def kind_of(sitemap_url):
    name = urllib.parse.urlparse(sitemap_url).path.rsplit("/", 1)[-1]
    m = re.match(r"(?:wp-sitemap-(?:posts|taxonomies|users)-)?([a-z_\-]+?)(?:-sitemap)?\d*\.xml$", name)
    return m.group(1) if m else name


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--site", required=True)
    p.add_argument("--out", required=True)
    args = p.parse_args()
    site = args.site.rstrip("/")
    host = urllib.parse.urlparse(site).netloc
    log = lambda m: print(m, flush=True)  # noqa: E731
    out = {"site": site, "when": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()), "method": "public GET only: robots.txt, XML sitemaps, REST collection totals"}

    status, _, body = get(site + "/robots.txt", log)
    sitemaps = re.findall(r"(?im)^\s*sitemap:\s*(\S+)", body.decode("utf-8", "replace")) if status == 200 else []
    out["robots_txt_status"] = status
    out["robots_sitemaps"] = sitemaps
    if not sitemaps:
        sitemaps = [site + "/sitemap_index.xml", site + "/wp-sitemap.xml"]

    seen = set()
    queue = list(sitemaps)
    by_sitemap = {}
    urls = {}
    while queue and len(seen) < 200:
        sm = queue.pop(0)
        if sm in seen:
            continue
        seen.add(sm)
        status, headers, body = get(sm, log)
        if status != 200 or not body:
            by_sitemap[sm] = {"status": status}
            continue
        try:
            root = ET.fromstring(body)
        except ET.ParseError as e:
            by_sitemap[sm] = {"status": status, "error": "not XML: %s" % e}
            continue
        if root.tag.endswith("sitemapindex"):
            children = [e.text.strip() for e in root.findall("sm:sitemap/sm:loc", NS) if e.text]
            by_sitemap[sm] = {"status": status, "type": "index", "children": len(children)}
            queue.extend(c for c in children if urllib.parse.urlparse(c).netloc == host)
        else:
            locs = [e.text.strip() for e in root.findall("sm:url/sm:loc", NS) if e.text]
            lastmods = [e.text.strip() for e in root.findall("sm:url/sm:lastmod", NS) if e.text]
            k = kind_of(sm)
            by_sitemap[sm] = {"status": status, "type": k, "urls": len(locs), "lastmod_min": min(lastmods) if lastmods else None, "lastmod_max": max(lastmods) if lastmods else None}
            for u in locs:
                urls.setdefault(u, k)
    out["sitemaps"] = by_sitemap

    counts = collections.Counter()
    for u, k in urls.items():
        path = urllib.parse.urlparse(u).path
        lang = "en" if path == "/en/" or path.startswith("/en/") else "vi"
        counts[(k, lang)] += 1
    out["sitemap_urls_total"] = len(urls)
    out["sitemap_urls_by_type_and_language"] = {"%s/%s" % key: n for key, n in sorted(counts.items())}
    out["sitemap_urls_by_language"] = dict(collections.Counter("en" if (urllib.parse.urlparse(u).path.startswith("/en/") or urllib.parse.urlparse(u).path == "/en/") else "vi" for u in urls))

    rest = {}
    for coll in ("posts", "pages", "categories", "tags"):
        status, headers, _ = get("%s/wp-json/wp/v2/%s?per_page=1&_fields=id" % (site, coll), log)
        h = {k.lower(): v for k, v in headers.items()}
        rest[coll] = {"status": status, "total": int(h["x-wp-total"]) if "x-wp-total" in h else None}
    out["rest_totals"] = rest

    with open(args.out, "w") as f:
        json.dump(out, f, indent=1)
    print(json.dumps({k: out[k] for k in ("site", "when", "robots_txt_status", "sitemap_urls_total", "sitemap_urls_by_type_and_language", "sitemap_urls_by_language", "rest_totals")}, indent=1))
    for sm, info in by_sitemap.items():
        print("  %s %s" % (sm, json.dumps(info)))
    return 0 if urls or any(v["total"] for v in rest.values() if v["total"] is not None) else 1


if __name__ == "__main__":
    sys.exit(main())
