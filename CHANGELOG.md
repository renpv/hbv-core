# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versões em
[SemVer](https://semver.org/lang/pt-BR/).

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
