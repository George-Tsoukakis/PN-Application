# Bundled third-party libraries

This directory bundles two libraries by Nicola Asuni / Tecnick.com
(LGPL-3.0), used **only** for server-side GS1 DataMatrix generation
(`includes/class-qrrp-datamatrix.php`, type `DATAMATRIX,S,GS1`). Everything
else in them is bundled for completeness, not because the plugin calls it.

| Library | Upstream |
| --- | --- |
| `tc-lib-barcode/` | https://github.com/tecnickcom/tc-lib-barcode |
| `tc-lib-color/`   | https://github.com/tecnickcom/tc-lib-color |

## Provenance

Both trees are upstream **release tags**, unmodified except for the private
namespace prefix described below.

| Library | Tag | Upstream tag commit |
|---|---|---|
| `tc-lib-barcode` | **2.16.4** | `e0b07db25cd223cacdfcfc1af728585781bd2bda` |
| `tc-lib-color`   | **3.0.7**  | `8455d371882d2e3eb2742d4d6fb82ca5aebb95db` |

Upgraded in 2.15.5 from 2.14.0 / 3.0.2. The reason was a conformance fix in
the barcode library: 2.14.0 computed the DataMatrix pad codewords (ISO/IEC
16022 §5.2.3, 253-state randomising) with a 0-based position instead of the
1-based one, so every symbol with more than one pad codeword carried wrong pad
values. Scanners ignore pads, so existing labels read correctly, but the symbols
were not strictly conformant. 2.16.x also fixes EDIFACT/X12 look-ahead and
end-of-data encoding. The prefixed trees were produced with the same
`token_get_all()` rewrite (134 + 19 files, 437 + 74 references); the same
script reproduces the previous 2.14.0 / 3.0.2 bundle byte for byte.

The tags are identified by git commit rather than archive hash, because the
archive download was not reachable from the build environment. Check them with
`git clone … && git -C tc-lib-barcode rev-parse 2.16.4^{commit}`.

The two are a pair: `tc-lib-barcode` 2.16.x requires `tc-lib-color` ^3.0 and
calls `withInvertedColor()`, which older `tc-lib-color` releases lack. Upgrade
them together.

### Reproducing the comparison

Strip the prefix from a copy and diff against the tag; the result must be
empty (a raw diff shows hundreds of prefix-only changes and is not a check):

```
git clone -q https://github.com/tecnickcom/tc-lib-barcode tc-lib-barcode-2.16.4
git -C tc-lib-barcode-2.16.4 checkout -q 2.16.4   # commit must equal the value above

cp -r <this>/tc-lib-barcode /tmp/cmp
grep -rlZ 'QRRPVendor' /tmp/cmp | xargs -0 sed -i 's/QRRPVendor\\//g'
diff -r tc-lib-barcode-2.16.4/src /tmp/cmp/src && diff tc-lib-barcode-2.16.4/LICENSE /tmp/cmp/LICENSE && echo IDENTICAL
```

Same for `tc-lib-color` with tag `3.0.7`.

## Namespace scoping (`QRRPVendor\`)

Every `Com\Tecnick\…` name is rewritten to `QRRPVendor\Com\Tecnick\…` in both
libraries. The public name is shared by anyone who bundles the same library,
and PHP declares a class once per request, so without the prefix another
plugin's copy could end up producing this plugin's GS1 codes. With the prefix
that collision cannot occur.

The rewrite is done with `token_get_all()` on name tokens only
(`T_NAME_QUALIFIED`, `T_NAME_FULLY_QUALIFIED`, `T_STRING`), never comments or
strings: 153 files, 511 references (2.15.5). The libraries build no class names
dynamically, so the rename is fully checked by the engine. Do not replace the
trees with unprefixed upstream copies.

`autoload-qrrp.php` is a minimal PSR-4 loader for the two prefixed namespaces.
It only loads files that resolve (via `realpath()`) inside the corresponding
`src/` directory, so crafted class names, `..` segments and symlinks pointing
outside the tree are ignored.

## Output verification and upgrade conditions

`QRRP_DataMatrix` does not trust the encoder. After rendering, it reads the
modules back from the final PNG, extracts the codewords with the ISO/IEC 16022
placement algorithm, checks Reed-Solomon, requires the leading FNC1 (232), and
decodes the data with its own decoder (written from the standard, not from this
library) to confirm it equals the requested payload. Any failure refuses the
label. No private library method is used.

The decoder implements ASCII, C40, TEXT, X12 and EDIFACT and refuses Base 256,
ECI, Macro 05/06 and Structured Append, none of which a GS1 charset-82 payload
needs. Before accepting any upgrade of either library:

1. Download the new **tag** (never a branch snapshot), record its commit hash
   (and the archive sha256 when the archive is reachable) above, re-apply the
   prefix rewrite, and confirm the strip-and-diff comparison is empty.
2. Upgrade `tc-lib-barcode` and `tc-lib-color` together if the barcode library
   needs newer colour APIs.
3. Check that every class referenced by the bundle (parents, interfaces,
   property/parameter/return types, including union types) is present. Types
   used only in signatures are resolved lazily and a missing file shows up only
   as a fatal on the exact line that needs it.
4. Keep the `QRRPVendor\` prefix in `QRRP_DataMatrix::library_path()`, which
   names the class as a string.
5. Run the plugin's DataMatrix regression tests. A failure with
   `qrrp_dm_undecodable` after an upgrade most likely means the encoder now
   chooses an encodation the decoder does not implement; extend the decoder
   from the standard rather than relaxing the check.
6. Regenerate `CHECKSUMS.sha256` (below).
7. Update `QRRP_Vendor_Check::INSTALLED` in `includes/class-qrrp-vendor-check.php`
   (the settings card and the `t_vendor_check` test read it).

## Integrity

`CHECKSUMS.sha256` lists a SHA-256 for every file shipped in this directory
except itself and this note. To confirm nothing has been altered:

```
cd wp-content/plugins/qr-rebuilder-pro/vendor
sha256sum -c CHECKSUMS.sha256
```

Regenerate it after any deliberate change:

```
find tc-lib-barcode tc-lib-color autoload-qrrp.php -type f | sort | xargs sha256sum > CHECKSUMS.sha256
```

## Error reporting

User-facing error messages never include the path of the loaded library; that
message can reach any visitor when guest access is enabled. Administrators see
the path under Tools → Site Health, and it is written to the debug log when
`WP_DEBUG` is on.
