#!/usr/bin/env python3
"""Anonymised ΗΔΙΚΑ prescription corpus for the rx-import regression test.
TEST-ONLY, never shipped.  Reads REAL prescriptions: run it only on a
machine that may hold them, and keep --private outside the repository.

  anonymise-rx.py build   --src DIR --truth truth.json --private PDIR
                          [--out tests/rx-corpus] [--seed N]
      Anonymises DIR/chrome/R*.txt and DIR/firefox/R*.txt into
      OUT/{chrome,firefox}/Cnnn.txt (new, shuffled ids), verifies the
      result (see `verify`), and writes OUT/expected.json from the truth
      file.  PDIR (outside the repository) receives the id map
      (map.json) and the detailed verification log, which name real
      values: it must never be committed.

  anonymise-rx.py add     --ids R236,R237,… --src DIR [--src DIR2] --truth T [--truth T2]
                          --private PDIR [--seed N] [--filename-map private-map.json --grep-root tests]
      Appends new prescriptions (C numbers continue after the last one,
      shuffled among themselves, fresh seed); refuses one whose prescription
      number is already in the corpus; then verifies the whole corpus and
      regenerates expected.json.  --src / --truth are repeatable (batch 1
      in txt/, batch 2 in txt2/ …) for every command.

  anonymise-rx.py verify  --src DIR --private PDIR [--out tests/rx-corpus]
      Re-extracts every identifying value from the originals, with an
      extractor independent of the anonymiser, and checks that none of
      them occurs in the anonymised files; greps the whole corpus for
      every surname and first name of every original; checks that the
      drug and dose text and the line structure are unchanged.  Writes
      OUT/anonymisation-verification.log (counts only, no values) and
      PDIR/verification-detail.log (values).  Exit 1 on any leak.

  anonymise-rx.py expected --truth truth.json --private PDIR [--out ...]
      Regenerates OUT/expected.json from a truth file (see
      /home/claude/rx240/truth/build_truth.py for its format) and the id
      map.  The noise budget («budget») of an existing expected.json is
      kept.

  anonymise-rx.py sidebyside --src DIR --private PDIR [--n 10]
      Prints 10 random original/anonymised pairs, header and footer, for
      a manual check (the output holds real data: read it, do not save).
"""
import argparse
import datetime
import hashlib
import json
import os
import random
import re
import secrets
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
TESTS = os.path.dirname(HERE)
REPO = os.path.dirname(TESTS)
DEFAULT_OUT = os.path.join(TESTS, 'rx-corpus')
BROWSERS = ('chrome', 'firefox')

# ---------------------------------------------------------------- helpers

GREEK = 'Α-ΩΆ-Ώα-ωά-ώϊϋΐΰ'
LETTERS = 'A-Za-z' + GREEK
LATIN_UP = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
LATIN_LO = LATIN_UP.lower()
# The barcode-font alphabet actually seen between «Í» and «Î» (Code 128).
GLYPH_ALPHABET = [chr(c) for c in range(0x21, 0x7f)] + list('ÂÃÄÅÆÇÈÉÊ')
GLYPH_RE = re.compile('Í[' + re.escape(''.join(GLYPH_ALPHABET)) + ']{9}Î')
DATE_RE = re.compile(r'(?<!\d)(\d{2})/(\d{2})/(\d{4}|\d{2})(?!\d)')
STICKER_RE = re.compile(r'\b(Barcode|PC|SN|Batch): (\S+)')
RXNO_LINE_RE = re.compile(r'(?<!\d)(\d{13}) (\d{3})\s*$')
AITIA_RE = re.compile(r'(ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ: )([Α-Ω][Α-Ω. ]*[Α-Ω.])')
# The zero co-payment reason is a closed ΗΔΙΚΑ list (the parser matches it
# as such since 1.27.2): another value of the same list, never the real one.
AITIA_VALUES = ['ΚΥΗΣΗ ΚΑΙ ΛΟΧΕΙΑ', 'ΝΕΦΡΟΠΑΘΕΙΣ ΣΕ ΑΙΜΟΚΑΘΑΡΣΗ', 'ΤΕΛ. ΣΤΑΔ. ΧΡΟΝ. ΝΕΦΡΙΚΗΣ ΝΟΣΟΥ ΕΞΩΝΕΦΡΙΚΗ ΚΑΘΑΡΣΗ']
COMMON_SPECIALTIES = {'ΓΕΝΙΚΗ/ΟΙΚΟΓΕΝΕΙΑΚΗ ΙΑΤΡΙΚΗ', 'ΑΝΕΥ', 'ΠΑΘΟΛΟΓΟΣ', 'ΨΥΧΙΑΤΡΟΣ', 'ΠΑΙΔΙΑΤΡΟΣ', 'ΚΑΡΔΙΟΛΟΓΟΣ'}
SPECIALTY_PLACEHOLDER = 'ΠΑΘΟΛΟΓΟΣ'
# Name placeholders: one per word of the real name (the parser's header
# patterns allow two words), Greek or Latin as the original.
PH = {
    ('doctor', 'surname', 'el'): ['ΙΑΤΡΟΥ', 'ΔΕΥΤΕΡΟΥ', 'ΤΡΙΤΟΥ', 'ΤΕΤΑΡΤΟΥ'],
    ('doctor', 'name', 'el'): ['ΓΙΑΤΡΟΣ', 'ΔΕΥΤΕΡΟΣ', 'ΤΡΙΤΟΣ', 'ΤΕΤΑΡΤΟΣ'],
    ('patient', 'surname', 'el'): ['ΑΣΘΕΝΟΥΣ', 'ΔΕΥΤΕΡΟΥ', 'ΤΡΙΤΟΥ', 'ΤΕΤΑΡΤΟΥ'],
    ('patient', 'name', 'el'): ['ΑΣΘΕΝΗΣ', 'ΔΕΥΤΕΡΗ', 'ΤΡΙΤΗ', 'ΤΕΤΑΡΤΗ'],
    ('doctor', 'surname', 'la'): ['DOCTOR', 'SECOND', 'THIRD', 'FOURTH'],
    ('doctor', 'name', 'la'): ['MEDIC', 'SECOND', 'THIRD', 'FOURTH'],
    ('patient', 'surname', 'la'): ['PATIENT', 'SECOND', 'THIRD', 'FOURTH'],
    ('patient', 'name', 'la'): ['PERSON', 'SECOND', 'THIRD', 'FOURTH'],
}
PLACEHOLDER_WORDS = {w for v in PH.values() for w in v} | {'ΟΔΟΣ', 'ΠΟΛΗ', 'ΤΟΠΟΣ', 'ΧΩΡΙΟ', 'ΑΛΛΗ', 'ΧΩΡΑ'}
ADDRESS_WORDS = ['ΟΔΟΣ', 'ΤΟΠΟΣ', 'ΠΟΛΗ', 'ΧΩΡΙΟ']
DIAG_FILLER = ['Z00.0 Γενική ιατρική εξέταση', 'Z01.8 Άλλη ειδική εξέταση', 'Z76.0 Επανάληψη συνταγής']


def is_inside(path, root):
    path, root = os.path.realpath(path), os.path.realpath(root)
    return path == root or path.startswith(root + os.sep)


def check_private(pdir):
    if is_inside(pdir, REPO):
        sys.exit('refused: --private %s is inside %s; it holds real data' % (pdir, REPO))
    os.makedirs(pdir, exist_ok=True)


class Gen:
    """Deterministic replacements for one prescription (seed, rx id)."""

    def __init__(self, seed, rx):
        self.seed, self.rx = seed, rx
        self.map = {}          # original token -> replacement (whole file)

    def rng(self, *key):
        h = hashlib.sha256(('%s|%s|' % (self.seed, self.rx) + '|'.join(map(str, key))).encode()).digest()
        return random.Random(h)

    def same_class(self, orig, kind, keep_first=0):
        r = self.rng(kind, orig)
        for _ in range(100):
            out = []
            for i, ch in enumerate(orig):
                if i < keep_first or not ch.isalnum():
                    out.append(ch)
                elif ch.isdigit():
                    out.append(r.choice('0123456789'))
                elif ch in LATIN_UP:
                    out.append(r.choice(LATIN_UP))
                elif ch in LATIN_LO:
                    out.append(r.choice(LATIN_LO))
                else:
                    out.append(ch)
            s = ''.join(out)
            if s != orig:
                return s
        raise RuntimeError('no replacement for a token')

    def token(self, orig, kind, **kw):
        if orig not in self.map:
            self.map[orig] = self.make(orig, kind, **kw)
        return self.map[orig]

    def make(self, orig, kind, yy=None):
        r = self.rng(kind, orig)
        if kind == 'amka' and re.fullmatch(r'\d{11}', orig):
            # DDMMYY + 5 digits, as a real ΑΜΚΑ (YY of the shifted birth year).
            for _ in range(100):
                s = '%02d%02d%02d' % (r.randint(1, 28), r.randint(1, 12), yy if yy is not None else r.randint(40, 99)) \
                    + ''.join(r.choice('0123456789') for _ in range(5))
                if s != orig:
                    return s
        if kind == 'phone':
            return self.same_class(orig, kind, keep_first=1)
        if kind == 'rxno':
            return self.same_class(orig, kind, keep_first=2)
        if kind == 'glyph':
            for _ in range(100):
                s = 'Í' + ''.join(r.choice(GLYPH_ALPHABET) for _ in range(9)) + 'Î'
                if s != orig:
                    return s
        return self.same_class(orig, kind)


def split_ws(line):
    m = re.match(r'^(.*?)(\s*)$', line, re.S)
    return m.group(1), m.group(2)


def script_of(word):
    return 'la' if re.search('[A-Za-z]', word) and not re.search('[' + GREEK + ']', word) else 'el'


def placeholder_name(value, who, what):
    """Each word of the name → a placeholder word; separators kept."""
    i = [0]

    def rep(m):
        w = m.group(0)
        if not re.search('[' + LETTERS + ']', w):
            return w
        lst = PH[(who, what, script_of(w))]
        out = lst[min(i[0], len(lst) - 1)]
        i[0] += 1
        return out
    return re.sub(r'[^\s\-]+', rep, value)


def placeholder_address(value, gen):
    i = [0]

    def rep(m):
        w = m.group(0)
        if re.fullmatch(r'\d+', w):
            return gen.token(w, 'addrnum')
        out = ADDRESS_WORDS[i[0] % len(ADDRESS_WORDS)]
        i[0] += 1
        return out
    return re.sub(r'\S+', rep, value)


def diag_filler(length, k, ends_slash):
    parts, n = [], k
    while not parts or len(' / '.join(parts)) < length - 8:
        parts.append(DIAG_FILLER[n % len(DIAG_FILLER)])
        n += 1
        if len(parts) > 6:
            break
    s = ' / '.join(parts)
    return s + (' /' if ends_slash else '')

# ------------------------------------------------------------ anonymiser


class Anonymiser:
    def __init__(self, seed, rx, texts):
        self.g = Gen(seed, rx)
        self.rxnos = set()
        r = self.g.rng('dates')
        self.orig_dates = set()
        yobs = set()
        for t in texts:
            self.orig_dates |= {m.group(0) for m in DATE_RE.finditer(t)}
            yobs |= set(re.findall(r'ΕΤΟΣ ΓΕΝΝΗΣΗΣ : (\d{4})', t))
        # one shift for every date of the prescription; no shifted date may
        # equal an original one (the check would see it as a leak)
        for _ in range(1000):
            self.shift = r.randint(-150, -11)     # back in time: no execution date in the future
            if not any(self.shift_date(d) in self.orig_dates for d in self.orig_dates):
                break
        ry = self.g.rng('yob')
        self.yob = {}
        for y in yobs:
            d = ry.choice([-3, -2, -1, 1, 2, 3])
            ny = int(y) + d
            if ny > 2025:
                ny = int(y) - abs(d)
            self.yob[y] = str(ny)
        # the patient ΑΜΚΑ takes the shifted birth year
        self.yy = int(next(iter(self.yob.values()))) % 100 if len(self.yob) == 1 else None

    def aitia(self, orig):
        others = [v for v in AITIA_VALUES if v != orig.strip()]
        return self.g.rng('aitia', orig).choice(others)

    def shift_date(self, s):
        m = DATE_RE.fullmatch(s)
        dd, mm, yy = m.groups()
        year = int(yy) + (2000 if len(yy) == 2 else 0)
        d = datetime.date(year, int(mm), int(dd)) + datetime.timedelta(days=self.shift)
        return '%02d/%02d/%s' % (d.day, d.month, str(d.year)[-len(yy):])

    def run(self, text):
        g = self.g
        out = []
        diag = False
        for raw in text.split('\n'):
            body, ws = split_ws(raw)
            if body.startswith('ΣΥΜΠΛΗΡΩΝΕΤΑΙ ΑΠΟ ΤΟΝ ΦΑΡΜΑΚΟΠΟΙΟ') or body.startswith('Συμ. Ποσότητα'):
                diag = False
            new = self.line(body, diag)
            if body.startswith('ΔΙΑΓΝΩΣΗ :'):
                diag = True
            out.append(new + ws)
        return '\n'.join(out)

    def line(self, b, diag):
        g = self.g
        m = re.match(r'^ΕΠΩΝΥΜΟ : (.*?) ΕΠΩΝΥΜΟ : (.*)$', b)
        if m:
            return 'ΕΠΩΝΥΜΟ : %s ΕΠΩΝΥΜΟ : %s' % (placeholder_name(m.group(1), 'doctor', 'surname'),
                                                  placeholder_name(m.group(2), 'patient', 'surname'))
        m = re.match(r'^ΟΝΟΜΑ : (.*?) ΟΝΟΜΑ : (.*)$', b)
        if m:
            return 'ΟΝΟΜΑ : %s ΟΝΟΜΑ : %s' % (placeholder_name(m.group(1), 'doctor', 'name'),
                                              placeholder_name(m.group(2), 'patient', 'name'))
        m = re.match(r'^Α\.Μ\.Κ\.Α\. : (\S*) (Α\.Μ\.Κ\.Α\.|ΔΙΑΒΑΤΗΡΙΟ) : (\S*)$', b)
        if m:
            a = g.token(m.group(1), 'amka') if m.group(1) else ''
            if m.group(2) == 'ΔΙΑΒΑΤΗΡΙΟ':
                p = g.token(m.group(3), 'passport') if m.group(3) else ''
            else:
                p = g.token(m.group(3), 'amka', yy=self.yy) if m.group(3) else ''
            return 'Α.Μ.Κ.Α. : %s %s : %s' % (a, m.group(2), p)
        m = re.match(r'^Ε\.Τ\.Α\.Α\. : (\S*) (.*)$', b)
        if m:
            e = g.token(m.group(1), 'etaa') if m.group(1) else ''
            rest = m.group(2)
            m2 = re.match(r'^Α\.Μ\.Α\. : (\S+)(.*)$', rest)
            if m2:
                rest = 'Α.Μ.Α. : ' + g.token(m2.group(1), 'ama') + m2.group(2)
            return 'Ε.Τ.Α.Α. : %s %s' % (e, rest)
        m = re.match(r'^ΕΙΔΙΚΟΤΗΤΑ : (.*?) ΕΤΟΣ ΓΕΝΝΗΣΗΣ :(?: (\d{4}))?$', b)
        if m:
            sp = m.group(1) if m.group(1) in COMMON_SPECIALTIES else SPECIALTY_PLACEHOLDER
            y = ' ' + self.yob[m.group(2)] if m.group(2) else ''
            return 'ΕΙΔΙΚΟΤΗΤΑ : %s ΕΤΟΣ ΓΕΝΝΗΣΗΣ :%s' % (sp, y)
        m = re.match(r'^ΜΟΝΑΔΑ : (.*?) (ΔΙΕΥΘΥΝΣΗ|ΧΩΡΑ) :(?: (.*))?$', b)
        if m:
            if m.group(2) == 'ΧΩΡΑ':
                v = ' ΑΛΛΗ ΧΩΡΑ' if m.group(3) else ''
            else:
                v = ' ' + placeholder_address(m.group(3), g) if m.group(3) else ''
            return 'ΜΟΝΑΔΑ : %s %s :%s' % (m.group(1), m.group(2), v)
        m = re.match(r'^ΤΗΛΕΦΩΝΟ : (\d+)$', b)
        if m:
            return 'ΤΗΛΕΦΩΝΟ : ' + g.token(m.group(1), 'phone')
        if b.startswith('ΔΙΑΓΝΩΣΗ :'):
            v = b[len('ΔΙΑΓΝΩΣΗ :'):].strip()
            return 'ΔΙΑΓΝΩΣΗ : ' + diag_filler(len(v), 0, v.endswith('/')) if v else b
        if diag:
            return diag_filler(len(b), 1, b.endswith('/'))
        # everywhere else: the prescription barcode and its number, dates,
        # pharmacist/pharmacy numbers, sticker codes, zero co-payment reason
        b = GLYPH_RE.sub(lambda x: g.token(x.group(0), 'glyph'), b)
        m = RXNO_LINE_RE.search(b)
        if m:
            g.token(m.group(1), 'rxno')
            self.rxnos.add(m.group(1))
        for orig in self.rxnos:
            if orig in b:
                b = re.sub(r'(?<!\d)' + orig + r'(?!\d)', g.map[orig], b)
        b = DATE_RE.sub(lambda x: self.shift_date(x.group(0)), b)
        if re.fullmatch(r'\d{5,}', b):
            b = g.token(b, 'num')
        b = STICKER_RE.sub(lambda x: x.group(1) + ': ' + g.token(x.group(2), 'sticker-' + x.group(1)), b)
        b = AITIA_RE.sub(lambda x: x.group(1) + self.aitia(x.group(2)), b)
        return b


# ------------------------------------------------------ independent check

def identifiers(text):
    """Every identifying value of an ORIGINAL print-out, by kind. Written
    apart from the anonymiser (own patterns) so that a gap in one is not
    repeated in the other."""
    ids = {}

    def add(kind, v):
        v = v.strip()
        if v:
            ids.setdefault(kind, set()).add(v)
    lines = text.split('\n')
    in_diag = False
    for raw in lines:
        l = raw.strip()
        for lab, kind in (('ΕΠΩΝΥΜΟ', 'surname'), ('ΟΝΟΜΑ', 'firstname')):
            if l.startswith(lab + ' :'):
                for part in re.split(lab + r' :', l)[1:]:
                    for w in re.findall('[' + LETTERS + r'][' + LETTERS + r'.]*', part):
                        if len(w) >= 2:
                            add(kind, w)
        for lab in ('Α.Μ.Κ.Α. :', 'Ε.Τ.Α.Α. :', 'Α.Μ.Α. :', 'ΔΙΑΒΑΤΗΡΙΟ :', 'ΤΗΛΕΦΩΝΟ :'):
            for m in re.finditer(re.escape(lab) + r' ?(\S+)', l):
                if re.search(r'\d', m.group(1)):
                    add('number', m.group(1))
        m = re.search(r'ΔΙΕΥΘΥΝΣΗ :(.*)$', l) or re.search(r'ΧΩΡΑ :(.*)$', l)
        if m:
            for w in m.group(1).split():
                if re.fullmatch(r'\d{3,}', w) or len(re.sub('[^' + LETTERS + ']', '', w)) >= 3:
                    add('address', w)
        m = re.search(r'ΕΤΟΣ ΓΕΝΝΗΣΗΣ : (\d{4})', l)
        if m:
            add('yob', m.group(0))
        m = re.search(r'ΕΙΔΙΚΟΤΗΤΑ : (.*?) ΕΤΟΣ', l)
        if m and m.group(1) not in COMMON_SPECIALTIES:
            add('specialty', m.group(1))
        if l.startswith('ΔΙΑΓΝΩΣΗ'):
            in_diag = True
        elif l.startswith('ΣΥΜΠΛΗΡΩΝΕΤΑΙ') or l.startswith('Συμ. Ποσότητα'):
            in_diag = False
        if in_diag:
            body = l.replace('ΔΙΑΓΝΩΣΗ :', '')
            for c in re.findall(r'\b[A-Z]\d{2}(?:\.\d{1,2})?\b', body):
                add('icd', c)
            for w in re.findall('[' + LETTERS + ']{6,}', body):
                add('diagword', w)
        m = re.search(r'ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ:(.*)$', l)
        if m:
            add('aitia', m.group(1))
        if re.fullmatch(r'\d{5,}', l):
            add('number', l)
        for m in re.finditer(r'(?<!\d)\d{13}(?= \d{3}\b)', l):
            add('rxno', m.group(0))
        for m in re.finditer(r'Í.{9}Î', l):
            add('glyph', m.group(0))
        for m in re.finditer(r'\b\d{1,2}/\d{1,2}/\d{2,4}\b', l):
            add('date', m.group(0))
        for m in re.finditer(r'\b(?:Barcode|PC|SN|Batch):\s*(\S+)', l):
            add('sticker', m.group(1))
    return ids


def word_re(tok):
    if re.fullmatch(r'\d+', tok):
        return re.compile(r'(?<!\d)' + re.escape(tok) + r'(?!\d)')
    return re.compile(r'(?<![' + LETTERS + r'0-9])' + re.escape(tok) + r'(?![' + LETTERS + r'0-9])')


def load_map(pdir):
    return json.load(open(os.path.join(pdir, 'map.json')))


def src_file(srcs, browser, rx):
    """The original R file: the first --src folder that has it (batch 1
    in txt/, batch 2 in txt2/ …)."""
    for s in srcs:
        f = os.path.join(s, browser, rx + '.txt')
        if os.path.exists(f):
            return f
    raise FileNotFoundError('%s/%s.txt in none of %s' % (browser, rx, srcs))


def load_truths(paths):
    t = {}
    for p in paths or []:
        for k, v in json.load(open(p, encoding='utf-8')).items():
            t.setdefault(k, v)
    return t


def filename_surnames(path, rxs):
    """Patient surnames as the PDF file names carry them
    («<number>_#U03a0#U0391….pdf»), for the given R ids."""
    fm = json.load(open(path, encoding='utf-8'))
    out = {}
    for rx in rxs:
        fn = fm.get(rx + '.pdf')
        if not fn:
            continue
        stem = re.sub(r'\.pdf$', '', fn, flags=re.I).split('_', 1)[-1]
        stem = re.sub(r'#U([0-9a-fA-F]{4})', lambda m: chr(int(m.group(1), 16)), stem)
        for w in re.split(r'[\s_\-]+', stem):
            if len(w) >= 3 and re.search('[' + LETTERS + ']', w):
                out.setdefault(w, set()).add(rx)
        # the prescription number the file is named after (batch 2 names
        # carry only that)
        for num in re.findall(r'\d{13}', fn):
            out.setdefault(num, set()).add(rx)
    return out


def grep_tree(root, words):
    """Word hits of each name in every file under root (text or not)."""
    hits = {}
    regs = {w: word_re(w) for w in words}
    for d, _, files in os.walk(root):
        for f in files:
            try:
                raw = open(os.path.join(d, f), 'rb').read()
            except OSError:
                continue
            if b'\0' in raw[:8192]:
                continue           # binary (png, …): no text to leak, random byte runs only
            t = raw.decode('utf-8', errors='ignore')
            t = GLYPH_RE.sub(' ', t)
            for w, r in regs.items():
                if w in t and r.search(t):
                    hits.setdefault(w, []).append(os.path.relpath(os.path.join(d, f), root))
    return hits


def verify(srcs, out, pdir, truth=None, filename_map=None, grep_root=None):
    idmap = load_map(pdir)          # {"C001": "R123", ...}
    truth = truth or {}
    pub, det = [], []
    fails = 0
    corpus = {}
    for c in sorted(idmap):
        for b in BROWSERS:
            corpus[(c, b)] = open(os.path.join(out, b, c + '.txt'), encoding='utf-8').read()
    # page furniture: a line seen verbatim in 20+ originals must never change
    seen = {}
    for c in sorted(idmap):
        for l in set(open(src_file(srcs, 'chrome', idmap[c]), encoding='utf-8').read().split('\n')):
            seen[l.strip()] = seen.get(l.strip(), 0) + 1
    # (not the pharmacist's own numbers and the common dates: identifiers)
    furniture = {l for l, n in seen.items() if n >= 20 and l and not re.search(r'\d{5,}|\d/\d{2}/\d', l)}
    # --- per file: every identifying value of the original is gone
    kinds_total = {}
    checked = 0
    for c in sorted(idmap):
        rx = idmap[c]
        for b in BROWSERS:
            orig = open(src_file(srcs, b, rx), encoding='utf-8').read()
            anon = corpus[(c, b)]
            ids = identifiers(orig)
            # drug/dose text of the truth: a value that is also part of it
            # (a number inside a drug line) is not a leak
            meds_text = ' '.join(' '.join(m['drug_lines'] + m['dose_lines']) for m in truth.get(rx, {}).get('meds', []))
            for kind, toks in ids.items():
                for t in toks:
                    kinds_total[kind] = kinds_total.get(kind, 0) + 1
                    checked += 1
                    if kind == 'yob':
                        hit = t in anon
                    elif kind in ('glyph', 'aitia', 'specialty'):
                        hit = t in anon
                    else:
                        hit = bool(word_re(t).search(anon))
                    if hit and kind == 'address' and t in PLACEHOLDER_WORDS:
                        det.append('%s/%s %s: %r is a placeholder word (allowed)' % (c, b, kind, t))
                        continue
                    if hit and kind in ('number', 'address', 'diagword', 'sticker') and word_re(t).search(meds_text):
                        det.append('%s/%s %s: %r also in the drug/dose text (allowed)' % (c, b, kind, t))
                        continue
                    if hit:
                        fails += 1
                        det.append('LEAK %s/%s (%s) %s: %r' % (c, b, rx, kind, t))
            # --- structure: same number of lines; the drug and dose text intact
            ol, al = orig.split('\n'), anon.split('\n')
            if len(ol) != len(al):
                fails += 1
                det.append('STRUCTURE %s/%s: %d lines, original %d' % (c, b, len(al), len(ol)))
            changed = sum(1 for x, y in zip(ol, al) if x != y)
            for x, y in zip(ol, al):
                if x != y and x.strip() in furniture:
                    fails += 1
                    det.append('FURNITURE %s/%s: %r changed' % (c, b, x))
            kinds_total['_changed_lines'] = kinds_total.get('_changed_lines', 0) + changed
            flat = ' '.join(anon.split())
            for m in truth.get(rx, {}).get('meds', []):
                for part in m['drug_lines'] + m['dose_lines']:
                    if ' '.join(part.split()) not in flat:
                        fails += 1
                        det.append('DRUGTEXT %s/%s: %r changed' % (c, b, part))
    # --- whole corpus: every surname / first name of every original, anywhere
    names = {}
    for c in sorted(idmap):
        orig = open(src_file(srcs, 'chrome', idmap[c]), encoding='utf-8').read()
        ids = identifiers(orig)
        for k in ('surname', 'firstname'):
            for w in ids.get(k, ()):
                names[w] = k
    name_hits = 0
    allowed_names = 0
    # (the random barcode-font strings are left out: «Íe7YRÈDE;iÎ» is not a name)
    blob = GLYPH_RE.sub(' ', '\n'.join(corpus.values()))
    for w, k in sorted(names.items()):
        if w in PLACEHOLDER_WORDS or w in {'ΕΠΩΝΥΜΟ', 'ΟΝΟΜΑ'}:
            allowed_names += 1
            det.append('NAME %s %r is also a placeholder word (allowed)' % (k, w))
            continue
        n = len(word_re(w).findall(blob))
        if n:
            name_hits += 1
            fails += 1
            det.append('NAME LEAK %s %r: %d occurrence(s) in the corpus' % (k, w, n))
    # --- the surnames of the PDF file names, grepped over a whole folder tree
    fn_line = None
    if filename_map and grep_root:
        fn_names = filename_surnames(filename_map, sorted(set(idmap.values())))
        hits = grep_tree(grep_root, [w for w in fn_names if w not in PLACEHOLDER_WORDS])
        for w, files in sorted(hits.items()):
            fails += 1
            det.append('FILENAME SURNAME LEAK %r (%s): %s' % (w, ','.join(sorted(fn_names[w])), ', '.join(files[:5])))
        fn_line = 'file-name surnames of all %d originals grepped over %s: %d distinct, %d found' % (
            len(set(idmap.values())), os.path.basename(os.path.abspath(grep_root)) + '/', len(fn_names), len(hits))
    pub.append('rx-corpus anonymisation verification (%s)' % datetime.date.today().isoformat())
    pub.append('files: %d prescriptions x %d browsers' % (len(idmap), len(BROWSERS)))
    pub.append('identifying values checked per file (sum over all files), by kind:')
    for k in sorted(kinds_total):
        pub.append('  %-16s %d' % (k, kinds_total[k]))
    pub.append('corpus-wide name grep: %d distinct surnames/first names, %d found in the corpus, %d equal to a placeholder word'
               % (len(names), name_hits, allowed_names))
    if fn_line:
        pub.append(fn_line)
    pub.append('allowed coincidences (value also inside the drug/dose text, or a generic address word equal to a placeholder): %d'
               % sum(1 for d in det if '(allowed)' in d and not d.startswith('NAME')))
    pub.append('RESULT: %s (%d problem(s))' % ('PASS' if not fails else 'FAIL', fails))
    open(os.path.join(out, 'anonymisation-verification.log'), 'w', encoding='utf-8').write('\n'.join(pub) + '\n')
    open(os.path.join(pdir, 'verification-detail.log'), 'w', encoding='utf-8').write('\n'.join(pub + [''] + det) + '\n')
    print('\n'.join(pub))
    return fails

# ------------------------------------------------------- expected.json

# Strength of a drug line: the numbers of its FIRST strength expression
# («10MG», «(50+1000)MG», «(70mg+140mcg) (5600IU)», «0,25MG+5MG+10000IU»),
# not the per-volume denominator («/5ML») nor a later concentration.
_GR = str.maketrans({'Β': 'B', 'Τ': 'T', 'Χ': 'X', 'Μ': 'M', 'Ι': 'I', 'Κ': 'K', 'Ε': 'E', 'Α': 'A', 'Ο': 'O',
                     'Ν': 'N', 'Ρ': 'P', 'Η': 'H', 'Ζ': 'Z', 'Υ': 'Y'})
_U = r'(?:MCG|MC|UG|MG|UNITS?|IU|U|G|%)(?![A-Z])'
_N = r'\d+(?:[.,]\d+)?'
_PART = r'\(?\s*' + _N + r'\s*(?:' + _U + r')?\s*\)?'
_CHAIN = re.compile(r'(?<![A-Z0-9,.])(' + _PART + r'(?:\s*\+\s*' + _PART + r')*)\s*(' + _U + r')?')
_PACK = re.compile(r'(?:^|[\s/)(,])(?:BT\s*X|BTX|BT\s|BOTTLE|FL\s*X|FLX|TUB|1\s*PF|ΣΥΣΚΕΥΗ|\d+\s*ΠΡ\.\s*ΣΥΣΚ|ΤΑΙΝΙΑ|\d+X\d+\s*\(|\d+X\d+$)')
# Reviewed by hand: the expression above does not read these two.
STRENGTH_OVERRIDE = {
    'TROFOCARD GR.OR.SD 1229,6(121,5Mg++)MG': ('1229,6(121,5MG)MG', [1229.6, 121.5]),
    'INNOHEP INJ.SOL 4500antiXA iu': ('4500 ANTI-XA IU', [4500.0]),
}
KNOWN_TRUNCATION = ('FOSAVANCE TAB (70mg+140mcg) (5600IU)', 'CADELIUS OR.DISP.TA (1500MG+')
WEEKLY_ONLY = re.compile(r'^(OZEMPIC|WEGOVY|TRULICITY|MOUNJARO|BYDUREON|FOSAMAX ONCE WEEKLY|FOSAVANCE|BINOSTO|ACTONEL OAW|ACTONEL GR|METHOTREXATE|NORDIMET|METOJECT|METHOX-F|TREXAN|EMTHEXATE|ZEXATE)\b')


def _fold(s):
    return s.replace('μg', 'MCG').replace('μG', 'MCG').upper().translate(_GR)


def drug_strength(drug):
    for k, v in STRENGTH_OVERRIDE.items():
        if drug.startswith(k):
            return v
    d = _fold(re.sub(r'\((?:Γενόσημο|Πρωτότυπο[^)]*)\)', '', drug))
    for m in _CHAIN.finditer(d):
        txt = m.group(0)
        if not re.search(_U, txt):
            continue
        g = re.match(r'\s*\(\s*(' + _N + r')\s*(' + _U + r')\s*\)', d[m.end():])
        if g:
            txt += ' ' + g.group(0).strip()
        return ' '.join(txt.split()), [float(x.replace(',', '.')) for x in re.findall(_N, txt)]
    return None, []


def volume_only(drug):
    d = _fold(drug)
    m = _PACK.search(d)
    return bool(re.search(_N + r'\s*ML', d[:m.start() if m else len(d)]))


DAILY = {1: '24h', 2: '12h', 3: '8h', 4: '6h'}

# 1.27.2 (round 9) insulin rule, as rx-import.js pickUnit(): an injectable
# that is a known insulin by name or has a strength in units per ml.
INSULIN_NAMES = re.compile(r'\b(TOUJEO|LANTUS|ABASAGLAR|SEMGLEE|TRESIBA|LEVEMIR|NOVORAPID|FIASP|HUMALOG|LYUMJEV|APIDRA|'
                           r'ADMELOG|HUMULIN|INSUMAN|ACTRAPID|INSULATARD|MIXTARD|NOVOMIX|RYZODEG|XULTOPHY|SULIQUA|'
                           r'KIRSTY|TRURAPI|INSULIN\w*)\b')
UNITS_PER_ML = re.compile(r'(?:^|[^A-Z])(?:UNITS?|IU|U)\s*/\s*ML\b')


def is_insulin_injection(m):
    """«N ΕΝΕΣΗ» of an insulin: the dose is in injections, the units are
    unknown. Not the one pre-filled syringe of an immunoglobulin in IU/ML
    (TETAGAM P 250 IU/ML, «1 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ»)."""
    n = m['norm']
    if n.get('unit') != 'injection':
        return False
    d = _fold(m['drug'])
    named = bool(INSULIN_NAMES.search(d))
    if not (named or UNITS_PER_ML.search(d)):
        return False
    uw = n.get('unit_words', '')
    single = m.get('pack_how') == 'single syringe/amp' or m.get('pack') == 1
    if not named and single and n['qty'] == 1 and 'ΠΡΟΓΕΜΙΣ' in uw and 'ΣΥΡΙΓΓ' in uw \
            and not re.search(r'PEN|CARTRIDGE|VIALS', d):
        return False
    return True


def single_dose_vials(m):
    """1.27.2, confirmed by the pharmacist: «N ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ» of a
    single-dose form («.SD») packed in vials / ampoules (SOLUMAG FORTE
    OR.SOL.SD … BTx20 VIALSx10 ML) is N single-dose vials: unit 'ampoule'.
    Not «ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ 5ML» (DE3-SOLE: 5 ml = 2 vials) nor a powder
    («ΠΟΣ.ΣΚΟΝΗ ΔΟΣ ΔΙΑΛΥΜΑ», VIOFER)."""
    if m['norm'].get('unit_words') != 'ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ':
        return False
    d = m['drug'].upper()
    return bool(re.search(r'\.SD\b', d) and re.search(r'VIALS?|AMPS?\b|ΦΙΑΛΙΔΙΑ|ΑΜΠΟΥΛΕΣ', d))


def short_duration(m):
    """15 times what the plan needs dispensed, for 3 days or less, on a
    reliable pack (FLECARYTHM BT x 60 «x 1 ημέρες»): the duration is
    probably wrong."""
    n = m['norm']
    if not (n['ok'] and m.get('pack_reliable') and m.get('pack') and m.get('boxes')):
        return False
    if n['freq']['kind'] != 'daily' or n['days'] > 3:
        return False
    needed = n['qty'] * n['freq']['n'] * n['days']
    return needed > 0 and m['pack'] * m['boxes'] / needed >= 15


def med_expectation(m):
    """One truth medicine → what the parser must produce, and whether a
    warning is genuinely needed («mustFlag»)."""
    n = m['norm']
    drug = m['drug']
    strength, nums = drug_strength(drug)
    unit = None if n['unit'] == 'UNSURE' else n['unit'].split('|')
    f = n['freq']
    must, notes = [], []
    if unit is None and single_dose_vials(m):
        unit = ['ampoule']
        notes.append('singleDoseVials')
    exp = None
    if n['ok'] and unit:
        e = {'doseAmount': n['qty'], 'doseUnit': unit, 'days': n['days']}
        if f['kind'] == 'daily' and f['n'] in DAILY:
            e['freq'] = [[DAILY[f['n']], 'days']]
        elif f['kind'] == 'weekly' and f['n'] == 1:
            e['freq'] = [['custom', 'weekday']]
        elif f['kind'] == 'once' and n['days'] == 1:
            e['freq'] = [['24h', 'days'], ['custom', 'once']]
        elif f['kind'] == 'every':
            # 1.27.2: «κάθε 4 εβδομάδες» is every 28 days (doses confirmed)
            e['freq'] = [['custom', 'days']]
            e['intervalDays'] = f['n'] * {'ημέρες': 1, 'εβδομάδες': 7}.get(f['per'], 0) or None
            if not e['intervalDays']:
                e = None
        else:
            e = None
        exp = e
    if f['kind'] == 'prn':
        must.append('asNeeded')              # «επί πόνου»: no fixed plan
    if f['kind'] == 'weekly' and f['n'] > 1:
        must.append('timesPerWeek')          # 3×/week: which days?
    if f['kind'] == 'every':
        must.append('everyN')                # «κάθε 4 εβδομάδες»
    if f['kind'] == 'weekly' and f['n'] == 1 and n['days'] % 7:
        must.append('weeklyNotWholeWeeks')   # 30 days weekly: 4 or 5 doses?
    if n['ok'] and not unit:
        must.append('unitUnclear')           # «ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ»: a vial is not a plan unit
    insulin = is_insulin_injection(m)
    if insulin:
        must.append('insulinInjections')     # insulin in «N ΕΝΕΣΗ»: units unknown, never addable
        exp = None
    elif unit == ['injection'] and n['qty'] != int(n['qty']):
        must.append('fractionalInjection')   # «1/2 ΕΝΕΣΗ» of something else
    if short_duration(m):
        must.append('shortDuration')         # 60 tablets for «x 1 ημέρες»
    if f['kind'] == 'daily' and WEEKLY_ONLY.search(drug):
        must.append('weeklyOnlyDaily')       # a once-a-week medicine written daily
    if not nums:
        if volume_only(drug):
            notes.append('volumeOnly')       # «0,5ML (DOSE)»: a vaccine dose, no strength needed
        elif re.search(r'\b(INJ|SUSP|SU\.IN|PD\.SU|PS\.INJ|PFS)\b', drug):
            # 1.27.2: a vaccine with no number+unit is read as it is, no
            # confirmation needed (nothing was dropped): a note, not a flag
            notes.append('noStrength')
        elif re.search(_N, _fold(drug)[:(_PACK.search(_fold(drug)).start() if _PACK.search(_fold(drug)) else None)]):
            must.append('strengthUnreadable')  # «CREAM 0,02», «EY.DRO.SOL 0,003»: a number with no unit (typo)
        else:
            notes.append('noStrength')       # no number at all («PAROTICIN EA.SOL»): nothing dropped
    if 'ML' in n.get('unit_words', '') and re.search(r'VIALS? ', drug) and n['qty'] <= 1 and unit == ['ml']:
        notes.append('mlDoseFromVials')      # 1 ml from 15 ml vials: faithful, but odd (not flagged)
    if not n['ok']:
        must.append('doseUnreadable')
    return {
        'drug': drug,
        'dose': m['dose'],
        'qty': n.get('qty'),
        'qtyText': n.get('qty_text'),
        'unitWords': n.get('unit_words'),
        'unit': unit,
        'freq': f,
        'freqText': n.get('freq_text'),
        'days': n.get('days'),
        'strength': strength,
        'strengthNums': nums,
        'knownTruncation': drug.startswith(KNOWN_TRUNCATION),
        'insulin': insulin,
        'expect': exp,
        'mustFlag': must,
        'notes': notes,
        'boxes': m.get('boxes'),
        'pack': m.get('pack'),
    }


def expected(truth, idmap, out):
    path = os.path.join(out, 'expected.json')
    old = json.load(open(path, encoding='utf-8')) if os.path.exists(path) else {}
    files = {}
    for c in sorted(idmap):
        t = truth[idmap[c]]
        files[c] = {
            'pages': t['pages'],
            'stickers': len(t['stickers']),
            'meds': [med_expectation(m) for m in t['meds']],
        }
    doc = {
        '_about': 'Derived from the independent PDF ground truth (truth.json, pdfplumber coordinates), never from the parser. '
                  'Regenerate with tests/tools/anonymise-rx.py expected. «budget» is the noise ratchet: lower it when the parser improves, never raise it.',
        'budget': old.get('budget', {}),
        'files': files,
    }
    json.dump(doc, open(path, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    n = sum(len(f['meds']) for f in files.values())
    must = sum(1 for f in files.values() for m in f['meds'] if m['mustFlag'])
    print('expected.json: %d files, %d medicines, %d must-flag' % (len(files), n, must))

# ---------------------------------------------------------------- main


def cmd_build(a):
    check_private(a.private)
    seed = a.seed if a.seed is not None else secrets.randbits(64)
    src_ids = sorted(f[:-4] for f in os.listdir(os.path.join(a.src[0], 'chrome')) if f.endswith('.txt'))
    if a.ids:
        src_ids = [x.strip() for x in re.split(r'[,\s]+', a.ids) if x.strip()]
    for b in BROWSERS:
        for r in src_ids:
            src_file(a.src, b, r)
    r = random.Random(seed)
    perm = src_ids[:]
    r.shuffle(perm)
    idmap = {'C%03d' % (i + 1): rx for i, rx in enumerate(perm)}
    for b in BROWSERS:
        d = os.path.join(a.out, b)
        os.makedirs(d, exist_ok=True)
        for f in os.listdir(d):
            if f.endswith('.txt'):
                os.remove(os.path.join(d, f))
    for c, rx in idmap.items():
        texts = {b: open(src_file(a.src, b, rx), encoding='utf-8').read() for b in BROWSERS}
        an = Anonymiser(seed, rx, list(texts.values()))
        for b in BROWSERS:
            open(os.path.join(a.out, b, c + '.txt'), 'w', encoding='utf-8').write(an.run(texts[b]))
    json.dump(idmap, open(os.path.join(a.private, 'map.json'), 'w'), indent=1)
    json.dump({'seed': seed, 'batches': [{'seed': seed, 'ids': sorted(idmap)}]}, open(os.path.join(a.private, 'seed.json'), 'w'))
    truth = load_truths(a.truth)
    fails = verify(a.src, a.out, a.private, truth, a.filename_map, a.grep_root)
    expected(truth, idmap, a.out)
    sys.exit(1 if fails else 0)


def rx_number(text):
    m = re.search(r'^(\d{13}) \d{3}\s*$', text, re.M)
    return m.group(1) if m else None


def cmd_add(a):
    """Append new prescriptions: C numbers continue after the last one,
    shuffled among themselves, with a fresh seed. A prescription whose
    number is already in the corpus is refused (a repeat PDF)."""
    check_private(a.private)
    idmap = load_map(a.private)
    ids = [x.strip() for x in re.split(r'[,\s]+', a.ids) if x.strip()]
    known = {}
    for c, rx in idmap.items():
        known[rx_number(open(src_file(a.src, 'chrome', rx), encoding='utf-8').read())] = c
    for rx in ids:
        if rx in idmap.values():
            sys.exit('refused: %s is already in the corpus' % rx)
        n = rx_number(open(src_file(a.src, 'chrome', rx), encoding='utf-8').read())
        if n in known:
            sys.exit('refused: %s repeats the prescription of %s' % (rx, known[n]))
        known[n] = rx
    seed = a.seed if a.seed is not None else secrets.randbits(64)
    perm = ids[:]
    random.Random(seed).shuffle(perm)
    start = max(int(c[1:]) for c in idmap) + 1
    new = {'C%03d' % (start + i): rx for i, rx in enumerate(perm)}
    for b in BROWSERS:
        os.makedirs(os.path.join(a.out, b), exist_ok=True)
    for c, rx in new.items():
        texts = {b: open(src_file(a.src, b, rx), encoding='utf-8').read() for b in BROWSERS}
        an = Anonymiser(seed, rx, list(texts.values()))
        for b in BROWSERS:
            open(os.path.join(a.out, b, c + '.txt'), 'w', encoding='utf-8').write(an.run(texts[b]))
    idmap.update(new)
    json.dump(idmap, open(os.path.join(a.private, 'map.json'), 'w'), indent=1)
    sp = os.path.join(a.private, 'seed.json')
    sd = json.load(open(sp)) if os.path.exists(sp) else {}
    sd.setdefault('batches', [{'seed': sd.get('seed'), 'ids': sorted(c for c in idmap if c not in new)}])
    sd['batches'].append({'seed': seed, 'ids': sorted(new)})
    json.dump(sd, open(sp, 'w'))
    print('added %d prescriptions: %s … %s' % (len(new), min(new), max(new)))
    truth = load_truths(a.truth)
    fails = verify(a.src, a.out, a.private, truth, a.filename_map, a.grep_root)
    expected(truth, idmap, a.out)
    sys.exit(1 if fails else 0)


def cmd_verify(a):
    check_private(a.private)
    sys.exit(1 if verify(a.src, a.out, a.private, load_truths(a.truth), a.filename_map, a.grep_root) else 0)


def cmd_expected(a):
    check_private(a.private)
    expected(load_truths(a.truth), load_map(a.private), a.out)


def cmd_sidebyside(a):
    idmap = load_map(a.private)
    r = random.Random(a.pick_seed)
    pool = sorted(idmap) if not a.ids else [c for c in sorted(idmap) if idmap[c] in a.ids.split(',')]
    for c in r.sample(pool, min(a.n, len(pool))):
        o = open(src_file(a.src, 'chrome', idmap[c]), encoding='utf-8').read().split('\n')
        n = open(os.path.join(a.out, 'chrome', c + '.txt'), encoding='utf-8').read().split('\n')
        print('=' * 30, c)
        for x, y in zip(o, n):
            if x != y:
                print('  - ' + x.rstrip())
                print('  + ' + y.rstrip())


def main():
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest='cmd', required=True)
    for name in ('build', 'add', 'verify', 'expected', 'sidebyside'):
        s = sub.add_parser(name)
        s.add_argument('--private', required=True, help='directory OUTSIDE the repository (id map, detailed log)')
        s.add_argument('--out', default=DEFAULT_OUT)
        if name != 'expected':
            s.add_argument('--src', required=True, action='append',
                           help='directory with chrome/ and firefox/ R*.txt originals (repeatable: txt, txt2 …)')
        if name in ('build', 'add', 'expected', 'verify'):
            s.add_argument('--truth', required=(name != 'verify'), action='append', help='truth file (repeatable)')
        if name in ('build', 'add', 'verify'):
            s.add_argument('--filename-map', help='private-map.json: R → PDF file name (holds the patient surname); '
                           'with --grep-root, every such surname is grepped over that whole folder')
            s.add_argument('--grep-root', help='folder to grep for the file-name surnames (e.g. tests/)')
        if name in ('build', 'add'):
            s.add_argument('--seed', type=int)
        if name in ('build', 'add', 'sidebyside'):
            s.add_argument('--ids', help='R ids, comma-separated (add: required)')
        if name == 'sidebyside':
            s.add_argument('--n', type=int, default=10)
            s.add_argument('--pick-seed', type=int, default=None)
    a = p.parse_args()
    {'build': cmd_build, 'add': cmd_add, 'verify': cmd_verify, 'expected': cmd_expected, 'sidebyside': cmd_sidebyside}[a.cmd](a)


if __name__ == '__main__':
    main()
