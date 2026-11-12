# Migrador do acervo (fase 2)

Converte os posts atuais (tudo é `post` com categoria) para os tipos do HBV Core, com registro
de cada mudança, desfazer por lote e redirecionamento 301 dos endereços antigos. Roda **somente
no ambiente local** (`hbv-local`); a produção recebe o banco já convertido no lançamento.

## Fluxo

```
extrair.sh  ->  posts.tsv  ->  classificar.py  ->  plano.tsv  ->  wp hbv migracao converter
                                                   (revisável)       (grava, registra, desfaz)
```

1. `tools/migracao/extrair.sh ~/dev/hbv-local posts.tsv` exporta os posts publicados.
2. `python3 tools/migracao/classificar.py posts.tsv .` gera `classificacao.tsv` (com motivo e
   confiança) e `plano.tsv` (o que o conversor lê).
3. `wp hbv migracao converter plano.tsv --simular` mostra o que mudaria, sem gravar.
4. `wp hbv migracao converter plano.tsv --lote=pessoas-1 --tipo=hbv_pessoa` converte.
5. `wp hbv migracao verificar` confere que cada endereço antigo resolve para o novo.
6. `wp hbv migracao desfazer --lote=pessoas-1` volta tudo, se preciso.

Por que o classificador é Python e não PHP: as regras já foram validadas na análise de
11/11/2026 (99,4% com confiança alta, 37 de 37 na amostra) e o plano em TSV pode ser lido e
corrigido antes de qualquer gravação. O PHP só aplica o plano.

## Formato do `plano.tsv`

Separado por tabulação, cabeçalho na primeira linha, UTF-8, sem aspas.

| Coluna | Exemplo | Uso |
|---|---|---|
| `id` | `38` | ID do post |
| `slug` | `bairro-recreio` | `post_name` no momento da análise; se mudou, a linha é recusada (plano velho) |
| `tipo` | `hbv_lugar` | destino (ver abaixo) |
| `termo` | `bairro` | slug do termo de tipo (`hbv_tipo_lugar`, `hbv_tipo_instituicao`, `hbv_tipo_obra`); vazio para os demais |
| `tema` | `a-cidade` | slug do termo `hbv_tema` a acrescentar; vazio = nenhum |
| `confianca` | `alta` | `alta`, `media` ou `baixa` |
| `titulo` | `Bairro Recreio` | só para leitura humana |

Destinos aceitos nesta versão:

| `tipo` | O que o conversor faz |
|---|---|
| `hbv_pessoa`, `hbv_lugar`, `hbv_logradouro`, `hbv_instituicao`, `hbv_obra`, `hbv_fato` | muda o `post_type`, acrescenta o termo de tipo e o tema |
| `post` | continua post; só acrescenta o tema |
| `pagina` | muda para `page` |
| `bibliografia`, `discografia`, `fato_mes` | **ignorados** nesta versão (serão divididos em itens por um comando próprio) |

Taxonomia do termo por tipo: `hbv_lugar` → `hbv_tipo_lugar`; `hbv_instituicao` →
`hbv_tipo_instituicao`; `hbv_obra` → `hbv_tipo_obra`. Termo inexistente → linha recusada e
listada (não cria termo solto).

## Comandos (`wp hbv migracao …`)

### `converter <plano> [--tipo=<tipo>] [--confianca=<min>] [--limite=<n>] [--lote=<nome>] [--simular] [--forcar]`

- `--confianca=alta` (padrão) aceita só `alta`; `media` aceita `alta` e `media`; `baixa` aceita todas.
- `--lote` padrão: `AAAAMMDD-HHMMSS`. Nome só com `[a-z0-9-]`.
- Antes de gravar, chama `hbv_seed_terms()` (idempotente) para garantir os termos novos.
- Para cada linha aceita, nesta ordem:
  1. Confere: post existe, `post_type = post`, `post_status = publish`, `post_name = slug`.
     Se já tem `_hbv_migracao`, pula (já convertido), a menos que `--forcar`.
  2. Guarda o estado anterior em `_hbv_migracao` (JSON): `lote`, `data` (UTC, ISO 8601),
     `tipo_anterior`, `caminho_anterior`, `tipo_novo`, `termos` (lista de `[taxonomia, term_id]`
     acrescentados por esta conversão; termos que o post já tinha não entram).
  3. Grava `_hbv_caminho_antigo` = caminho relativo do permalink antes da conversão
     (`/bairro-recreio/`), em minúsculas, com barra no começo e no fim.
  4. `set_post_type()` (não altera `post_modified` nem cria revisão).
  5. `wp_set_object_terms( $id, $term_id, $tax, true )` para termo de tipo e tema.
- Processa em blocos de 100: `wp_defer_term_counting( true )` durante o lote e contagem no fim;
  limpa o cache de objetos e `$wpdb->queries` entre blocos para não estourar memória.
- `--simular`: não grava nada; mostra tabela por tipo (aceitas, recusadas, já convertidas) e
  5 exemplos de cada tipo com caminho antigo → novo.
- Sempre: grava um relatório TSV em `wp-content/uploads/hbv-migracao/<lote>.tsv`
  (`id, tipo, termo, tema, caminho_antigo, caminho_novo, resultado, motivo`) e imprime o resumo.
- Linhas recusadas nunca interrompem o lote; o resumo lista os motivos com contagem.

### `desfazer (--lote=<nome> | --todos) [--simular]`

Para cada post com `_hbv_migracao` do lote: volta `post_type` ao anterior, remove só os termos
listados em `termos`, apaga `_hbv_migracao` e `_hbv_caminho_antigo`. Recalcula contagens no fim.

### `status`

Tabela: lote, data, tipo novo, quantidade. E o total ainda como `post`.

### `verificar [--lote=<nome>] [--formato=table|csv]`

Para cada post convertido: passa `_hbv_caminho_antigo` pelo resolvedor do módulo de
redirecionamentos (sem HTTP) e confere que devolve o permalink atual do post. Mostra falhas e
o total (`N de N resolvem`). Termina com código de erro se houver falha.

## Módulo de redirecionamentos (`includes/redirecionamentos.php`)

Sempre ativo com o plugin (desligável pelo filtro `hbv_core_redirecionar` → `false`).

1. **Posts convertidos.** Em `template_redirect` (prioridade 1), só quando `is_404()`:
   normaliza o caminho da requisição (sem query, minúsculas, barra no fim, sem o caminho de
   instalação), busca `post_id` com `_hbv_caminho_antigo` = caminho (consulta preparada,
   `LIMIT 1`, só posts publicados) e faz `wp_safe_redirect( get_permalink(), 301 )`.
   A busca fica numa função pública `hbv_core_resolver_caminho( $caminho )` que devolve a URL de
   destino ou `null` (usada também por `verificar`).
2. **Arquivos de categoria antigos.** Quando `is_404()` ou `is_category()`: se o caminho for
   `/category/<slug>/…` ou `/<slug>/…` de uma categoria do mapa abaixo, redireciona 301 para o
   destino. Paginação (`/page/N/`) vai para a primeira página do destino. O mapa passa pelo filtro
   `hbv_core_mapa_categorias`.
3. Nunca redireciona se a requisição já resolve para conteúdo válido (fora os casos acima), em
   `is_admin()`, REST, AJAX, cron ou WP-CLI.

| Categoria antiga | Destino |
|---|---|
| biografias | arquivo `hbv_pessoa` (`/biografias/`) |
| distritos | termo `hbv_tipo_lugar/distrito` |
| avenidas-e-ruas | arquivo `hbv_logradouro` |
| educacao | termo `hbv_tema/educacao` |
| literatura | termo `hbv_tipo_obra/literatura` |
| religiosidade | termo `hbv_tema/religiosidade` |
| bairros | termo `hbv_tipo_lugar/bairro` |
| cronicas-e-poesias | termo `hbv_tipo_obra/cronicas-e-poesias` |
| administracao | termo `hbv_papel/prefeito` |
| recursos-hidricos | termo `hbv_tema/recursos-hidricos` |
| sem-categoria | termo `hbv_tema/editorial` |
| economia | termo `hbv_tema/economia` |
| pracas | termo `hbv_tipo_lugar/praca` |
| turismo | termo `hbv_tema/turismo` |
| cultura | termo `hbv_tema/cultura` |
| saude | termo `hbv_tipo_instituicao/saude` |
| datas-e-fatos | arquivo `hbv_fato` (`/datas-e-fatos/`) |
| necropole | termo `hbv_tipo_lugar/necropole` |
| acesso | termo `hbv_tipo_lugar/acesso` |
| simbolos-municipais | termo `hbv_tema/simbolos` |
| historia | termo `hbv_tema/historia-do-municipio` |
| comendas-e-titulos | termo `hbv_tipo_obra/comendas-e-titulos` |
| mapas | termo `hbv_tipo_obra/mapas` |
| quem-somos | página com slug `quem-somos`, se existir; senão termo `hbv_tema/editorial` |

## Termos novos (acrescentados a `hbv_seed_terms()`)

| Taxonomia | Nome | Slug |
|---|---|---|
| `hbv_tipo_lugar` | Rio e riacho | `rio` |
| `hbv_tipo_lugar` | Ponto turístico e monumento | `ponto-turistico` |
| `hbv_tipo_lugar` | Equipamento esportivo | `equipamento-esportivo` |
| `hbv_tipo_instituicao` | Comércio, indústria e serviços | `comercio-e-servicos` |
| `hbv_tipo_instituicao` | Serviços públicos | `servicos-publicos` |
| `hbv_tipo_instituicao` | Comunicação | `comunicacao` |
| `hbv_tipo_instituicao` | Associações e entidades | `associacoes` |
| `hbv_tipo_instituicao` | Cultura | `cultura` |

## Fora desta versão (próximos PRs)

- Dividir bibliografias e discografias em uma obra por livro ou disco (411 itens; decisão de 11/11/2026).
- Páginas de mês: listas "Nasceram/Morreram" passam a ser geradas das datas das biografias;
  "Programa de eventos" vira `hbv_fato`.
- Extração de campos da ficha (nascimento, falecimento, local, papel; tipo e lei do logradouro;
  distrito da localidade). Regras primeiro; casos fora do padrão por modelo pequeno, com validação.
- Reescrever links internos antigos dentro dos textos.

## Regras do projeto que valem aqui

Prefixo `hbv_`, text domain `hbv`, saída escapada, `$wpdb->prepare` em toda consulta com
variável, consultas com limite, nada de serviço externo. Comandos só via WP-CLI (nenhuma tela
de admin nesta versão).
