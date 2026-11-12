#!/usr/bin/env python3
"""
Divide as páginas de Bibliografia e Discografia em uma obra por livro ou disco.

Entrada: classificacao.tsv (tipos bibliografia/discografia) e um JSON com o conteúdo dos posts
(gerado por exportar_conteudo.sh). Saída: plano-obras.jsonl (uma linha por obra) e
plano-autores.jsonl (uma linha por página de autor), mais um resumo de qualidade no terminal.

Nada é gravado no site: o comando "wp hbv migracao obras" aplica o plano.
"""
import csv, html, json, os, re, sys, collections

csv.field_size_limit(10**8)
CLASSIF, CONTEUDO, SAIDA = sys.argv[1], sys.argv[2], sys.argv[3]

C = {r['ID']: r for r in csv.DictReader(open(CLASSIF, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE)
     if r['tipo'] in ('bibliografia', 'discografia')}
POSTS = json.load(open(CONTEUDO, encoding='utf-8'))  # {id: {title, name, content}}

FICHA = {
    'ano': r'Ano(?: de (?:publica[çc][ãa]o|lan[çc]amento))?',
    'edicao': r'Edi[çc][ãa]o',
    'editora': r'(?:Editora|Gravadora|Selo|Produtora)',
    'isbn': r'ISBN',
    'paginas': r'P[áa]ginas',
    'tiragem': r'Tiragem',
    'estudio': r'Est[úu]dio',
    'musicas': r'(?:M[úu]sicas|Faixas)',
}

def texto(h):
    h = re.sub(r'\[caption[^\]]*\]|\[/caption\]', ' ', h)
    h = re.sub(r'<br\s*/?>|</p>|\\n|\n', '\n', h)
    h = re.sub(r'<[^>]+>', '', h)
    h = html.unescape(h)
    return re.sub(r'[ \t\xa0]+', ' ', h).strip()

def limpar_valor(v):
    return re.sub(r'\s+', ' ', v).strip().rstrip('.').strip()

ITEM = re.compile(r'(\d{1,3})\s*[ºª°o]?\s*\)\s*(?:T[ÍI]TULO|[ÁA]LBUM)\s*:', re.I)

obras, autores, problemas = [], [], collections.Counter()
for pid, cl in C.items():
    p = POSTS.get(pid)
    if not p:
        problemas['post sem conteúdo exportado'] += 1
        continue
    c = p['content']
    tipo = cl['tipo']
    # Cabeçalho do autor: Nome, Formação e link "Biografia".
    cab = c[:ITEM.search(c).start()] if ITEM.search(c) else c
    nome = re.search(r'Nome\s*:\s*(.+?)(?:\\n|\n|<|$)', cab)
    form = re.search(r'Forma[çc][ãa]o\s*:\s*(.+?)(?:\\n|\n|<|$)', cab)
    bio = re.search(r'<a [^>]*href="[^"]*/([^/"]+)/"[^>]*>\s*Biografia\s*</a>', cab, re.I)
    autor_nome = limpar_valor(html.unescape(re.sub(r'<[^>]+>', '', nome.group(1)))) if nome else re.sub(r'\s*\((Bibliografia|Discografia)\)\s*$', '', p['title'], flags=re.I).strip()
    pos = [m.start() for m in ITEM.finditer(c)]
    autores.append({
        'id_pagina': pid, 'slug_pagina': p['name'], 'tipo': tipo, 'autor': autor_nome,
        'formacao': limpar_valor(html.unescape(re.sub(r'<[^>]+>', '', form.group(1)))) if form else '',
        'slug_biografia': bio.group(1) if bio else '', 'itens': len(pos),
    })
    if not pos:
        problemas[f'{tipo}: página sem itens TÍTULO'] += 1
        continue
    pos.append(len(c))
    for i in range(len(pos) - 1):
        bloco = c[pos[i]:pos[i + 1]]
        t = texto(bloco)
        m = re.match(r'\d{1,3}\s*[ºª°o]?\s*\)\s*(?:T[ÍI]TULO|[ÁA]LBUM)\s*:\s*(.+?)(?:\n|$)', t, re.I)
        titulo = limpar_valor(m.group(1)) if m else ''
        if not titulo:
            problemas['item sem título legível'] += 1
        res = re.search(r'RESENHA\s*:\s*(.+?)(?=\n\s*(FICHA|\d{1,3}\s*[ºª°o]?\s*\)|BIBLIOGRAFIA|$))', t, re.I | re.S)
        resenha = res.group(1).strip() if res else ''
        if not resenha:
            problemas['item sem resenha'] += 1
        # HTML da resenha (mantém links internos e ênfases).
        rh = re.search(r'RESENHA\s*:\s*(.+?)(?=FICHA (?:CATALOGR|T[ÉE]CNICA)|\d{1,3}\s*[ºª°o]?\s*\)\s*(?:T[ÍI]TULO|[ÁA]LBUM)|<strong>\s*BIBLIOGRAFIA|$)', bloco, re.I | re.S)
        resenha_html = re.sub(r'^\s*</?(p|strong)[^>]*>|\s*<p[^>]*>\s*$', '', rh.group(1).strip()) if rh else ''
        img = re.search(r'wp-image-(\d+)', bloco)
        # Conteúdo completo da obra, sem a linha do título: nada do original se perde.
        conteudo = re.sub(r'^.*?(?:T[ÍI]TULO|[ÁA]LBUM)\s*:.*?(?:</strong>)?\s*(?:</p>)?', '', bloco, count=1, flags=re.I | re.S).strip()
        conteudo = re.sub(r'^\s*(?:<strong>)?[^<]*?(?:</strong>)\s*(?:</p>)?\s*', '', conteudo, count=1)
        campos = {}
        for k, rx in FICHA.items():
            mm = re.search(rf'(?:^|\n)\s*{rx}\s*:\s*(.+?)(?:\n|$)', t, re.I)
            if mm:
                campos[k] = limpar_valor(mm.group(1))
        if not campos:
            problemas['item sem ficha catalográfica'] += 1
        # Ficha com rótulo vazio ("Editora:" sem valor) vira vazio, não o rótulo seguinte.
        for k in list(campos):
            if re.match(r'^(Ano|Edi|Editora|ISBN|P[áa]ginas|Tiragem|Est[úu]dio|M[úu]sicas|Gravadora)\b.*:', campos[k]):
                campos[k] = ''
        obras.append({
            'id_pagina': pid, 'ordem': i + 1, 'tipo': tipo, 'autor': autor_nome,
            'slug_biografia': autores[-1]['slug_biografia'], 'titulo': titulo,
            'capa_id': img.group(1) if img else '', **{k: campos.get(k, '') for k in FICHA},
            'tem_resenha': bool(resenha), 'conteudo_html': conteudo,
        })

os.makedirs(SAIDA, exist_ok=True)
def gravar(nome, linhas, campos):
    with open(os.path.join(SAIDA, nome), 'w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, fieldnames=campos, delimiter='\t', quoting=csv.QUOTE_NONE, escapechar='\\', extrasaction='ignore')
        w.writeheader()
        for l in linhas:
            w.writerow({k: re.sub(r'[\t\r\n]+', ' ', str(v).replace('\\', '')) for k, v in l.items()})
# JSON Lines: o HTML de cada obra vai inteiro, com quebras de linha e barras.
with open(os.path.join(SAIDA, 'plano-obras.jsonl'), 'w', encoding='utf-8') as f:
    for o in obras:
        f.write(json.dumps(o, ensure_ascii=False) + '\n')
with open(os.path.join(SAIDA, 'plano-autores.jsonl'), 'w', encoding='utf-8') as f:
    for a in autores:
        f.write(json.dumps(a, ensure_ascii=False) + '\n')

# Resumo de qualidade.
by = collections.Counter(o['tipo'] for o in obras)
print('páginas:', collections.Counter(a['tipo'] for a in autores), '| obras:', dict(by))
print('autores com link de biografia:', sum(1 for a in autores if a['slug_biografia']), 'de', len(autores))
for tp in ('bibliografia', 'discografia'):
    O = [o for o in obras if o['tipo'] == tp]
    if not O: continue
    pct = lambda k: round(100 * sum(1 for o in O if o[k]) / len(O))
    print(f'{tp}: título={pct("titulo")}% resenha={pct("tem_resenha")}% capa={pct("capa_id")}% ' +
          ' '.join(f'{k}={pct(k)}%' for k in FICHA))
print('problemas:', dict(problemas))
