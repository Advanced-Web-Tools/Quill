"""Refresh the editor's font metadata; no font binaries or API keys are stored."""
import argparse
import datetime
import json
from pathlib import Path
import urllib.request

SOURCE = 'https://fonts.google.com/metadata/fonts'
ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser()
parser.add_argument('--input', type=Path, help='Use a previously downloaded Google metadata response.')
args = parser.parse_args()
raw = args.input.read_text() if args.input else urllib.request.urlopen(SOURCE, timeout=30).read().decode()
metadata = json.loads(raw.removeprefix(")]}'\n"))
families = []
for entry in metadata['familyMetadataList']:
    variants = sorted((str(key) for key in entry['fonts'] if str(key).rstrip('i').isdigit()), key=lambda key: (key.endswith('i'), int(key.rstrip('i'))))
    if not variants:
        continue
    axis = next((axis for axis in entry.get('axes', []) if axis['tag'] == 'wght'), None)
    family = {'family': entry['family'], 'category': entry['category'], 'variants': variants,
              'subsets': [subset for subset in entry.get('subsets', []) if subset != 'menu']}
    if axis:
        family['weightRange'] = [axis['min'], axis['max']]
    families.append(family)
families.sort(key=lambda entry: entry['family'])
result = {'source': SOURCE, 'updated': datetime.date.today().isoformat(), 'families': families}
target = ROOT / 'Quill/resources/google-fonts.json'
target.write_text(json.dumps(result, ensure_ascii=False, separators=(',', ':')) + '\n')
print(f'Wrote {len(families)} font families to {target} ({target.stat().st_size} bytes).')
