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

## Obras: bibliografias e discografias (0.3.0)

Decisão de 11/11/2026: **uma obra por livro ou disco**. As 198 páginas "Bibliografia" e
"Discografia" viram 416 obras (`hbv_obra`), cada autor ou artista vira um termo da nova
taxonomia **Autoria**, e o endereço antigo da página redireciona (301) para a lista de obras do
autor. 10 páginas de banda não têm discos (só a história do grupo): viram instituição
(`hbv_instituicao`, tipo `cultura`) pelo mesmo caminho do conversor.

### Fluxo

```
exportar_conteudo.py -> conteudo-obras.json -> dividir_obras.py -> plano-obras.jsonl + plano-autores.jsonl
                                                                    -> wp hbv migracao obras
```

`dividir_obras.py` mostra um resumo de qualidade (análise de 11/11/2026: título em 100% das
obras, capa em 95–99%, ficha catalográfica dos livros em 94–100%; 28 livros têm "RESENHA:"
vazia no original).

### `plano-autores.jsonl` (uma linha JSON por página)

`id_pagina`, `slug_pagina`, `tipo` (`bibliografia`|`discografia`), `autor` (nome), `formacao`,
`slug_biografia` (slug do post de biografia ligado em "Biografia", ou vazio), `itens` (obras na
página; 0 = página de banda sem discos).

### `plano-obras.jsonl` (uma linha JSON por obra)

`id_pagina`, `ordem`, `tipo`, `autor`, `slug_biografia`, `titulo` (como no original, em
maiúsculas), `capa_id` (ID do anexo da capa ou vazio), `ano`, `edicao`, `editora`, `isbn`,
`paginas`, `tiragem`, `estudio`, `musicas`, `tem_resenha` (bool), `conteudo_html` (HTML
completo da obra no original, sem a linha do título: resenha, imagens, ficha, faixas).

### Modelo (mudanças no plugin)

- Nova taxonomia `hbv_autoria` ("Autoria", plural "Autorias"): só em `hbv_obra`, não
  hierárquica, pública, `show_in_rest`, `show_admin_column`, rewrite `autoria`. Termo = autor ou
  artista; descrição = "Formação: …" quando houver. Term meta registrados: `hbv_pessoa`
  (integer, ID da biografia `hbv_pessoa` publicada com o `slug_biografia`, se existir) e
  `hbv_formacao` (string).
- Campos novos de `hbv_obra` em `hbv_fields_for()` (todos `text`, menos `musicas` que é
  `textarea`): `edicao` (Edição), `isbn` (ISBN), `paginas` (Páginas), `tiragem` (Tiragem),
  `estudio` (Estúdio), `musicas` (Músicas). Os existentes `autor`, `ano`, `editora`, `link`
  continuam; `editora` recebe a editora ou a gravadora.
- O resolvedor de caminhos (`hbv_core_resolver_caminho`) passa a procurar também em term meta
  `_hbv_caminho_antigo` (termo de `hbv_autoria`) e devolve `get_term_link()`. Ordem: post
  primeiro, depois termo.

### `wp hbv migracao obras <plano-obras.jsonl> <plano-autores.jsonl> [--simular] [--lote=<nome>] [--limite=<n>]`

Para cada página do `plano-autores` (na ordem do arquivo):

1. Confere que o post `id_pagina` existe, é `post`, `publish`, e `post_name = slug_pagina`;
   senão recusa a página e todas as suas obras, com motivo.
2. Página com `itens = 0` (banda sem discos): faz o mesmo que o `converter` faz para
   `hbv_instituicao` com termo `cultura` e tema `cultura` (registro `_hbv_migracao`,
   `_hbv_caminho_antigo`, `set_post_type`). Fim para essa página.
3. Termo de autoria: procura em `hbv_autoria` por nome exato; se não existir, cria (slug =
   `sanitize_title(autor)`, único). Grava descrição e term meta (`hbv_formacao`, `hbv_pessoa`
   se a biografia existir e estiver publicada como `hbv_pessoa`). Se o termo foi criado agora,
   grava term meta `_hbv_migracao` (JSON: `lote`, `data`, `criado: true`).
4. Para cada obra dessa página (na ordem): `wp_insert_post` com `post_type = hbv_obra`,
   `post_status = publish`, `post_title = titulo`, `post_content = conteudo_html`,
   `post_date`/`post_date_gmt`/`post_author` iguais aos da página de origem, `post_name` a
   partir do título (o WordPress garante unicidade). Depois:
   - meta `hbv_autor` (nome), e os campos da ficha que vierem preenchidos (`hbv_ano`,
     `hbv_editora`, `hbv_edicao`, `hbv_isbn`, `hbv_paginas`, `hbv_tiragem`, `hbv_estudio`,
     `hbv_musicas`);
   - termos: `hbv_autoria` (o termo da página), `hbv_tipo_obra` (`literatura` para
     bibliografia, `musica` para discografia), `hbv_tema` `cultura`;
   - capa: `set_post_thumbnail` se `capa_id` for um anexo existente;
   - `_hbv_migracao` (JSON: `lote`, `data`, `acao: "criada"`, `origem` = id da página, `ordem`).
5. Página de origem: grava `_hbv_migracao` (JSON: `lote`, `data`, `acao: "dividida"`,
   `status_anterior`, `termo_autoria` = term_id, `obras` = IDs criados) e `_hbv_caminho_antigo`
   **no termo** de autoria (term meta, caminho normalizado da página); muda a página para
   `post_status = draft` (o conteúdo fica guardado para o desfazer). Assim o endereço antigo dá
   404 e o módulo redireciona para `/autoria/<termo>/`.
6. Página já com `_hbv_migracao`: pulada, salvo `--forcar` (não necessário nesta versão: pode
   recusar `--forcar` com mensagem).

Saída: resumo (páginas divididas, obras criadas, bandas convertidas, recusas por motivo),
relatório TSV em `uploads/hbv-migracao/<lote>.tsv` (`id_pagina, autor, termo, obras_criadas,
ids, caminho_antigo, destino, resultado, motivo`). `--simular`: nada gravado; mostra contagens e
5 exemplos (título, autor, termo novo ou existente, destino do endereço antigo).

Memória e desempenho como no `converter` (blocos, `wp_defer_term_counting`, limpeza de cache).

### `desfazer` (ampliado)

Além do que já faz para posts convertidos, por lote:
- obras com `acao = "criada"`: `wp_delete_post( $id, true )` (o anexo da capa não é apagado);
- páginas com `acao = "dividida"`: volta `post_status` ao `status_anterior` e apaga os metas;
- termos de `hbv_autoria` com `_hbv_migracao.criado` do lote: `wp_delete_term`; nos demais
  termos tocados pelo lote, apaga só `_hbv_caminho_antigo` que veio da página desfeita.
A ordem é: obras, páginas, termos.

### `verificar` e `status` (ampliados)

`verificar` também confere cada `_hbv_caminho_antigo` de termo (resolve para o link do termo).
`status` conta também obras criadas e páginas divididas por lote.

### `wp hbv migracao titulos <arquivo.tsv> [--simular]`

Aplica títulos revisados (por exemplo, de MAIÚSCULAS para a grafia normal). TSV com cabeçalho
`id	titulo`. Só aceita a linha se o post existir, for `hbv_obra` com `_hbv_migracao.acao =
criada`, e o título novo for igual ao atual ignorando maiúsculas e minúsculas
(`mb_strtolower` dos dois, após `trim`); senão recusa com motivo. Grava o título anterior em
`_hbv_titulo_original` (só na primeira vez) e atualiza `post_title` com `wp_update_post`
(`post_name` não muda). `desfazer` não precisa tratar: o título some com a obra.

## Fora desta versão (próximos PRs)

- Páginas de mês: listas "Nasceram/Morreram" passam a ser geradas das datas das biografias;
  "Programa de eventos" vira `hbv_fato`.
- Extração de campos da ficha (nascimento, falecimento, local, papel; tipo e lei do logradouro;
  distrito da localidade). Regras primeiro; casos fora do padrão por modelo pequeno, com validação.
- Reescrever links internos antigos dentro dos textos.

## Regras do projeto que valem aqui

Prefixo `hbv_`, text domain `hbv`, saída escapada, `$wpdb->prepare` em toda consulta com
variável, consultas com limite, nada de serviço externo. Comandos só via WP-CLI (nenhuma tela
de admin nesta versão).
