# rx-corpus: anonymised regression corpus for the prescription parser

311 real ΗΔΙΚΑ e-prescriptions (572 medicines), anonymised, in five batches:

- Batch 1: 235 prescriptions (C001–C235, 415 medicines).
- Batch 2: 38 prescriptions (C236–C273, 75 medicines). These are the genuinely
  new ones out of 126 PDFs. The other 88 repeat batch-1 prescriptions: same
  prescription number and byte-identical text.
- Batch 3: 10 prescriptions (C274–C283, 21 medicines). These are the new ones
  out of 32 PDFs. The other 22 repeat corpus prescriptions: the number in each
  PDF was checked against the corpus.
- Batch 4: 17 prescriptions (C284–C300, 36 medicines), none of them a repeat.
  Each prescription number was checked against the text and PDF of every
  earlier batch.
- Batch 5: 11 prescriptions (C301–C311, 25 medicines). These are the new ones
  out of 13 uploaded PDFs. The other 2 repeat corpus prescriptions: the number
  in each PDF was checked against the corpus.

Each prescription is stored
twice: as a Chrome copy (PDFium text) and as a Firefox (pdf.js) copy. The
ground truth comes from the PDF coordinates, never from the parser.
`tests/rx-corpus.test.js` checks `PD.parsePrescription()` against it.

```
rx-corpus/
  chrome/C001.txt … C311.txt   PDFium text (what a Chrome copy-paste gives)
  firefox/C001.txt … C311.txt  Firefox viewer copy of the same prescriptions
  expected.json                truth per medicine + the noise budget
  anonymisation-verification.log   last verification run (counts only)
```

The C numbers are shuffled within each batch, so they do not follow the order of
the originals.
The map from C numbers to originals is kept outside the repository.

## How it was built

1. **Truth** (`/home/claude/rx240/truth/`, private): pdfplumber reads the word
   coordinates of each PDF. The full-width rules of the medicines table cut it
   into rows. For each medicine, the truth holds the drug line, the ΔΟΣΟΛΟΓΙΑ,
   the price row, the pack size and a strict normalisation of the dose (quantity,
   unit class, frequency, days). Anything outside the grammar
   `<qty> <unit words> x <freq> x <N> ημέρες` is marked UNSURE. The truth is in
   `truth.json`. `build_truth.py` documents its format.
2. **Anonymisation**: `tests/tools/anonymise-rx.py build`. See the next section.
3. **Verification**: runs automatically after each build. It can also be run on
   its own with `anonymise-rx.py verify`.
4. **expected.json**: `anonymise-rx.py expected` derives it from `truth.json`,
   `truth2.json` and `truth3.json`. The parser plays no part in it.
   `truth3.json` was built with `BATCH=3 extract_truth.py` and then
   `BATCH=3 build_truth.py`, and checked by hand. Every drug and dose line was
   found verbatim in the text. Two unit words that `normalize.py` does not know,
   `ΟΦΘ.ΣΤΑΓΟΝΕΣ ΔΟΣΕΙΣ` and `ΟΦΘΑΛΜΙΚΕΣ ΣΤΑΓΟΝΕΣ ΔΙΑΛΥΜΑ` (eye drops), were set
   to `drops` by hand. Each carries a `review` field. For batch 4 (2026-09-26),
   `normalize.py` learned the two unit words, so a rerun keeps them. The rule
   change leaves every earlier truth file unchanged. `truth4.json` was built the
   same way with `BATCH=4` and checked by hand: 36 medicines, and every drug and
   dose line was found verbatim. `truth5.json` (`BATCH=5`) was checked by hand
   the same way: 25 medicines, all found verbatim.

The parser gives exactly the same output on each anonymised file as on its
original. The only differences are the patient name and the random value of a
sticker line the parser leaves unread. This was checked on all 546 files and
shows that the anonymisation keeps the structure that matters. The check covers
all 566 files, including batch 3.

## What was anonymised

The drug lines, dose lines, price rows, totals, fund names, page furniture, line
breaks and trailing spaces are unchanged. The rest was replaced as follows:

| Field | Replacement |
|---|---|
| Doctor and patient ΕΠΩΝΥΜΟ / ΟΝΟΜΑ | `ΙΑΤΡΟΥ` / `ΓΙΑΤΡΟΣ` and `ΑΣΘΕΝΟΥΣ` / `ΑΣΘΕΝΗΣ`, one placeholder word per real word, with hyphens kept. Latin names get Latin placeholders (`PATIENT`, …). |
| Α.Μ.Κ.Α. (doctor and patient) | Random but valid in shape: DDMMYY plus 5 digits. The patient's YY follows the shifted birth year. |
| Ε.Τ.Α.Α., Α.Μ.Α., ΔΙΑΒΑΤΗΡΙΟ, pharmacist's ΑΜΚΑ/ΕΤΑΑ, pharmacy ΑΦΜ | Random digits (and letters) of the same length and class. A value that repeats inside a file keeps a single replacement. |
| ΤΗΛΕΦΩΝΟ | Random digits of the same length. The first digit (2… landline, 6… mobile) is kept. |
| ΔΙΕΥΘΥΝΣΗ / ΧΩΡΑ | Each word becomes `ΟΔΟΣ` / `ΤΟΠΟΣ` / `ΠΟΛΗ` / `ΧΩΡΙΟ`. Numbers become random digits. A country becomes `ΑΛΛΗ ΧΩΡΑ`. |
| ΕΤΟΣ ΓΕΝΝΗΣΗΣ | Shifted by ±1–3 years, never later than 2025. |
| ΕΙΔΙΚΟΤΗΤΑ | Kept when common (ΓΕΝΙΚΗ/ΟΙΚΟΓΕΝΕΙΑΚΗ ΙΑΤΡΙΚΗ, ΑΝΕΥ, ΠΑΘΟΛΟΓΟΣ, ΨΥΧΙΑΤΡΟΣ, ΠΑΙΔΙΑΤΡΟΣ, ΚΑΡΔΙΟΛΟΓΟΣ). Any other specialty becomes `ΠΑΘΟΛΟΓΟΣ`. The ΜΟΝΑΔΑ type (a fixed ΗΔΙΚΑ category) is kept. |
| ΔΙΑΓΝΩΣΗ (all lines) | Generic ICD-shaped placeholders (`Z00.0 Γενική ιατρική εξέταση / …`) of about the same length, with the same number of lines and the trailing ` /` kept. |
| ΑΙΤΙΑ ΜΗΔΕΝΙΚΗΣ ΣΥΜ/ΧΗΣ | Another value from the same closed ΗΔΙΚΑ list, never the real one. Since 1.27.2 the parser recognises only the listed values. |
| Prescription number (13 digits) | One random 13-digit number per file, the same at every occurrence. The first 2 digits are kept. |
| Barcode glyph strings `Í…Î` | `Í` + 9 random characters from the barcode-font alphabet actually seen (0x21–0x7E, `ÂÃÄÅÆÇÈÉÊ`) + `Î`. One per file, the same at every occurrence. |
| Sticker Barcode / PC / SN / Batch | Random characters of the same class (digit/letter) and the same length. Hyphens are kept. The same product in one file keeps the same PC. |
| Dates (ΑΠΟ, ΕΩΣ, ΗΜ/ΝΙΑ ΕΚΤΕΛΕΣΗΣ, …) | Every date of a file is moved back by one random number of days (11–150), in the same format. |

## Verification (automatic)

`anonymise-rx.py verify` reads the originals with its own extractor, written
separately from the anonymiser. For each file it collects the identifying
values: surnames and names, ΑΜΚΑ, ΕΤΑΑ, ΑΜΑ, passport and phone numbers,
address words and numbers, birth year, rare specialties, ICD codes and
diagnosis words, the ΑΙΤΙΑ text, the prescription number, the glyph strings,
all dates and all sticker codes. It then checks the following:

- None of these values occurs in the anonymised copy (whole-word match).
- No surname or first name from any of the 273 originals occurs anywhere in the
  546 corpus files.
- With `--filename-map private-map.json --grep-root tests/`, every surname and
  prescription number in the original PDF file names is searched over the whole
  `tests/` folder. Binary files such as PNGs are skipped.
- Every page-furniture line (one found verbatim in 20 or more originals) is
  unchanged.
- The number of lines is unchanged.
- Every drug line and dose line of the truth is present unchanged.

The log in this folder has counts only. The detailed log, which names the
values, goes to the private folder. Last result (all five batches, 622 files):
**PASS**, 0 leaks. 20 734 values were checked. 403 distinct names returned 0
hits in the corpus, and 483 file-name surnames or numbers returned 0 hits in
`tests/`. The 2 allowed coincidences are one real address word that happens to
equal the placeholder `ΧΩΡΙΟ`. Twenty-four random files (10 from batch 1, 5 from
batch 2, and 3 each from batches 3, 4 and 5) were also compared side by side by
hand.

## The test

`rx-corpus.test.js` parses every file, Chrome and Firefox, and matches the
parser items to the truth medicines by brand, strength and dose text, as
`truth/compare.py` does. The test runs in about 1 s.

Each item is judged as its review row shows it. The time, weekday or
day-of-month choice counts as made. The row's dispensed-quantity warnings
`dispensedQty` and `shortDuration` are added from `PD.rxQuantityCheck()`, as
`syncQuantityWarning()` does. `parsePrescription()` alone never sets them.

- **HARD SAFETY** (must always pass)
  - Every truth medicine is found, unless the parse reports unread dose lines.
  - No item has a wrong quantity, unit, frequency, days, brand, or strength in
    its name (missing or invented numbers) while `PD.rxItemProblem()` is `''`
    after the time, weekday or day-of-month choice is made.
  - Every `mustFlag` medicine carries a hard warning, or the confirmation that
    asks the right question.
- **NOISE BUDGET** (ratchet, per browser)
  - `needless`: medicines that were read right but carry a warning other than
    time, weekDay, monthly/monthDay or intervalCount (or a soft
    duplicate/inPlan note).
  - `wrongFlagged`: medicines read wrong but flagged.
  - `mustFlagWrong`: must-flag medicines that are also read wrong, for example
    GRAPERA «επί πόνου» with no name. They are flagged anyway, but get their
    own ratchet.
  - `extraItems`: parser items that match no medicine.

  Each must be at most `expected.json → budget`. The test prints the numbers,
  and says so when one falls below its budget. Then lower the budget. Never
  raise it to make a regression pass. `RX_CORPUS_VERBOSE=1` lists every noisy
  and every wrong item.

### `mustFlag` codes (from the truth)

| Code | Truth condition |
|---|---|
| `asNeeded` | `επί πόνου` (SOS) |
| `timesPerWeek` | 3 φορές την εβδομάδα |
| `everyN` | κάθε N εβδομάδες/ημέρες. Since 1.27.2 it is expected as every 7N (or N) days (`intervalDays`); `intervalCount` is enough. |
| `weeklyNotWholeWeeks` | once a week over days that are not a multiple of 7 (4 or 5 doses?). `intervalCount` is enough here. |
| `unitUnclear` | `ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ 5ML` (DE3-SOLE: 5 ml = 2 vials), `ΠΟΣ.ΣΚΟΝΗ ΔΟΣ ΔΙΑΛΥΜΑ` (VIOFER), `ΞΗΡΑ ΑΝΘΗ ΦΥΤΟΥ`: no plan unit. Since batch 4, the pharmacist has confirmed that `N ΠΟΣ.ΔΙΑΛ ΔΟΣΕΙΣ` of a single-dose form (`.SD`) packed in VIALS, AMP, ΦΙΑΛΙΔΙΑ or ΑΜΠΟΥΛΕΣ means N single-dose vials. SOLUMAG FORTE OR.SOL.SD is therefore expected as unit `ampoule`, with no flag and the note `singleDoseVials`. |
| `insulinInjections` | an insulin (a known insulin name, or a strength in U/ML or IU/ML) dosed as «N ΕΝΕΣΗ». It needs the hard `insulinUnits` warning itself (or `iuConfirm` for more than 3), and must never be addable. Cases: NOVORAPID ½ ένεση ×7, TRESIBA 1 ΕΝΕΣΗ, TOUJEO 300 Units/ml ½ and 1 ΕΝΕΣΗ. Not TETAGAM P 250 IU/ML: one pre-filled syringe, «1 ΕΝΕΣΙΜΟ ΔΙΑΛΥΜΑ ΣΕ ΠΡΟΓΕΜΙΣΜΕΝΗ ΣΥΡΙΓΓΑ». |
| `fractionalInjection` | `1/2 ΕΝΕΣΗ` of an injection that is not insulin |
| `shortDuration` | 15× or more what the plan needs dispensed, for 3 days or less, on a reliable pack (FLECARYTHM BT x 60, «x 1 ημέρες»). The soft `shortDuration` confirmation is enough. |
| `weeklyOnlyDaily` | a once-a-week medicine written daily: OZEMPIC and the other GLP-1 pens, the weekly bisphosphonates, and every methotrexate (METHOTREXATE, METOJECT, NORDIMET, METHOX-F, TREXAN, …). A methotrexate is expected weekly with a weekday pick. Over 30 days it is also `weeklyNotWholeWeeks`. |
| `strengthUnreadable` | a number with no unit where the strength should be (typo): `FUNGORAL CREAM 0,02` ×2, `TOBREX EY.DRO.SOL 0,003` ×2. A drug line with no number at all (`PAROTICIN EA.SOL`) is only the note `noStrength`, because nothing was dropped. |

Since 1.27.2, a vaccine whose drug line has no number and unit at all
(INFANRIX, BOOSTRIX, BEXSERO, HEXYON, PROQUAD) is only a note, `noStrength`.
It needs no strength confirmation, because nothing was dropped. A vaccine with
only a dose volume (`0,5ML (DOSE)`: GARDASIL, TRIAXIS, PREVENAR) is noted
`volumeOnly`.

`strengthNums` holds the numbers of the first strength expression of the drug
line (for example `(70mg+140mcg) (5600IU)` gives 70/140/5600). The three known
name-truncation cases keep `knownTruncation: true`: FOSAVANCE, and CADELIUS
×2 (+1000 IU and +2000 IU). `STRENGTH_OVERRIDE` in the tool lists the two
drug lines whose strength was set by hand (TROFOCARD, INNOHEP).

## Regenerating expected.json from a truth file

```
python3 tests/tools/anonymise-rx.py expected \
    --truth /home/claude/rx240/truth.json --truth /home/claude/rx240/truth2.json \
    --private /home/claude/rx240/corpus-private
```

This needs the private `map.json`, which maps C numbers to R numbers. The
`budget` block of the current `expected.json` is kept.

## Adding new prescriptions

1. Put the new PDFs next to the private originals (R236.pdf, …). Extract the
   Chrome text (PDFium) into `txt/chrome/R236.txt` and the Firefox copy into
   `txt/firefox/R236.txt`.
2. Rebuild the truth: `truth/extract_truth.py` then `truth/build_truth.py`.
   Review every UNSURE dose by hand.
3. Append the new prescriptions. The C numbers continue after the last one and
   are shuffled among themselves, with a fresh seed. A prescription whose
   number is already in the corpus (a repeat PDF) is refused. Batch 2 was added
   like this:
   ```
   python3 tests/tools/anonymise-rx.py add --ids R236,R237,… \
       --src /home/claude/rx240/txt --src /home/claude/rx240/txt2 \
       --truth /home/claude/rx240/truth.json --truth /home/claude/rx240/truth2.json \
       --private /home/claude/rx240/corpus-private \
       --filename-map /home/claude/rx240/private-map.json --grep-root tests
   ```
   `add` refuses a `--private` folder inside the repository. It verifies the
   whole corpus and exits 1 on a leak. Read `verification-detail.log` in the
   private folder, fix the anonymiser, and run it again. To reproduce the
   corpus byte for byte, run `build --seed <batch 1 seed> --src …/txt`, then
   `add --seed <batch 2 seed> --ids <its R ids>`. The seeds are in
   `corpus-private/seed.json` and the R ids in `map.json`.
4. Check 10 files by hand:
   `anonymise-rx.py sidebyside --src … --private … --n 10 [--ids R…]`. The
   output holds real data, so read it but do not save it.
5. Update the counts in the first test of `rx-corpus.test.js` (311 / 572). Run
   `npm test`. New files can add new noise. Only then set the budget to the new
   totals, and note in `budget._note` which prescriptions were added.

Never store the originals, `map.json`, `seed.json` or the detailed log inside
the repository.
