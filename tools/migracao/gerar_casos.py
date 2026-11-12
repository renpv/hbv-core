#!/usr/bin/env python3
"""
Monta o TSV de casos da Revisão editorial (importado pela tela "Revisão editorial > Importar e exportar"
ou por `wp hbv revisao importar`) a partir dos relatórios da migração. Ver docs/REVISAO.md.

1. Exportar do banco o que o script precisa (só leitura):
     IDS=$(tail -n +2 links-2-nao-resolvidos.tsv | cut -f1 | sort -u | paste -sd,)
     wp eval-file wp-content/plugins/hbv-core/tools/migracao/exportar_revisao.php "$IDS" > dados-revisao.json
2. Gerar os casos:
     python3 gerar_casos.py --dados dados-revisao.json --host https://www.historiadeboaviagem.com.br \
         --papel papel-incertos.tsv --papel-res papel-res.jsonl \
         --links links-2-nao-resolvidos.tsv --meses-pessoas meses-2-pessoas.tsv \
         --fichas fichas-datas-final.tsv --casadas datas-pessoas-casadas.tsv \
         --plano-obras plano-obras.jsonl --manuais casos-manuais.tsv --saida casos.tsv

--host é o endereço do site onde os casos serão importados: os links quebrados do relatório foram gravados
com o endereço do ambiente local (--host-local) e precisam do endereço do destino para serem achados no texto.
Colunas de saída: chave tipo post_id resumo evidencia acao parametros (com \\t, \\n e \\\\ escapados).
A chave é o md5 de tipo|post_id|detalhe: importar de novo o mesmo arquivo não duplica casos.
"""
import argparse
import csv
import hashlib
import html
import json
import re
import unicodedata
from collections import Counter, defaultdict

csv.field_size_limit(10**8)
MESES = ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro',
         'outubro', 'novembro', 'dezembro']
VERBO = {'nascimento': 'nasceu', 'falecimento': 'morreu'}


def esc(s):
    return str(s).replace('\\', '\\\\').replace('\t', '\\t').replace('\r', '').replace('\n', '\\n')


def norm(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()
    return ' '.join(re.sub(r'[^a-z0-9 ]', ' ', s).split())


def texto(h):
    h = re.sub(r'\[/?(caption|gallery|embed)[^\]]*\]', ' ', h)  # shortcodes de legenda e galeria
    return re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', h))).strip()


def data_ext(dia, mes):
    return f'{int(dia)} de {MESES[int(mes)]}'


class Casos:
    def __init__(self):
        self.linhas, self.chaves, self.cont = [], set(), Counter()

    def add(self, tipo, post_id, detalhe, resumo, evidencia, acao='nenhuma', parametros=None):
        chave = hashlib.md5(f'{tipo}|{post_id}|{detalhe}'.encode()).hexdigest()
        if chave in self.chaves:
            return
        self.chaves.add(chave)
        self.cont[tipo] += 1
        self.linhas.append([chave, tipo, str(post_id or 0), resumo[:250], evidencia, acao,
                            json.dumps(parametros or {}, ensure_ascii=False)])


def ler_tsv(arq, cab=True):
    with open(arq, encoding='utf-8') as f:
        linhas = list(csv.reader(f, delimiter='\t', quoting=csv.QUOTE_NONE))
    return linhas[1:] if cab else linhas


def main():
    ap = argparse.ArgumentParser()
    for a in ('dados', 'host', 'papel', 'papel-res', 'links', 'meses-pessoas', 'fichas', 'casadas',
              'plano-obras', 'manuais', 'saida'):
        ap.add_argument('--' + a, required=a not in ('manuais',))
    ap.add_argument('--host-local', default='http://localhost:8088')
    o = ap.parse_args()
    d = json.load(open(o.dados, encoding='utf-8'))
    pes = {int(k): v for k, v in d['pessoas'].items()}
    host = o.host.rstrip('/')
    C = Casos()

    # 1. Papel na história incerto (a leitura automática respondeu "incerto")
    evid = {}
    for l in open(o.papel_res, encoding='utf-8'):
        r = json.loads(l)
        evid[(r['id'], r['papel'])] = r.get('evidencia', '')
    for pid, nome, papel in (r[:3] for r in ler_tsv(o.papel, cab=False) if len(r) >= 3):
        e = evid.get((pid, papel), '')
        C.add('papel', int(pid), papel, f'Confirmar se {nome} foi {papel}',
              'A leitura automática do texto não teve certeza. Trecho encontrado:\n«' + e + '…»\n\n'
              'Se o texto confirmar, aplique a sugestão. Se não, rejeite.',
              'termo', {'taxonomia': 'hbv_papel', 'termo': papel})

    # 2. Links quebrados (relatório <lote>-nao-resolvidos.tsv do comando links)
    for r in ler_tsv(o.links):
        pid, titulo, href = int(r[0]), r[1], r[2]
        de = href.replace(o.host_local, host, 1) if href.startswith(o.host_local) else href
        cont = d['conteudos'].get(str(pid), '')
        contexto, ancora = '', ''
        m = re.search(r'<a\b[^>]*href=["\']' + re.escape(href) + r'["\'][^>]*>(.*?)</a>', cont, re.S)
        if m:
            ancora = texto(m.group(1))
            antes = texto(cont[max(0, m.start() - 600):m.start()])[-200:]
            depois = texto(cont[m.end():m.end() + 600])[:200]
            contexto = f'…{antes} [{ancora or "link na imagem"}] {depois}…'
        caminho = re.sub(r'^https?://[^/]+', '', de)
        C.add('link_quebrado', pid, de, f'Link quebrado em “{titulo}”: {caminho}',
              f'Texto do link: «{ancora or "(imagem ou sem texto)"}»\nEndereço que não existe mais: {de}\n\n'
              f'Trecho:\n{contexto}\n\n'
              'Informe o endereço certo e aplique, ou marque “remover o link e manter o texto”.',
              'link', {'de': de, 'para': ''})

    # 3. Nomes sem biografia e páginas de mês com duas datas (relatório <lote>-pessoas.tsv do comando meses)
    sem_bio, conflito = defaultdict(list), defaultdict(set)
    for r in csv.DictReader(open(o.meses_pessoas, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE):
        dia, mes = r['data_do_mes'].split('/')
        if r['resultado'] == 'sem_biografia':
            sem_bio[r['nome']].append(f'{VERBO[r["tipo"]]} em {data_ext(dia, mes)} (página de {MESES[int(mes)]})')
        elif r['resultado'] == 'conflito_interno':
            conflito[(int(r['id']), r['tipo'])].add((int(mes), int(dia)))
    for nome, itens in sem_bio.items():
        C.add('sem_biografia', 0, norm(nome), f'{nome} aparece nas páginas de mês, mas não tem biografia',
              'As antigas páginas de mês diziam:\n- ' + '\n- '.join(itens) + '\n\n'
              'Essas páginas viraram rascunho e a data só volta ao site se houver uma biografia. Se houver fonte, '
              'crie a biografia como rascunho (Biografias > Adicionar) e envie para aprovação; depois marque '
              '“Resolvi à mão”. Se não houver, rejeite.')
    for (pid, tipo), datas in conflito.items():
        f = pes.get(pid, {})
        datas_txt = ' e '.join(f'{data_ext(dd, mm)} (página de {MESES[mm]})' for mm, dd in sorted(datas))
        atual = f.get(f'hbv_{tipo}') or '(vazio)'
        dm = f'{f.get(f"hbv_{tipo}_dia") or "?"}/{f.get(f"hbv_{tipo}_mes") or "?"}'
        C.add('data_conflito', pid, tipo, f'{f.get("titulo", pid)}: duas datas de {tipo} nas páginas de mês',
              f'As páginas de mês traziam {datas_txt}.\nNa ficha hoje: {atual} (dia/mês usados em “Nasceram/Morreram '
              f'neste mês”: {dm}).\n\nConfira no texto e na fonte e corrija a ficha se precisar.')

    # 4. Data do texto diferente da página de mês (ficou a do texto)
    pag = defaultdict(set)
    for r in csv.DictReader(open(o.casadas, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE):
        if r['id']:
            pag[(int(r['id']), r['tipo'])].add((int(r['dia']), int(r['mes'])))
    for r in csv.DictReader(open(o.fichas, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE):
        if 'pagina_mes' in r['fonte']:
            continue
        m = re.match(r'(\d{1,2})/(\d{1,2})/(\d{4})$', r['valor'])
        if not m:
            continue
        pid, tipo = int(r['id']), r['campo']
        p = pag.get((pid, tipo))
        if not p or len(p) > 1:
            continue
        (dd, mm), = p
        if (dd, mm) == (int(m.group(1)), int(m.group(2))):
            continue
        f = pes.get(pid, {})
        C.add('data_divergente', pid, tipo,
              f'{f.get("titulo", pid)}: {tipo} em {data_ext(m.group(1), m.group(2))} (texto) ou {data_ext(dd, mm)} (página de mês)?',
              f'O texto da biografia dá {data_ext(m.group(1), m.group(2))} de {m.group(3)}; a antiga página de '
              f'{MESES[mm]} dava {data_ext(dd, mm)}. Ficou a data do texto.\nNa ficha hoje: {f.get(f"hbv_{tipo}") or "(vazio)"}.\n\n'
              'Se a página de mês estiver certa, corrija o texto e a ficha e marque “Resolvi à mão”; se o texto estiver '
              'certo, marque “Resolvi à mão” também (nada a mudar).')

    # 5. Obras sem capa, sem resenha e com título incompleto
    obras = defaultdict(list)
    for ob in d['obras']:
        obras[norm(ob['titulo'])].append(ob)
    for l in open(o.plano_obras, encoding='utf-8'):
        r = json.loads(l)
        cands = [ob for ob in obras.get(norm(r['titulo']), []) if ob['lote'] == 'obras-1']
        if len(cands) > 1:
            cands = [ob for ob in cands if norm(r['autor']) in norm(ob.get('autoria', ''))] or cands
        if len(cands) > 1:
            cands = [ob for ob in cands if ob['ordem'] == r['ordem']] or cands
        if len(cands) != 1:
            print('obra não achada:', r['titulo'], len(cands))
            continue
        ob = cands[0]
        tipo_obra = 'livro' if r['tipo'] == 'bibliografia' else 'disco'
        if not r.get('capa_id') and not ob['capa']:
            C.add('obra_capa', ob['id'], 'capa', f'Obra sem capa: {ob["titulo"]}',
                  f'A antiga página de {r["tipo"]} de {r["autor"]} não tinha imagem para este {tipo_obra} (ou a '
                  'imagem foi apagada). Se houver foto da capa, envie e defina como imagem destacada; depois marque '
                  '“Resolvi à mão”. Se não houver, rejeite.')
        if r['tipo'] == 'bibliografia' and not r.get('tem_resenha'):
            C.add('obra_resenha', ob['id'], 'resenha', f'Livro sem resenha: {ob["titulo"]}',
                  f'A antiga página de bibliografia de {r["autor"]} não trazia resenha deste livro; a página mostra só a '
                  'ficha. Escreva uma resenha curta, se quiser, ou rejeite (fica só a ficha).')
        if len(ob['titulo'].strip()) <= 2:
            C.add('obra_titulo', ob['id'], 'titulo', f'Título de obra incompleto: “{ob["titulo"]}”',
                  f'O título lido da página de {r["tipo"]} de {r["autor"]} ficou “{ob["titulo"]}”. Corrija o '
                  'título na obra e marque “Resolvi à mão”.')

    # 5b. Obras com o mesmo título (volumes, edições ou a mesma obra repetida por coautoria)
    for chave_t, grupo in sorted(obras.items()):
        grupo = [ob for ob in grupo if ob['status'] == 'publish']
        if len(grupo) < 2:
            continue
        grupo.sort(key=lambda ob: ob['id'])
        linhas = '\n'.join(f'- ID {ob["id"]}: autoria {ob.get("autoria") or "?"}; ano {ob.get("ano") or "?"}; '
                           f'edição {ob.get("edicao") or "?"}; editora/estúdio {ob.get("editora") or "?"}' for ob in grupo)
        C.add('duplicado', grupo[0]['id'], 'obra:' + chave_t,
              f'{len(grupo)} obras com o título “{grupo[0]["titulo"]}”',
              f'{linhas}\n\nPodem ser volumes ou edições diferentes (então está certo; marque “Resolvi à mão”, '
              'e se quiser acrescente o volume ou a edição ao título) ou a mesma obra repetida porque aparecia na página '
              'de mais de um autor. Nesse caso, avise o administrador quais IDs são a mesma obra: ele junta numa só, com '
              'todos os autores, e redireciona as outras.')

    # 6. Amostra da classificação automática dos fatos
    if d.get('fatos'):
        C.add('amostra', 0, 'temas-fatos', 'Conferir o tema dado automaticamente aos fatos (amostra de 25)',
              'Os 162 fatos das páginas de mês receberam um tema por regra de palavras. Confira esta amostra; se '
              'muitos estiverem errados, avise o administrador para refazer a regra.\n\n' +
              '\n'.join(f'- {f["titulo"][:110]} → {f["tema"] or "(sem tema)"} (ID {f["id"]})' for f in d['fatos']))

    # 7. Casos escritos à mão (duplicados, textos trocados etc.): tipo post_id resumo evidencia
    if o.manuais:
        for r in ler_tsv(o.manuais):
            tipo, pid, resumo, evidencia = r[0], int(r[1] or 0), r[2], r[3].replace('\\n', '\n')
            C.add(tipo, pid, resumo, resumo, evidencia)

    with open(o.saida, 'w', encoding='utf-8') as out:
        out.write('chave\ttipo\tpost_id\tresumo\tevidencia\tacao\tparametros\n')
        for ln in C.linhas:
            out.write('\t'.join(esc(x) for x in ln) + '\n')
    for k, v in sorted(C.cont.items()):
        print(f'{k}\t{v}')
    print(f'total\t{sum(C.cont.values())}')


if __name__ == '__main__':
    main()
