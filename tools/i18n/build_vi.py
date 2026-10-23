#!/usr/bin/env python3
"""
Builds languages/semantic-seo-engine-vi-en-vi.po from the POT and the translation tables in
vi_part*.py, then checks coverage and printf-placeholder consistency. Compile the .mo with
`wp i18n make-mo languages/` afterwards.

Requires: polib (pip install polib). Exit code 1 on missing translations or placeholder mismatch.
"""

import os
import re
import sys

import polib

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
POT = os.path.join(ROOT, "languages", "semantic-seo-engine-vi-en.pot")
PO = os.path.join(ROOT, "languages", "semantic-seo-engine-vi-en-vi.po")
PLACEHOLDER = re.compile(r"%(?:\d+\$)?[sdf]")

sys.path.insert(0, HERE)
from vi_part1 import T as T1  # noqa: E402
from vi_part2 import T as T2  # noqa: E402
from vi_part3 import T as T3, PLURALS  # noqa: E402
from vi_part4 import T as T4, PLURALS as PLURALS4  # noqa: E402

TABLE = {}
for table in (T1, T2, T3, T4):
    TABLE.update(table)
PLURALS = dict(PLURALS, **PLURALS4)

BLOCK_STRINGS = [
    ("block title", "Related content (Semantic SEO)"),
    ("block description", "Precomputed related articles from Semantic SEO Engine VI–EN. Renders nothing until related content output is enabled in the plugin settings."),
]


def placeholders(s):
    return sorted(PLACEHOLDER.findall(s))


def main():
    pot = polib.pofile(POT)
    for ctx, msgid in BLOCK_STRINGS:
        if not pot.find(msgid, msgctxt=ctx):
            pot.append(polib.POEntry(msgctxt=ctx, msgid=msgid, msgstr="", occurrences=[("blocks/related-content/block.json", "")]))
    pot.save(POT)

    po = polib.POFile(wrapwidth=0)
    po.metadata = {
        "Project-Id-Version": pot.metadata.get("Project-Id-Version", "Semantic SEO Engine VI-EN"),
        "Report-Msgid-Bugs-To": pot.metadata.get("Report-Msgid-Bugs-To", ""),
        "Language": "vi",
        "MIME-Version": "1.0",
        "Content-Type": "text/plain; charset=UTF-8",
        "Content-Transfer-Encoding": "8bit",
        "Plural-Forms": "nplurals=1; plural=0;",
        "X-Domain": "semantic-seo-engine-vi-en",
    }
    missing, bad = [], []
    for e in pot:
        entry = polib.POEntry(msgid=e.msgid, msgctxt=e.msgctxt, occurrences=e.occurrences, comment=e.comment, tcomment=e.tcomment, flags=e.flags)
        if e.msgid_plural:
            tr = PLURALS.get(e.msgid)
            entry.msgid_plural = e.msgid_plural
            if tr is None:
                missing.append(e.msgid)
                tr = ""
            entry.msgstr_plural = {0: tr}
            if tr and placeholders(tr) != placeholders(e.msgid):
                bad.append(e.msgid)
        else:
            tr = TABLE.get(e.msgid)
            if tr is None:
                missing.append(e.msgid)
                tr = ""
            entry.msgstr = tr
            if tr and placeholders(tr) != placeholders(e.msgid):
                bad.append(e.msgid)
        po.append(entry)
    po.save(PO)
    unused = sorted(set(TABLE) - {e.msgid for e in pot})
    print("entries: %d, translated: %d, missing: %d, placeholder mismatches: %d, unused table keys: %d" % (len(pot), len(pot) - len(missing), len(missing), len(bad), len(unused)))
    for m in missing:
        print("MISSING:", repr(m))
    for b in bad:
        print("PLACEHOLDER MISMATCH:", repr(b))
    for u in unused:
        print("UNUSED:", repr(u))
    return 1 if missing or bad else 0


if __name__ == "__main__":
    sys.exit(main())
