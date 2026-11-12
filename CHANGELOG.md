# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Versões em
[SemVer](https://semver.org/lang/pt-BR/).

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
