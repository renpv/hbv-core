#!/usr/bin/env python3
"""
Lê as 12 páginas de mês ("JANEIRO" ... "DEZEMBRO") e separa o que cada uma tem:

- datas-pessoas.tsv: quem "nasceu" e "morreu" em cada dia, com o slug da biografia do link.
  Serve para preencher dia e mês de nascimento e falecimento nas fichas (hbv_pessoa).
- plano-fatos.jsonl: um fato (hbv_fato) por item do "Programa de eventos do mês".
- meses.json: o texto de abertura e a bibliografia de cada mês.

Uso: python3 dividir_meses.py conteudo-meses.json pasta-de-saida
(conteudo-meses.json vem de: exportar_conteudo.py classificacao.tsv conteudo-meses.json fato_mes)
"""
import html
import json
import re
import sys
from collections import Counter

MESES = {'janeiro': 1, 'fevereiro': 2, 'marco': 3, 'abril': 4, 'maio': 5, 'junho': 6, 'julho': 7,
         'agosto': 8, 'setembro': 9, 'outubro': 10, 'novembro': 11, 'dezembro': 12}

RE_SECAO = re.compile(r'(NASCERAM\s+NESSE\s+M[ÊE]S|MORRERAM\s+NESSE\s+M[ÊE]S|PROGRAMA\s+DE\s+EVENTOS\s+DO\s+M[ÊE]S|BIBLIOGRAFIA)\s*:', re.I)
RE_CABECALHO = re.compile(r'<ul>\s*<li[^>]*>\s*<span style="text-decoration: underline;">(.*?)</span>\s*</li>\s*</ul>', re.S)
RE_LI = re.compile(r'<li[^>]*>(.*?)</li>', re.S)
RE_DIA = re.compile(r'^\s*(\d{1,2})\s*(?:º|°|o)?\s+de\s+([A-Za-zÀ-ú]+)', re.I)
RE_LINK = re.compile(r'<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>', re.S)
RE_ANO = re.compile(r'(?:\b(?:em|de|desde)\s+|\s[-–]\s*)(1[5-9]\d\d|20\d\d)\b')


def texto(h):
    t = re.sub(r'<[^>]+>', ' ', h)
    t = html.unescape(t)
    return re.sub(r'\s+', ' ', t).strip()


def limpar_item(t):
    return re.sub(r'\s*[;.,]+\s*$', '', t).replace(' ,', ',').replace(' .', '.').strip()


NOMES_MES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto',
             'setembro', 'outubro', 'novembro', 'dezembro']


def tema_do_fato(t):
    if re.search(r'campanha', t, re.I):
        return 'saude'
    if re.search(r'festividade|festa|padroeir|par[óo]quia|igreja|capela|folia|santu[áa]rio|congrega', t, re.I):
        return 'religiosidade'
    if re.search(r'cart[óo]rio|munic[íi]pio|distrito|\blei\b|decreto|c[âa]mara|prefeitura', t, re.I):
        return 'historia-do-municipio'
    return 'cultura'


def slug_do_link(href):
    m = re.match(r'https?://[^/]+/([^?#]+?)/?$', href)
    return m.group(1) if m else ''


def secoes(conteudo):
    """Divide o conteúdo em abertura + seções nomeadas."""
    partes = RE_SECAO.split(conteudo)
    abertura = partes[0]
    out = {}
    for i in range(1, len(partes) - 1, 2):
        nome = partes[i].upper()
        chave = ('nasceram' if nome.startswith('NASC') else 'morreram' if nome.startswith('MORR')
                 else 'programa' if nome.startswith('PROG') else 'bibliografia')
        out[chave] = partes[i + 1]
    return abertura, out


def blocos(secao):
    """Devolve [(cabeçalho, html_até_o_próximo_cabeçalho)], e o que vem antes do 1º cabeçalho."""
    ms = list(RE_CABECALHO.finditer(secao))
    res = []
    for k, m in enumerate(ms):
        fim = ms[k + 1].start() if k + 1 < len(ms) else len(secao)
        res.append((texto(m.group(1)), secao[m.end():fim]))
    return res


def limpar_abertura(h):
    # Tira o "⇒" e o fecho de tags soltas que ficaram antes do nome da seção.
    h = re.sub(r'<strong>\s*(<span[^>]*>\s*)*(<em>\s*)?(<span[^>]*>\s*)?⇒?\s*(</span>\s*)*$', '', h.strip())
    # O tema já põe o título "As informações básicas": tira o parágrafo que o repetia.
    h = re.sub(r'^\s*<p[^>]*>\s*<strong>\s*AS INFORMA[ÇC][ÕO]ES B[ÁA]SICAS:?\s*</strong>\s*</p>', '', h.strip(), flags=re.I)
    return h.strip()


def main():
    origem, pasta = sys.argv[1], sys.argv[2]
    dados = json.load(open(origem, encoding='utf-8'))
    pessoas, fatos, meses, avisos = [], [], {}, []
    cont = Counter()

    for pid, p in sorted(dados.items(), key=lambda kv: MESES.get(kv[1]['name'], 99)):
        mes = MESES.get(p['name'])
        if not mes:
            avisos.append(f'{pid}: slug "{p["name"]}" não é mês')
            continue
        abertura, sec = secoes(p['content'])
        for falta in ('nasceram', 'morreram', 'programa', 'bibliografia'):
            if falta not in sec:
                avisos.append(f'{p["name"]}: sem a seção {falta}')

        biblio = [texto(li) for li in RE_LI.findall(sec.get('bibliografia', ''))]
        meses[mes] = {'id_pagina': int(pid), 'slug': p['name'], 'titulo': p['title'],
                      'abertura_html': limpar_abertura(abertura), 'bibliografia': biblio}

        # Nasceram / morreram
        for tipo, chave in (('nascimento', 'nasceram'), ('falecimento', 'morreram')):
            for cab, corpo in blocos(sec.get(chave, '')):
                md = RE_DIA.match(cab)
                if not md:
                    avisos.append(f'{p["name"]}/{chave}: cabeçalho sem dia: "{cab}"')
                    continue
                dia = int(md.group(1))
                if MESES.get(re.sub(r'[ç]', 'c', md.group(2).lower())) not in (mes, None):
                    avisos.append(f'{p["name"]}/{chave}: "{cab}" é de outro mês')
                for li in RE_LI.findall(corpo):
                    links = RE_LINK.findall(li)
                    nome = limpar_item(texto(li))
                    apelido = ''
                    m_ap = re.search(r'\(([^)]+)\)\s*$', nome)
                    if m_ap:
                        apelido = m_ap.group(1).strip()
                        nome = nome[:m_ap.start()].strip()
                    slug = slug_do_link(links[0][0]) if links else ''
                    pessoas.append({'mes': mes, 'dia': dia, 'tipo': tipo, 'slug': slug,
                                    'nome': nome, 'apelido': apelido})
                    cont[tipo] += 1
                    if not slug:
                        cont[tipo + '_sem_link'] += 1

        # Programa de eventos
        ordem = 0
        prog = sec.get('programa', '')
        for cab, corpo in blocos(prog):
            md = RE_DIA.match(cab)
            if md:
                dia = int(md.group(1))
                itens = RE_LI.findall(corpo.split('</ol>')[0]) if '<ol' in corpo else []
                if not itens:
                    avisos.append(f'{p["name"]}/programa: "{cab}" sem itens')
                for li in itens:
                    ordem += 1
                    t = limpar_item(texto(li))
                    ma = RE_ANO.search(t)
                    fatos.append({'id_pagina': int(pid), 'mes': mes, 'dia': dia, 'ordem': ordem,
                                  'ano': int(ma.group(1)) if ma else None, 'titulo': t,
                                  'conteudo_html': '<p>' + re.sub(r'\s*[;.,]+\s*$', '', li.strip()) + '.</p>',
                                  'links': [slug_do_link(h) for h, _ in RE_LINK.findall(li)],
                                  'fontes': biblio, 'grupo': 'dia', 'tema': tema_do_fato(t)})
                    cont['fato_dia'] += 1
            else:
                # Bloco sem dia (ex.: "Campanha Governamental"): um fato do mês, sem dia.
                ordem += 1
                corpo_limpo = corpo.strip()
                fatos.append({'id_pagina': int(pid), 'mes': mes, 'dia': None, 'ordem': ordem, 'ano': None,
                              'titulo': (f'Campanhas governamentais de {NOMES_MES[mes]}'
                                         if re.match(r'campanha', cab, re.I) else f'{cab} ({NOMES_MES[mes]})'),
                              'conteudo_html': corpo_limpo, 'tema': tema_do_fato(cab),
                              'links': [slug_do_link(h) for h, _ in RE_LINK.findall(corpo_limpo)],
                              'fontes': biblio, 'grupo': 'mes'})
                cont['fato_mes'] += 1

    with open(f'{pasta}/datas-pessoas.tsv', 'w', encoding='utf-8') as f:
        f.write('mes\tdia\ttipo\tslug\tnome\tapelido\n')
        for r in pessoas:
            f.write('\t'.join(str(r[k]) for k in ('mes', 'dia', 'tipo', 'slug', 'nome', 'apelido')) + '\n')
    with open(f'{pasta}/plano-fatos.jsonl', 'w', encoding='utf-8') as f:
        for r in fatos:
            f.write(json.dumps(r, ensure_ascii=False) + '\n')
    json.dump(meses, open(f'{pasta}/meses.json', 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

    for k, v in sorted(cont.items()):
        print(f'{k}\t{v}')
    print(f'avisos\t{len(avisos)}')
    for a in avisos:
        print('  ' + a)


if __name__ == '__main__':
    main()
