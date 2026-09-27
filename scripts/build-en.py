#!/usr/bin/env python3
"""Build the English homepage from the Russian source and the shared dictionary.

No third-party dependencies. Fail on missing translations instead of producing a
partially translated page. Run from any directory: python3 scripts/build-en.py.
"""
import html
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
messages = json.loads((ROOT / 'i18n/en.json').read_text(encoding='utf-8'))

def translate(value):
    if not re.search('[А-Яа-яЁё]', value):
        return value
    key = html.unescape(value.strip())
    if key not in messages:
        raise ValueError(f'Missing translation: {key}')
    start = len(value) - len(value.lstrip())
    end = len(value.rstrip())
    return value[:start] + html.escape(messages[key], quote=True) + value[end:]

def build():
    source = (ROOT / 'index.html').read_text(encoding='utf-8')
    result = []
    for part in re.split(r'(<[^>]*>)', source):
        if part.startswith('<'):
            part = re.sub(r'((?:content|alt|title|aria-label|placeholder)=")([^"]*)(")',
                          lambda m: m[1] + translate(m[2]) + m[3], part)
        else:
            part = translate(part)
        result.append(part)
    page = ''.join(result).replace('<html lang="ru" data-home-language="ru">',
                                  '<html lang="en" data-home-language="en">')
    page = page.replace('aria-label="Русский" aria-current="true"', 'aria-label="Русский"')
    page = page.replace('aria-label="English">EN', 'aria-label="English" aria-current="true">EN')
    page = page.replace('href="/dkp/?lang=ru"', 'href="/dkp/?lang=en"')
    (ROOT / 'en').mkdir(exist_ok=True)
    (ROOT / 'en/index.html').write_text(page, encoding='utf-8')

if __name__ == '__main__':
    build()
