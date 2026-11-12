# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versões em
[SemVer](https://semver.org/lang/pt-BR/).

## [0.8.0] - 2026-11-12

### Adicionado
- Área **Revisão editorial** (menu de primeiro nível, ícone de visto, bolha com o número de pendentes):
  uma tela para a revisora decidir os casos duvidosos que a migração deixou (papel incerto, link
  quebrado, data divergente, nome sem biografia, duplicado, obra sem capa e outros). Cada caso é uma
  linha da nova tabela `{prefixo}hbv_revisao` (criada com `dbDelta` na ativação e em `admin_init`/WP-CLI
  quando a option `hbv_core_revisao_db` está desatualizada, porque o plugin já está ativo nos sites).
- Lista com views (Pendentes, Aplicados, Rejeitados, Resolvidos, Todos), filtro por tipo, busca e ações
  em massa ("Marcar como resolvido" e "Rejeitar"); tela do caso com evidência, sugestão em linguagem
  simples, valor editável, observação e histórico; formulário em `admin-post.php` com nonce por caso.
- Ações de "Aplicar sugestão", sempre validadas no servidor: `termo` (acrescenta termo existente),
  `meta` (campo da ficha, com `hbv_sanitize_field()`), `link` (troca o `href` ou remove o `<a>` mantendo
  o texto; grava com `wp_update_post`, que cria revisão do WordPress), `publicar` e `nenhuma`.
  Exige `edit_post` (e `publish_post` para publicar) além da capability da tela. "Reabrir" volta o caso
  a pendente e não desfaz alterações.
- Capability `hbv_revisar` (dada ao administrador) e papel `hbv_revisor` ("Revisor(a) de conteúdo":
  capabilities do editor mais `hbv_revisar`). Não são removidos ao desativar o plugin.
- `wp hbv revisao importar <arquivo.tsv> [--simular] [--lote=<nome>]`, `status`,
  `exportar [--situacao=<s>] [--tipo=<t>]` e `remover --lote=<nome> [--yes]`. Relatório da importação em
  `uploads/hbv-migracao/<lote>.tsv`.
- Subpágina **Revisão editorial > Importar e exportar** (capability `manage_options`), para homologação
  e produção, que não têm WP-CLI: importar `.tsv` por upload (até 5 MB, "Só simular" marcado por
  padrão, relatório em `uploads/hbv-migracao/` e resultado na tela por 10 minutos), baixar o TSV de
  exportação em blocos (`revisao-AAAAMMDD-HHMMSS.tsv`) e remover os pendentes de um lote, com
  confirmação. A lógica de importar e exportar ficou na classe (`HBV_Revisao::importar_tsv()`,
  `exportar_tsv()`, `decodificar()`, `codificar()`) e o comando WP-CLI delega a ela.
- Importação aceita `link` com `para` vazio (a revisora informa o endereço na tela; a tela mostra
  "Trocar o link X por um endereço que você informa abaixo (ou remover o link)").
- Novos arquivos: `includes/revisao/class-hbv-revisao.php`, `includes/revisao/class-hbv-revisao-lista.php`,
  `includes/revisao/admin.php`, `includes/revisao/admin-transferencia.php`, `includes/cli/class-hbv-revisao-command.php`, `assets/admin-revisao.css`
  (só carregado na tela da revisão) e `docs/REVISAO.md`.

## [0.7.0] - 2026-11-12

### Adicionado
- `wp hbv migracao links [--simular] [--lote=<nome>] [--limite=<n>] [--tipos=<lista>] [--host=<h1,h2>]`:
  reescreve os links internos dos textos (`href` de `<a>` para o host do site ou os `--host`) para os
  endereços novos. Regras, em ordem: a) anexo por `rel="wp-att-ID"`; b) já é o permalink atual (não
  mexe); c) `hbv_core_resolver_caminho()`; d) slug antigo (`_wp_old_slug`, "ambíguo" se houver mais de
  um); e) categoria antiga; f) não resolvido (o link não é alterado). Troca só o valor do `href`
  (regex pontual; aspas, query, fragmento e o resto do HTML ficam byte a byte) e só quando o destino
  difere do link atual. Guarda o original em `_hbv_migracao_links` (JSON por lote, com md5 do
  original e do novo) e grava `post_content` direto (sem `post_modified`, revisão nem kses). Recusa
  post já reescrito por outro lote. Relatórios `<lote>.tsv` e `<lote>-nao-resolvidos.tsv`.
- `wp hbv migracao limpar --lote=<nome> [--yes]`: remove só o `conteudo_original` do registro de
  links (mantém os md5), com confirmação; depois disso o `desfazer` do lote não é mais possível.
- Novo arquivo `includes/cli/trait-hbv-migracao-links.php` (auxiliares do comando `links`).

### Alterado
- `desfazer` restaura o texto dos posts do lote `links` (só se o md5 atual for o gravado), `verificar`
  confere md5 e links restantes resolvíveis, e `status` conta os posts reescritos por lote.

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
