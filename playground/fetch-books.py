# SPDX-FileCopyrightText: 2026 Felix Kurth
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Downloads the sample books of the Playground blueprint into a directory.

    python3 playground/fetch-books.py <directory>

- Three public-domain EPUBs from Project Gutenberg.
- Pepper&Carrot episodes 1-3 by David Revoy (CC BY 4.0), packed as CBZ with a ComicInfo.xml that names the author
  and the license.

The files go to the branch `playground` (see playground/README.md).
"""
import re
import sys
import urllib.request
import zipfile

OUT = sys.argv[1]
GUTENBERG = [
    (11, 'alice-in-wonderland.epub'),
    (84, 'frankenstein.epub'),
    (22367, 'die-verwandlung.epub'),
]
EPISODES = [
    (1, 'ep01_Potion-of-Flight', 'Potion of Flight'),
    (2, 'ep02_Rainbow-potions', 'Rainbow Potions'),
    (3, 'ep03_The-secret-ingredients', 'The Secret Ingredients'),
]
PEPPER = 'https://www.peppercarrot.com/0_sources/'


def get(url):
    with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'ebookreader-playground'})) as r:
        return r.read()


for number, name in GUTENBERG:
    with open(f'{OUT}/{name}', 'wb') as f:
        f.write(get(f'https://www.gutenberg.org/ebooks/{number}.epub3.images'))
    print(name)

for num, folder, title in EPISODES:
    listing = get(PEPPER + folder + '/low-res/').decode('utf8', 'replace')
    names = sorted(set(re.findall(rf'en_Pepper-and-Carrot_by-David-Revoy_E{num:02d}P(\d\d)\.jpg', listing)))
    pages = []
    for p in names:
        data = get(f'{PEPPER}{folder}/low-res/en_Pepper-and-Carrot_by-David-Revoy_E{num:02d}P{p}.jpg')
        if len(data) > 10000:  # the last page of an episode can be an empty placeholder
            pages.append(data)
    info = f'''<?xml version="1.0" encoding="utf-8"?>
<ComicInfo xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
  <Title>{title}</Title>
  <Series>Pepper&amp;Carrot</Series>
  <Number>{num}</Number>
  <Writer>David Revoy</Writer>
  <Penciller>David Revoy</Penciller>
  <Genre>Fantasy, Humor</Genre>
  <LanguageISO>en</LanguageISO>
  <Web>https://www.peppercarrot.com</Web>
  <Summary>Pepper&amp;Carrot by David Revoy, www.peppercarrot.com. License: Creative Commons Attribution 4.0 (CC BY 4.0), https://creativecommons.org/licenses/by/4.0/</Summary>
  <PageCount>{len(pages)}</PageCount>
</ComicInfo>
'''
    name = f'pepper-and-carrot-{num:02d}.cbz'
    with zipfile.ZipFile(f'{OUT}/{name}', 'w', zipfile.ZIP_STORED) as z:
        z.writestr('ComicInfo.xml', info)
        for i, data in enumerate(pages):
            z.writestr(f'{i:03d}.jpg', data)
    print(name, len(pages), 'pages')
