#!/usr/bin/env python3
"""
Casa cada linha de datas-pessoas.tsv (saída de dividir_meses.py) com uma biografia (hbv_pessoa):
primeiro pelo slug do link, depois por um slug antigo (_wp_old_slug) e, por fim, pelo nome normalizado
quando ele é único.

Uso (na pasta analise do hbv-local):
  ./scripts/wp.sh db query "SELECT p.ID, p.post_name, p.post_title, IFNULL((SELECT GROUP_CONCAT(meta_value
    SEPARATOR ',') FROM wp_postmeta m WHERE m.post_id=p.ID AND m.meta_key='_wp_old_slug'),'') FROM wp_posts p
    WHERE p.post_type='hbv_pessoa' AND p.post_status='publish'" --skip-column-names > analise/pessoas.tsv
  python3 casar_pessoas.py pessoas.tsv datas-pessoas.tsv datas-pessoas-casadas.tsv
"""
import csv
import re
import sys
import unicodedata
from collections import Counter

csv.field_size_limit(10**8)


def norm(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()
    s = re.sub(r'\b(dr|dra|pe|padre|prof|profa|frei|dom|mons|irma|sr|sra|min|cel)\b\.?', ' ', s)
    s = re.sub(r'\b(d[aeo]s?)\b', ' ', s)
    return ' '.join(re.sub(r'[^a-z ]', ' ', s).split())


def main():
    arq_pessoas, arq_datas, arq_saida = sys.argv[1], sys.argv[2], sys.argv[3]
    pes, por_nome, velhos = {}, {}, {}
    for r in csv.reader(open(arq_pessoas, encoding='utf-8'), delimiter='\t'):
        if len(r) < 3:
            continue
        pid, slug, titulo = r[0], r[1], r[2]
        old = r[3] if len(r) > 3 else ''
        pes[slug] = pid
        por_nome.setdefault(norm(titulo), []).append(pid)
        for o in filter(None, old.split(',')):
            velhos[o] = pid

    c, falhas = Counter(), []
    with open(arq_saida, 'w', encoding='utf-8') as out:
        out.write('id\tmes\tdia\ttipo\tcomo\tslug\tnome\n')
        for r in csv.DictReader(open(arq_datas, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE):
            pid, como = '', ''
            if r['slug'] in pes:
                pid, como = pes[r['slug']], 'slug'
            elif r['slug'] in velhos:
                pid, como = velhos[r['slug']], 'slug_antigo'
            else:
                cand = por_nome.get(norm(r['nome']), [])
                if len(cand) == 1:
                    pid, como = cand[0], 'nome'
                elif len(cand) > 1:
                    como = 'nome_ambiguo'
            c[como or 'nenhum'] += 1
            if not pid:
                falhas.append(f"{r['mes']}/{r['dia']}\t{r['tipo']}\t{r['slug']}\t{r['nome']}\t{como}")
            out.write('\t'.join([pid, r['mes'], r['dia'], r['tipo'], como or 'nenhum', r['slug'], r['nome']]) + '\n')

    print(dict(c))
    print('\n'.join(falhas))


if __name__ == '__main__':
    main()
