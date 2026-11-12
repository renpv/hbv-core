# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versões em
[SemVer](https://semver.org/lang/pt-BR/).

## [0.6.0] - 2026-11-12

### Adicionado
- `wp hbv migracao campos` aceita duas pseudo-chaves na coluna `campo`:
  - `post_parent`: define o pai de um post de tipo hierárquico (na prática `hbv_lugar`). O valor é o
    ID de um post publicado do mesmo tipo; recusa o próprio post e descendentes (sobe a cadeia de
    pais, até 50 passos). `preencher` grava só se o pai atual é 0; `sobrescrever` grava. Grava direto
    em `wp_posts.post_parent` (sem `post_modified`, revisão nem hooks de `save_post`) e limpa o cache
    do post. O relatório traz o permalink anterior e o novo.
  - `tax:<taxonomia>` (ex.: `tax:hbv_papel`): acrescenta um termo existente ao post (valor = slug; não
    cria termos; a taxonomia tem de ser do tipo do post). Usa `wp_set_object_terms` com acréscimo.
    Várias linhas do mesmo post com termos diferentes da mesma taxonomia são válidas.
- O registro `_hbv_migracao_campos` ganha as chaves `post_parent` (pai anterior, inteiro) e
  `tax:<taxonomia>` (lista de term_ids acrescentados pelo lote).

### Alterado
- `desfazer` restaura o pai (só se ainda é o gravado) e remove só os termos acrescentados pelo lote
  (se ainda estiverem no post), recontando os termos. `verificar` confere pai e termos. `status`
  conta um item por termo acrescentado. A regra de duplicatas deixa de recusar termos diferentes da
  mesma taxonomia no mesmo post.
- O permalink de um `hbv_lugar` muda quando o pai muda; `_hbv_caminho_antigo` continua resolvendo
  (o resolvedor usa `get_permalink`, que já devolve o endereço novo).

## [0.5.0] - 2026-11-12

### Adicionado
- `wp hbv migracao campos <arquivo.tsv> [--simular] [--lote=<nome>]`: grava campos da ficha (post
  meta) em qualquer tipo do plugin. TSV com cabeçalho `id campo valor modo fonte`; o campo tem de
  existir na ficha do tipo do post (exceto `hbv_fontes`) e o valor é validado conforme o tipo do
  campo (número no intervalo, opção de select, texto, url, ID de post do tipo certo). Modos
  `preencher` (só campo vazio; valor diferente vira conflito, só no relatório) e `sobrescrever`.
  Recusa linhas repetidas com valores diferentes e campos já gravados por outro lote não desfeito.
  Processa em blocos de 200 linhas. Relatório `<lote>.tsv` em `uploads/hbv-migracao/` e, com
  `--simular`, contagens por campo e 5 exemplos de conflito.
- Registro de desfazer `_hbv_migracao_campos` (JSON por lote: valor anterior e valor gravado de cada
  campo), escrito antes de alterar o campo.

### Alterado
- `desfazer` restaura os campos gravados por `campos` (apaga o meta que não existia; não restaura
  o campo que foi editado depois do lote, e avisa), `verificar` confere que cada campo gravado ainda
  tem o valor gravado e `status` mostra, por lote, os posts e os campos gravados.

## [0.4.0] - 2026-11-12

### Adicionado
- Campos de `hbv_pessoa`: dia, mês e ano de nascimento e de falecimento (`hbv_nascimento_dia`,
  `hbv_nascimento_mes`, `hbv_nascimento_ano` e os três de falecimento). Servem para "Nasceram
  neste mês" e "Hoje na história"; o campo de texto continua sendo o que aparece na ficha.
- `wp hbv migracao meses <plano-fatos.jsonl> <meses.json> <datas-pessoas-casadas.tsv>`: divide as
  12 páginas de mês em fatos (`hbv_fato`, um por item do "Programa de eventos do mês"), grava dia e
  mês de nascimento e falecimento nas biografias (sem sobrescrever: valor diferente vira conflito
  no relatório), guarda a abertura e a bibliografia de cada mês na option `hbv_meses` e passa a
  página para rascunho. Com `--simular`, lotes e dois relatórios TSV (`<lote>.tsv` e
  `<lote>-pessoas.tsv`).
- Caminhos fixos (`hbv_core_caminhos_fixos`): o módulo de redirecionamentos resolve endereços
  antigos que não são post nem termo (`/janeiro/` vai para `/datas-e-fatos/?hbv_mes=1`).
  Função pública `hbv_core_caminhos_fixos()`.
- `desfazer`, `verificar` e `status` passam a tratar fatos, datas das biografias, páginas de mês,
  `hbv_meses` e caminhos fixos.

### Alterado
- `hbv_core_resolver_caminho()` consulta os caminhos fixos depois de posts e termos.
- O registro `_hbv_migracao` de fato criado ganha `"tipo": "hbv_fato"`, e o da página de mês
  traz `"mes"`, para o `desfazer` e o `status` distinguirem fatos de obras e meses de autores.

## [0.3.0] - 2026-11-11

### Adicionado
- Taxonomia `hbv_autoria` ("Autoria", só em `hbv_obra`, endereço `/autoria/<termo>/`) com term
  meta `hbv_pessoa` (ID da biografia) e `hbv_formacao`.
- Campos de `hbv_obra`: edição, ISBN, páginas, tiragem, estúdio e músicas.
- `wp hbv migracao obras <plano-obras.jsonl> <plano-autores.jsonl>`: divide as páginas
  "Bibliografia" e "Discografia" em uma obra por livro ou disco, cria os termos de autoria,
  guarda a página original como rascunho e converte as páginas de banda sem discos em
  instituição. Com `--simular`, lotes e relatório TSV.
- `wp hbv migracao titulos <arquivo.tsv>`: aplica títulos revisados nas obras criadas pelo
  migrador (só mudança de maiúsculas e minúsculas), guardando o original.
- `desfazer`, `verificar` e `status` passam a tratar obras, páginas divididas e termos de autoria.

### Alterado
- `hbv_core_resolver_caminho()` também resolve `_hbv_caminho_antigo` de termos de `hbv_autoria`
  (301 do endereço antigo da página para a lista de obras do autor).
- A página dividida muda de status por atualização direta: não ganha revisão, `_encloseme`
  nem nova data de modificação, e o `desfazer` devolve o banco idêntico.

## [0.2.0] - 2026-11-11

### Adicionado
- Migrador WP-CLI (`wp hbv migracao converter|desfazer|status|verificar`), conforme
  `docs/MIGRADOR.md`: converte posts para os tipos do plugin a partir de um `plano.tsv`, com
  simulação, lotes, registro em `_hbv_migracao`, relatório TSV em `uploads/hbv-migracao/` e
  desfazer por lote.
- Módulo de redirecionamentos (`includes/redirecionamentos.php`): 301 dos endereços antigos de
  posts convertidos (`_hbv_caminho_antigo`) e dos arquivos de categoria antigos. Filtros
  `hbv_core_redirecionar` e `hbv_core_mapa_categorias`; função pública `hbv_core_resolver_caminho()`.
- Oito termos novos: tipo de lugar (`rio`, `ponto-turistico`, `equipamento-esportivo`) e tipo de
  instituição (`comercio-e-servicos`, `servicos-publicos`, `comunicacao`, `associacoes`, `cultura`).

## [0.1.0] - 2026-11-11

### Adicionado
- Modelo de conteúdo movido do tema `hbv-memoria` 1.0.0, sem mudança de comportamento:
  tipos (`includes/post-types.php`), taxonomias e termos padrão (`includes/taxonomies.php`)
  e campos da ficha (`includes/meta.php`).
- Ativação cria os termos padrão (idempotente) e atualiza os links permanentes.
- Proteção de transição: com o tema 1.0.x ativo, o plugin não carrega e avisa no painel.
