#!/usr/bin/env python3
"""
Rebuild the self-hosted fonts in assets/fonts (developer tool, not run on the server).

  pip install fonttools brotli
  npm pack material-symbols @fontsource/ibm-plex-sans @fontsource/ibm-plex-mono   # then extract the .tgz files
  python3 bin/build-fonts.py <dir containing the extracted packages>

The full Material Symbols font is ~3.7 MB. Only the icons this code base names are
kept (found by scanning the .php/.js sources), with opsz/wght/GRAD pinned to what
custom.css uses and only the FILL axis left variable: ~100 KB instead.
Run it again after adding an icon that is not in assets/fonts/icons.txt.
"""
import re, shutil, sys
from pathlib import Path
from fontTools.ttLib import TTFont
from fontTools.varLib import instancer
from fontTools import subset

root = Path(__file__).resolve().parent.parent
src = Path(sys.argv[1])
out = root / 'assets' / 'fonts'
out.mkdir(parents=True, exist_ok=True)

# 1. text fonts: just copy the Latin woff2 files we use
for pkg, files in {
    'fontsource-ibm-plex-sans-5.3.0': ['400-normal', '500-normal', '600-normal', '700-normal', '400-italic'],
    'fontsource-ibm-plex-mono-5.3.0': ['400-normal', '500-normal', '600-normal'],
}.items():
    fam = 'ibm-plex-sans' if 'sans' in pkg else 'ibm-plex-mono'
    for f in files:
        shutil.copy(src / pkg / 'package' / 'files' / f'{fam}-latin-{f}.woff2', out / f'{fam}-latin-{f}.woff2')

# 2. icon names used anywhere in the code
tokens = set()
for p in root.rglob('*'):
    if p.suffix not in ('.php', '.js') or any(x in p.parts for x in ('node_modules', 'vendor', 'dist', 'tests')):
        continue
    if p.name.startswith('index-'):
        continue
    tokens |= set(re.findall(r'[a-z][a-z0-9_]*', p.read_text(errors='ignore')))

font = TTFont(src / 'material-symbols-0.49.0' / 'package' / 'material-symbols-outlined.woff2')
font = instancer.instantiateVariableFont(font, {'opsz': 24, 'wght': 400, 'GRAD': 0}, inplace=False)

# 3. keep only the ligatures for icons we use
wanted = set()
gsub = font['GSUB'].table
for lookup in gsub.LookupList.Lookup:
    for st in lookup.SubTable:
        if getattr(st, 'ExtSubTable', None) is not None:
            st = st.ExtSubTable
        if st.__class__.__name__ != 'LigatureSubst':
            continue
        for first, ligs in list(st.ligatures.items()):
            keep = [l for l in ligs if l.LigGlyph in tokens]
            wanted |= {l.LigGlyph for l in keep}
            st.ligatures[first] = keep
        st.ligatures = {k: v for k, v in st.ligatures.items() if v}

# fontTools' subsetter expects an (even empty) gvar entry for every glyph
if 'gvar' in font:
    for g in font.getGlyphOrder():
        if g not in font['gvar'].variations:
            font['gvar'].variations[g] = []

opts = subset.Options()
opts.layout_features = ['liga', 'rlig', 'calt']
opts.flavor = 'woff2'
opts.notdef_outline = True
opts.glyph_names = False
opts.name_IDs = [1, 2]
sub = subset.Subsetter(opts)
sub.populate(unicodes=list(range(0x61, 0x7b)) + list(range(0x30, 0x3a)) + [0x5f],
             glyphs=sorted(wanted))
sub.subset(font)
font.flavor = 'woff2'
font.save(out / 'material-symbols-outlined.woff2')
(out / 'icons.txt').write_text('\n'.join(sorted(wanted)) + '\n')
print(f'{len(wanted)} icons -> {(out / "material-symbols-outlined.woff2").stat().st_size // 1024} KB')
