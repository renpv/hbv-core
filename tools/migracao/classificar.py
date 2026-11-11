#!/usr/bin/env python3
"""
Classificação dos posts do acervo em tipos do HBV Core (simulação, nada é gravado no site).

Três sinais por post: padrão do título, início do texto e categoria atual.
Confiança: alta (título e texto ou categoria concordam), media (um sinal só),
baixa (só a categoria, ou sinais em conflito).
Saída: classificacao.tsv e um resumo no terminal.
"""
import csv, os, re, sys, collections, unicodedata
csv.field_size_limit(10**7)
here = os.path.dirname(os.path.abspath(__file__))
entrada = sys.argv[1] if len(sys.argv) > 1 else os.path.join(here, 'posts.tsv')
saida_dir = sys.argv[2] if len(sys.argv) > 2 else here
rows = list(csv.DictReader(open(entrada, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE))

I = re.IGNORECASE
MESES = 'JANEIRO|FEVEREIRO|MARÇO|ABRIL|MAIO|JUNHO|JULHO|AGOSTO|SETEMBRO|OUTUBRO|NOVEMBRO|DEZEMBRO'
PAGINAS = {'EDITORIAL', 'OS DIREITOS AUTORAIS', 'QUEM SOMOS'}

# (regex do título, tipo, termo). A primeira regra que casa vence.
TITULO = [
    (r'\(Bibliografia\)', 'bibliografia', 'literatura'),
    (r'^Hist[óo]ria (da Forma[çc][ãa]o Pol[íi]tica )?d[oa] Distrito', 'post', 'historia-do-distrito'),
    (r'^(Top[ôo]nimo|A rela[çc][ãa]o d|Nomenclatura|Galeria dos|M[úu]sicos formados|Regimento d|Repert[óo]rio d|Primeiras-damas)', 'post', 'lista'),
    (r'^(O |A )?(Reisado|Maneiro-Pau|Caretas|Sert[ãa]o Junino|"Sert[ãa]o Junino|Teoria)', 'post', 'cultura'),
    (r'^Largo\b', 'hbv_lugar', 'praca'),
    (r'^Vila\b', 'hbv_lugar', 'bairro'),
    (r'^(O |A )?(Marco Zero|Letreiro|Casar[ãa]o|Imagem de|Imagem sacra)', 'hbv_lugar', 'ponto-turistico*'),
    (r'^Centro de (Esportes|Lazer)', 'hbv_lugar', 'equipamento-esportivo*'),
    (r'^(Sal[ãa]o Paroquial|Centro Pastoral|Casa Pastoral|Acampamento|Casa de Acolhida|Minist[ée]rio)\b', 'hbv_instituicao', 'igrejas-e-templos'),
    (r'^(Centro de Refer[êe]ncia|Centro Administrativo|Centro de Abastecimento|Centro Vocacional|Promotoria|(A )?Penitenci[áa]ria|Cozinha Comunit|Lavanderia|Departamento|Companhia|Garagem|Camel[óo]dromo|Subesta[çc][ãa]o|Servi[çc]o Aut[ôo]nomo|Mercado|Esta[çc][ãa]o Elevat[óo]ria)', 'hbv_instituicao', 'servicos-publicos*'),
    (r'^(Bradesco|Caixa Econ[ôo]mica|Banco do|Boa Viagem Agroindustrial|Barraca|Cabana)\b', 'hbv_instituicao', 'comercio-e-servicos*'),
    (r'Clube\b|Club\b', 'hbv_instituicao', 'clubes-e-casas-de-shows'),
    (r'^(Cap[íi]tulo|Tri[âa]ngulo)\b|DeMolay|Ma[çc][ôo]nic', 'hbv_instituicao', 'associacoes*'),
    (r'^(Casa da Educa[çc][ãa]o|Organiza[çc][ãa]o Educacional|Patronato|(O )?Audit[óo]rio|N[úu]cleo de Arte)', 'hbv_instituicao', 'escolas'),
    (r'^(O |A )?Posto da Sa[úu]de', 'hbv_instituicao', 'saude'),
    (r'^(A |O |Os )?(Bandeira|Bras[ãa]o|Selo|Hino|S[íi]mbolos)\b', 'post', 'simbolos'),
    (r'\(Discografia\)', 'discografia', 'musica'),
    (r'\(Hist[óo]ria\)', 'post', 'historia-de-instituicao'),
    (rf'^({MESES})$', 'fato_mes', ''),
    (r'^(Ruas|Rela[çc][ãa]o d)', 'post', 'lista'),
    (r'^(Rua|Avenida|Travessa|Beco)\b', 'hbv_logradouro', ''),
    (r'^Bairro\b', 'hbv_lugar', 'bairro'),
    (r'^Distrito d', 'hbv_lugar', 'distrito'),
    (r'^(A )?Pra[çc]a\b', 'hbv_lugar', 'praca'),
    (r'^Cemit[ée]rio\b', 'hbv_lugar', 'necropole'),
    (r'^(A[çc]ude|Barragem)\b', 'hbv_lugar', 'acude'),
    (r'^(Rio|Riacho)\b', 'hbv_lugar', 'rio*'),
    (r'^(CE|BR)-\d', 'hbv_lugar', 'acesso'),
    (r'^(Loteamento|Conjunto Habitacional|Conjunto)\b', 'hbv_lugar', 'bairro'),
    (r'^(Quadra|Gin[áa]sio|Est[áa]dio|Campo de Futebol|Areninha|Arena)\b', 'hbv_lugar', 'equipamento-esportivo*'),
    (r'^(Est[áa]tua|Ef[íi]gie|Mastro|Monumento|Obelisco|Marco)\b', 'hbv_lugar', 'ponto-turistico*'),
    (r'^(Serrote|Pedra|Cachoeir|Mirante|Gruta|Lagoa|Serra|Penha|Parque|S[íi]tio Arqueol)', 'hbv_lugar', 'ponto-turistico*'),
    (r'^(Escola|Creche|Instituto|Centro de Educa[çc][ãa]o|Col[ée]gio|Externato|Liceu|Grupo Escolar|EEF|EEM|EEIEF)\b', 'hbv_instituicao', 'escolas'),
    (r'^(Polo|Universidade|Faculdade|IFCE|Campus)\b', 'hbv_instituicao', 'ensino-tecnico-e-superior'),
    (r'^(A |O )?(Capela|Igreja|Congrega[çc][ãa]o|Par[óo]quia|Templo|Santu[áa]rio|Convento|Casa Paroquial|Sal[ãa]o do Reino|Assembleia de Deus|Centro Esp[íi]rita|Casa de Ora[çc][ãa]o|Comunidade)\b', 'hbv_instituicao', 'igrejas-e-templos'),
    (r'^(Posto de Sa[úu]de|Unidade B[áa]sica|UBS|Hospital|Policl[íi]nica|Centro de Sa[úu]de|CAPS|Farm[áa]cia|Laborat[óo]rio|Cl[íi]nica|Casa de Sa[úu]de|Maternidade|SAMU\b|Unidade de Pronto|UPA\b|Unidade de Refer[êe]ncia|Casa de Apoio|Centro de Reabilita|Centro de Especialidades|Servi[çc]o de)', 'hbv_instituicao', 'saude'),
    (r'^Posto de Pol[íi]cia|^(Delegacia|Cadeia|Quartel|Guarda|F[óo]rum|C[âa]mara|Secretaria|Prefeitura|Correios|SAAE|Esta[çc][ãa]o de Tratamento|Terminal|Matadouro|Mercado P[úu]blico)\b', 'hbv_instituicao', 'servicos-publicos*'),
    (r'^Cart[óo]rio\b', 'hbv_instituicao', 'cartorio'),
    (r'^(Hotel|Pousada|Motel|Hospedaria)\b', 'hbv_instituicao', 'hospedagem'),
    (r'^(Clube|Casa de Show|Boate|Balne[áa]rio|Parque Aqu[áa]tico|Vaquejada|Parque de Vaquejada)\b', 'hbv_instituicao', 'clubes-e-casas-de-shows'),
    (r'^(R[áa]dio|TV|Jornal)\b', 'hbv_instituicao', 'comunicacao*'),
    (r'^(Auto Posto|Posto)\b|^(F[áa]brica|Ind[úu]stria|Cer[âa]mica|Distribuidora|Pedreira|Ag[êe]ncia|Banco|Cooperativa|Loja|Supermercado|Padaria|Empresa|Triunfo)\b', 'hbv_instituicao', 'comercio-e-servicos*'),
    (r'^(Associa[çc][ãa]o|Sindicato|Sociedade|Funda[çc][ãa]o|Conselho|Loja Ma[çc][ôo]nica|Rotary|Lions)\b', 'hbv_instituicao', 'associacoes*'),
    (r'^(Biblioteca|Museu|Teatro|Cine|Casa de Cultura|Centro Cultural|Banda)\b', 'hbv_instituicao', 'cultura*'),
    (r'^(Administra[çc][ãa]o d|Secret[áa]rios|Secretariado|Comiss[õo]es|Comiss[ãa]o|Elei[çc][õo]es|Legislatura)', 'post', 'historia-politica'),
    (r'^Calouros', 'post', 'educacao'),
]

# Sinais no início do texto.
def sinal_texto(sn):
    s = sn[:240]
    if re.search(r'^\S.{0,80}\bnasceu\b', s): return ('hbv_pessoa', '')
    if re.search(r'^\S.{0,80}\b(era filh[oa] de|era (agricultor|comerciante|dom[ée]stica|de nacionalidade)|contraiu matrim[ôo]nio|foi um agropecuarista|possui origem incerta|provavelmente seja|chegou ao (Munic|Cear)|casad[oa] com|Foi casad[oa])', s): return ('hbv_pessoa', '')
    if re.search(r'INFORMA[ÇC][ÕO]ES B[ÁA]SICAS: Nome: .{3,160} A DISCOGRAFIA', s): return ('discografia', 'musica')
    if re.search(r'INFORMA[ÇC][ÕO]ES B[ÁA]SICAS: Nome: .{3,160} A BIBLIOGRAFIA', s): return ('bibliografia', 'literatura')
    if re.search(r'INFORMA[ÇC][ÕO]ES B[ÁA]SICAS SOBRE (O|A) AUTOR', s): return ('bibliografia', 'literatura')
    if re.search(r'\(Ilustrador\)', s[:160]): return ('hbv_obra', 'cronicas-e-poesias')
    if re.search(r'é (uma|um) (pequen[ao] )?(localidade|povoado|vila|agrovila)\b|A localidade de|é uma pequena localidade', s): return ('hbv_lugar', 'localidade')
    if re.search(r'^(AS )?INFORMA[ÇC][ÕO]ES B[ÁA]SICAS: (O|A|Os|As) [^,]{2,40} está localizad[oa] na zona rural', s): return ('hbv_lugar', 'localidade?')
    if re.search(r's[íi]tio arqueol[óo]gico|potencial(idade)? tur[íi]stic|equipamento tur[íi]stico|local com forte', s, I): return ('hbv_lugar', 'ponto-turistico*')
    if re.search(r'A TURMA QUE INGRESSOU', s): return ('post', 'educacao')
    return None

# Destino padrão por categoria (quando título e texto não decidem).
CATEGORIA = {
    'biografias': ('hbv_pessoa', ''), 'administracao': ('post', 'historia-politica'),
    'distritos': ('hbv_lugar', 'localidade'), 'avenidas-e-ruas': ('hbv_logradouro', ''),
    'bairros': ('hbv_lugar', 'bairro'), 'pracas': ('hbv_lugar', 'praca'), 'necropole': ('hbv_lugar', 'necropole'),
    'recursos-hidricos': ('hbv_lugar', 'acude'), 'acesso': ('hbv_lugar', 'acesso'),
    'literatura': ('bibliografia', 'literatura'), 'cronicas-e-poesias': ('hbv_obra', 'cronicas-e-poesias'),
    'comendas-e-titulos': ('hbv_obra', 'comendas-e-titulos'), 'mapas': ('hbv_obra', 'mapas'),
    'datas-e-fatos': ('hbv_fato', ''), 'religiosidade': ('hbv_instituicao', 'igrejas-e-templos'),
    'saude': ('hbv_instituicao', 'saude'), 'educacao': ('post', 'educacao'), 'turismo': ('post', 'turismo'),
    'economia': ('post', 'economia'), 'cultura': ('post', 'cultura'), 'simbolos-municipais': ('post', 'simbolos'),
    'historia': ('post', 'historia-do-municipio'), 'sem-categoria': ('post', 'editorial'), 'quem-somos': ('pagina', ''),
}
# Categorias cujo destino natural é compatível com cada tipo (para medir concordância).
COMPAT = {
    'hbv_pessoa': {'biografias', 'administracao', 'literatura', 'cultura', 'religiosidade', 'educacao', 'sem-categoria', 'NULL'},
    'hbv_lugar': {'religiosidade', 'economia', 'saude', 'cultura', 'acesso', 'distritos', 'bairros', 'pracas', 'necropole', 'recursos-hidricos', 'acesso', 'turismo', 'datas-e-fatos', 'educacao', 'simbolos-municipais', 'sem-categoria'},
    'hbv_logradouro': {'avenidas-e-ruas', 'distritos', 'sem-categoria', 'bairros'},
    'hbv_instituicao': {'administracao', 'datas-e-fatos', 'cronicas-e-poesias', 'literatura', 'avenidas-e-ruas', 'pracas', 'necropole', 'simbolos-municipais', 'historia', 'educacao', 'religiosidade', 'saude', 'bairros', 'distritos', 'turismo', 'economia', 'acesso', 'cultura', 'sem-categoria', 'comendas-e-titulos', 'recursos-hidricos'},
    'hbv_obra': {'cronicas-e-poesias', 'comendas-e-titulos', 'mapas', 'literatura', 'sem-categoria'},
    'bibliografia': {'literatura', 'sem-categoria'}, 'discografia': {'cultura'},
    'post': set(CATEGORIA), 'fato_mes': {'datas-e-fatos'}, 'hbv_fato': {'datas-e-fatos'}, 'pagina': set(CATEGORIA),
}


# Tema (hbv_tema) a partir da categoria atual, seguindo o mapa do site (RESUMO-DO-SITE.md).
TEMA = {
    'biografias': 'cultura', 'literatura': 'cultura', 'cronicas-e-poesias': 'cultura', 'cultura': 'cultura',
    'datas-e-fatos': 'cultura', 'necropole': 'cultura', 'comendas-e-titulos': 'cultura', 'religiosidade': 'religiosidade',
    'distritos': 'historia-do-municipio', 'historia': 'historia-do-municipio', 'mapas': 'historia-do-municipio',
    'simbolos-municipais': 'simbolos', 'administracao': 'historia-politica',
    'bairros': 'a-cidade', 'avenidas-e-ruas': 'a-cidade', 'pracas': 'a-cidade', 'acesso': 'a-cidade',
    'educacao': 'educacao', 'saude': 'saude', 'turismo': 'turismo', 'economia': 'economia',
    'recursos-hidricos': 'recursos-hidricos', 'sem-categoria': 'editorial', 'quem-somos': 'editorial', 'NULL': '',
}

def caixa_alta(t):
    l = re.sub(r'[^A-Za-zÀ-ÿ]', '', t)
    return len(l) > 3 and l == l.upper()

out = []
for r in rows:
    t, sn = r['title'].strip(), r['snippet']
    cats = (r['cats'] or 'NULL').split(',')
    cat = next((c for c in cats if c != 'sem-categoria'), cats[0])
    tit = next(((tp, term) for rx, tp, term in TITULO if re.search(rx, t, 0 if rx.startswith('^(Rua') else I)), None)
    tex = sinal_texto(sn)
    if t.upper() in PAGINAS or cat == 'quem-somos':
        tipo, term, conf, motivo = 'pagina', '', 'alta', 'página institucional'
    elif tit and tit[0] == 'fato_mes':
        tipo, term, conf, motivo = 'fato_mes', '', 'alta', 'página do mês: separar em fatos'
    elif caixa_alta(t) and not tit:
        tipo, term, conf, motivo = 'post', 'panorama', 'alta', 'título em caixa alta: texto panorâmico da seção'
    elif tit and tex and tex[1] == 'localidade?':
        tipo, term = tit; conf, motivo = 'alta', 'título prevalece (texto só diz zona rural)'
    elif tit and tex:
        if tit[0] == tex[0]:
            tipo, term = tit[0], tit[1] or tex[1]; conf, motivo = 'alta', 'título e texto concordam'
        elif tex[0] == 'hbv_pessoa' and tit[0] in ('hbv_logradouro',):
            tipo, term, conf, motivo = tit[0], tit[1], 'media', 'logradouro com texto biográfico do homenageado'
        elif tex[0] in ('hbv_pessoa', 'hbv_obra', 'bibliografia', 'discografia') or (tex[1] == 'localidade' and tit[0] == 'hbv_instituicao'):
            tipo, term = tex; conf, motivo = 'media', f'texto prevalece sobre o título ({tit[0]})'
        else:
            tipo, term = tit; conf, motivo = 'baixa', f'conflito: título→{tit[0]} texto→{tex[0]}'
    elif tit:
        tipo, term = tit
        conf, motivo = ('alta', 'título + categoria') if cat in COMPAT.get(tipo, ()) else ('media', 'só título')
    elif tex:
        tipo, term = tex[0], tex[1].rstrip('?')
        conf, motivo = ('alta', 'texto + categoria') if cat in COMPAT.get(tipo, ()) else ('media', 'só texto')
    else:
        tipo, term = CATEGORIA.get(cat, ('post', ''))
        # Biografia sem "nasceu" no começo, mas com marcadores biográficos no texto.
        nome = re.match(r"^((Pe|Rev|Frei|Dom|Dr|Mons|Cel|Cap|Ten|Maj|Sr|Srª|Prof)\.? )?[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõçü]+( (d[aeo]s?|e|[A-ZÁÉÍÓÚÂÊÔÃÕÇ][a-záéíóúâêôãõçü'-]+))+$", t)
        if tipo == 'hbv_pessoa' and (nome or r['bio'] == '1'):
            conf, motivo = 'alta' if nome else 'media', 'categoria + título de nome próprio' if nome else 'categoria + marcadores biográficos'
        elif tipo == 'bibliografia' and cat == 'literatura' and 'Nome:' not in sn[:120]:
            tipo, term, conf, motivo = 'hbv_obra', 'cronicas-e-poesias', 'media', 'texto autoral na categoria Literatura'
        elif tipo == 'hbv_obra' and cat == 'cronicas-e-poesias':
            conf, motivo = 'alta', 'categoria de crônicas (texto autoral)'
        elif tipo == 'post' and int(r['len']) > 1500:
            conf, motivo = 'alta', 'artigo da seção (sem padrão de ficha)'
        else:
            conf, motivo = 'baixa', 'só a categoria'
    out.append({**r, 'cat': cat, 'tipo': tipo, 'termo': term, 'conf': conf, 'motivo': motivo})

with open(os.path.join(saida_dir, 'classificacao.tsv'), 'w', encoding='utf-8', newline='') as f:
    w = csv.writer(f, delimiter='\t')
    w.writerow(['ID', 'titulo', 'cats', 'tipo', 'termo', 'confianca', 'motivo', 'snippet'])
    for o in out:
        w.writerow([o['ID'], o['title'], o['cats'], o['tipo'], o['termo'], o['conf'], o['motivo'], o['snippet'][:200]])

# Plano de conversão lido por "wp hbv migracao converter". Termos com * são novos (criados pelo plugin).
with open(os.path.join(saida_dir, 'plano.tsv'), 'w', encoding='utf-8', newline='') as f:
    w = csv.writer(f, delimiter='\t', quoting=csv.QUOTE_NONE, escapechar='\\')
    w.writerow(['id', 'slug', 'tipo', 'termo', 'tema', 'confianca', 'titulo'])
    for o in out:
        tema = TEMA.get(o['cat'], '')
        w.writerow([o['ID'], o['post_name'], o['tipo'], o['termo'].rstrip('*?'), tema, o['conf'], re.sub(r'[\t\n]', ' ', o['title'])])

C = collections.Counter
print('== por tipo e confiança')
by = collections.defaultdict(C)
for o in out: by[o['tipo']][o['conf']] += 1
for tp, c in sorted(by.items(), key=lambda x: -sum(x[1].values())):
    print(f"  {tp:16s} {sum(c.values()):5d}  alta={c['alta']:4d} media={c['media']:4d} baixa={c['baixa']:4d}")
print('  TOTAL', len(out), dict(C(o['conf'] for o in out)))
print('\n== termos propostos (os com * são novos)')
for (tp, term), n in C((o['tipo'], o['termo']) for o in out if o['termo']).most_common():
    print(f'  {tp:16s} {term:28s} {n}')
print('\n== categoria atual → tipo proposto')
for cat in sorted({o['cat'] for o in out}, key=lambda c: -sum(1 for o in out if o['cat'] == c)):
    c = C(o['tipo'] for o in out if o['cat'] == cat)
    print(f'  {cat:20s}', ', '.join(f'{k}={v}' for k, v in c.most_common()))
print('\n== motivos das confianças baixa/média')
for (cf, m), n in C((o['conf'], o['motivo']) for o in out if o['conf'] != 'alta').most_common():
    print(f'  {cf:5s} {n:4d}  {m}')
