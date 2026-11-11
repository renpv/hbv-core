#!/usr/bin/env python3
"""Medidas complementares: bibliografias x biografias, duplicatas, estrutura das páginas dos meses."""
import csv, os, re, sys, collections, unicodedata, subprocess, random
csv.field_size_limit(10**7)
here = os.path.dirname(os.path.abspath(__file__))
C = list(csv.DictReader(open(os.path.join(here, 'classificacao.tsv'), encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE))

def norm(t):
    t = re.sub(r'\s*\((Bibliografia|Discografia|Hist[óo]ria)\)\s*$', '', t, flags=re.I)
    t = re.sub(r'^(Pe|Rev|Frei|Dom|Dr|Mons|Prof)\.?\s+', '', t.strip())
    t = unicodedata.normalize('NFKD', t).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9]+', ' ', t).strip()

pessoas = {norm(r['titulo']): r['ID'] for r in C if r['tipo'] == 'hbv_pessoa'}
for tp in ('bibliografia', 'discografia'):
    R = [r for r in C if r['tipo'] == tp]
    hit = [r for r in R if norm(r['titulo']) in pessoas]
    print(f'{tp}: {len(R)} páginas; {len(hit)} têm biografia com o mesmo nome ({round(100*len(hit)/max(1,len(R)))}%)')
    sem = [r['titulo'] for r in R if norm(r['titulo']) not in pessoas]
    print('   sem biografia (amostra):', '; '.join(sem[:8]))

print('\n== títulos repetidos dentro do mesmo tipo')
by = collections.defaultdict(list)
for r in C: by[(r['tipo'], norm(r['titulo']))].append(r)
dups = {k: v for k, v in by.items() if len(v) > 1}
print('grupos:', len(dups), '| posts envolvidos:', sum(len(v) for v in dups.values()))
for (tp, n), v in list(dups.items())[:12]:
    print(f'  {tp:15s} {v[0]["titulo"][:45]:45s} IDs', ','.join(x['ID'] for x in v))

print('\n== lugares que citam outro nome ("também chamado/conhecido", "ou simplesmente")')
alias = [r for r in C if r['tipo'] == 'hbv_lugar' and re.search(r'tamb[ée]m (chamad|conhecid)|ou simplesmente|, ou (o|a) ', r['snippet'])]
print(len(alias), 'ex.:', '; '.join(f"{r['titulo']}" for r in alias[:8]))

print('\n== slugs atuais que viram endereços novos por tipo')
for tp in ('hbv_pessoa','hbv_lugar','hbv_logradouro','hbv_instituicao','hbv_obra'):
    print(f'  {tp:16s} {sum(1 for r in C if r["tipo"]==tp):4d} posts -> /{ {"hbv_pessoa":"biografias","hbv_lugar":"lugares","hbv_logradouro":"logradouros","hbv_instituicao":"instituicoes","hbv_obra":"obras"}[tp]}/<slug>/')
