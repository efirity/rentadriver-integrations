#!/usr/bin/env python3
"""Export WordPress banners from the approved master and the icon from the brand mark.

Needs ImageMagick (`magick`) and the `sharp` npm package: either installed in this folder's `node_modules`
(`npm install sharp`) or in a directory named by the `SHARP_DIR` environment variable.
"""
from pathlib import Path
import argparse
import os
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--banners-only', action='store_true', help='Export banners without rebuilding the unchanged SVG icon')
args = parser.parse_args()

here = Path(__file__).resolve().parent
design = here / 'design'
assets = here / 'assets'
assets.mkdir(exist_ok=True)
sharp_dir = Path(os.environ.get('SHARP_DIR') or here)

def magick(*args):
    subprocess.run(['magick', *map(str, args)], check=True)

def render_mark(path, size):
    # Sharp uses librsvg, which preserves the identity's inherited strokes and paths.
    subprocess.run(['node', '-e', '''const sharp = require('node:module').createRequire(process.argv[1] + '/')('sharp');
sharp(process.argv[2], {density: 432}).resize(Number(process.argv[4])).png().toFile(process.argv[3]);''',
        str(sharp_dir), str(design / 'mark.svg'), str(path), str(size)], check=True)

# Keep the approved artwork separate from the upload-ready WordPress assets.
# Re-running this exporter must not restore the previous banner design.
source = design / 'banner-source.png'
large = assets / 'banner-1544x500.png'
magick(source, '-resize', '1544x500^', '-gravity', 'center',
       '-extent', '1544x500', '-strip', large)
magick(large, '-resize', '772x250', '-strip', assets / 'banner-772x250.png')
if not args.banners_only:
    render_mark(assets / 'icon-256x256.png', 256)
print(f'Rendered directory assets in {assets}')
