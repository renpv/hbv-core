# HBV Core

Plugin do acervo **História de Boa Viagem** (historiadeboaviagem.com.br). Guarda o modelo de
conteúdo do site, separado do tema, para que trocar de tema não esconda biografias, lugares e
logradouros do painel.

Requer WordPress 6.4+ e PHP 7.4+. Testado com WordPress 7.1 e PHP 8.3 (mesmas versões da produção).

## O que o plugin registra

| Tipo | Endereço | Taxonomias próprias |
|---|---|---|
| `hbv_pessoa` (Biografia) | `/biografias/` | Papel na história (`hbv_papel`) |
| `hbv_lugar` (Lugar, hierárquico) | `/lugares/` | Tipo de lugar (`hbv_tipo_lugar`) |
| `hbv_logradouro` (Logradouro) | `/logradouros/` | |
| `hbv_fato` (Data e fato) | `/datas-e-fatos/` | |
| `hbv_obra` (Obra ou documento) | `/obras/` | Tipo de obra (`hbv_tipo_obra`) |
| `hbv_instituicao` (Instituição) | `/instituicoes/` | Tipo de instituição (`hbv_tipo_instituicao`) |

Em todos os tipos, inclusive posts e páginas: **Tema** (`hbv_tema`), **Período histórico**
(`hbv_periodo`, com ano inicial e final) e o campo **Fontes e bibliografia** (`hbv_fontes`).
Os campos da ficha de cada tipo estão em `includes/meta.php` e aparecem na API REST.

## Funções usadas pelo tema

O tema `hbv-memoria` (1.1.0+) chama estas funções; mudar a assinatura delas quebra o tema:

- `hbv_post_type_defs()`, `hbv_cpts()` — tipos de conteúdo
- `hbv_taxonomy_defs()`, `hbv_seed_terms()` — taxonomias e termos padrão
- `hbv_fields_for( $post_type )`, `hbv_month_names()` — campos da ficha

## Instalação e atualização

1. Atualize o tema para a versão 1.1.0 ou superior **antes** de ativar o plugin. Com o tema
   1.0.x ativo, o plugin não carrega e mostra um aviso no painel (as duas versões declaram as
   mesmas funções).
2. Envie a pasta `hbv-core` para `wp-content/plugins/` e ative. A ativação cria os termos padrão
   (sem duplicar) e atualiza os links permanentes.

Desativar ou remover o plugin **não apaga** conteúdo, termos ou campos: eles continuam no banco
e voltam a aparecer ao reativar.

## Desenvolvimento local

O ambiente `hbv-local` (Docker no WSL) monta este repositório em `wp-content/plugins/hbv-core`.

```bash
./scripts/wp.sh plugin activate hbv-core
./scripts/wp.sh post-type list --fields=name,label --format=table
```

## Migração

Comandos WP-CLI (`wp hbv migracao converter|desfazer|status|verificar`) convertem os posts atuais
para os tipos do plugin, com registro por lote, desfazer e redirecionamento 301 dos endereços
antigos (módulo `includes/redirecionamentos.php`, sempre ativo; desligável com o filtro
`hbv_core_redirecionar`). Rodam só no ambiente local. Fluxo, formato do plano e regras em
[`docs/MIGRADOR.md`](docs/MIGRADOR.md); ajuda com `wp help hbv migracao converter`.

Desde a 0.3.0, `wp hbv migracao obras` divide as páginas de bibliografia e discografia em uma
obra por livro ou disco (taxonomia Autoria) e `wp hbv migracao titulos` aplica títulos revisados.

Desde a 0.4.0, `wp hbv migracao meses` divide as 12 páginas de mês (janeiro a dezembro) em fatos
(`hbv_fato`), grava dia e mês de nascimento e falecimento nas biografias e guarda o texto de cada
mês na option `hbv_meses`; o endereço antigo (`/janeiro/`) redireciona por caminho fixo.

Desde a 0.5.0, `wp hbv migracao campos` grava campos da ficha (post meta) em qualquer tipo, a partir
de um TSV (`id`, `campo`, `valor`, `modo`, `fonte`), com simulação, relatório e desfazer por lote.

Desde a 0.6.0, o `campos` também define o pai de um post hierárquico (`post_parent`, ex.: localidade dentro de distrito) e acrescenta termos de taxonomia (`tax:hbv_papel`), com desfazer por lote.

Desde a 0.7.0, `wp hbv migracao links` reescreve os links internos dos textos para os endereços novos (com relatório dos não resolvidos, desfazer por lote e `limpar` dos originais).

## Revisão editorial

Desde a 0.8.0, o menu **Revisão editorial** reúne os casos duvidosos que a migração deixou, para uma
revisora (papel "Revisor(a) de conteúdo", capability `hbv_revisar`) aplicar a sugestão, resolver à mão
ou rejeitar. Os casos são importados por `wp hbv revisao importar` (TSV gerado fora). Guia para quem
revisa e para quem administra em [`docs/REVISAO.md`](docs/REVISAO.md).

## Próximos módulos (plano de modernização)

Compartilhar e Open Graph, como citar, dados
estruturados, kit de divulgação, Hoje na história, relacionamentos, contribua, mapa e visitas
sem rastreio. Cada um entra como módulo em `includes/`.
