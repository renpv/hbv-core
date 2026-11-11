#!/usr/bin/env python3
"""Exploração dos posts: categorias, combinações e padrões de título."""
import csv, re, sys, collections, os
csv.field_size_limit(10**7)
here = os.path.dirname(os.path.abspath(__file__))
rows = list(csv.DictReader(open(os.path.join(here, 'posts.tsv'), encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE))
print('posts', len(rows))

cats = collections.Counter()
combos = collections.Counter()
for r in rows:
    cs = (r['cats'] or 'NULL').split(',')
    for c in cs: cats[c] += 1
    combos[','.join(cs)] += 1
print('\n== categorias:', dict(cats.most_common()))
multi = [k for k in combos if ',' in k]
print('\n== posts em mais de 1 categoria:', sum(combos[k] for k in multi))
for k, v in combos.most_common():
    if ',' in k: print(f'  {v:4d}  {k}')

def first_word(t):
    t = t.strip()
    m = re.match(r"([A-Za-zÀ-ÿ\.]+)", t)
    return (m.group(1) if m else '?')

print('\n== primeiras palavras do título por categoria (top 12)')
by = collections.defaultdict(collections.Counter)
upper = collections.Counter()
for r in rows:
    for c in (r['cats'] or 'NULL').split(','):
        by[c][first_word(r['title'])] += 1
        t = re.sub(r'[^A-Za-zÀ-ÿ]', '', r['title'])
        if t and t == t.upper() and len(t) > 3: upper[c] += 1
for c, n in cats.most_common():
    print(f'  {c} ({n}; títulos em CAIXA ALTA: {upper[c]}):', ', '.join(f'{w}={k}' for w, k in by[c].most_common(12)))

print('\n== tamanho do texto (caracteres) por categoria: mediana / p10 / p90')
for c, n in cats.most_common():
    L = sorted(int(r['len']) for r in rows if c in (r['cats'] or 'NULL').split(','))
    q = lambda p: L[min(len(L)-1, int(p*len(L)))]
    print(f'  {c:22s} {q(.5):6d} / {q(.1):6d} / {q(.9):6d}')

print('\n== marcadores por categoria (% dos posts): bio, lei, denom, infobas, biblio')
for c, n in cats.most_common():
    R = [r for r in rows if c in (r['cats'] or 'NULL').split(',')]
    pct = lambda k: round(100*sum(r[k]=='1' for r in R)/len(R))
    print(f'  {c:22s} bio={pct("bio"):3d} lei={pct("lei"):3d} denom={pct("denom"):3d} infobas={pct("infobas"):3d} biblio={pct("biblio"):3d}')

print('\n== ano de publicação')
print(' ', dict(sorted(collections.Counter(r['d'][:4] for r in rows).items())))
print('== ano da última modificação')
print(' ', dict(sorted(collections.Counter(r['m'][:4] for r in rows).items())))
