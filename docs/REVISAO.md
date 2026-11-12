# Revisão editorial

A migração do acervo deixou alguns casos que um programa não consegue decidir sozinho: um papel
que talvez esteja errado, um link que não leva a lugar nenhum, uma data que não bate com a página
do mês. Cada um desses casos aparece numa tela do painel, para uma pessoa decidir.

Este documento tem duas partes: a primeira é para quem revisa; a segunda, para quem administra o site.

---

## Parte 1. Para quem revisa

### Onde fica

No menu da esquerda do painel, clique em **Revisão editorial** (ícone de visto). O número ao lado
do nome é a quantidade de casos que ainda esperam por você. Se o menu não aparece, peça a quem
administra o site para dar a você o papel "Revisor(a) de conteúdo".

### A lista

A lista abre nos casos **Pendentes**. Acima dela, os links **Aplicados**, **Rejeitados**,
**Resolvidos** e **Todos** mostram o que já foi decidido. Você pode filtrar por tipo de caso,
buscar uma palavra e, marcando vários casos, usar a ação em massa **Marcar como resolvido** ou
**Rejeitar**.

Para olhar um caso, clique em **Analisar**. Em cada linha também há **Editar conteúdo** (abre o
editor do texto) e **Ver no site**.

### O que cada tipo de caso significa

| Tipo | O que é | O que fazer |
|---|---|---|
| Papel na história a confirmar | O programa achou que esta biografia tem um papel (por exemplo, vereador), mas não teve certeza. | Leia a evidência. Se o papel é esse, aplique a sugestão. Se for outro, escolha o termo certo na lista e aplique. |
| Nome sem biografia | O nome aparece em textos, mas não tem página de biografia. | Decida se vale criar a biografia. Quando criar, marque "Resolvi à mão". |
| Link quebrado no texto | Um link do texto não leva a nenhuma página. | Aplique a sugestão (trocar o link) ou marque "tirar o link e manter o texto". |
| Data do texto diferente da página de mês | A data no texto da biografia não é a mesma da página do mês. | Confira na fonte, corrija o que estiver errado no editor e marque "Resolvi à mão". |
| Página de mês com duas datas | A página do mês traz duas datas para o mesmo fato. | Confira na fonte, corrija no editor e marque "Resolvi à mão". |
| Falecimento só na página de mês | A morte da pessoa só consta na página do mês, não no texto dela. | Acrescente a informação ao texto, com a fonte, e marque "Resolvi à mão". |
| Possível duplicado | Dois conteúdos parecem ser a mesma coisa. | Compare os dois. Se forem iguais, una os textos à mão e marque "Resolvi à mão". Se forem diferentes, "Rejeitar". |
| Obra sem capa | A obra não tem imagem de capa. | Envie a capa no editor e marque "Resolvi à mão". |
| Obra sem resenha | A obra não tem resenha. | Escreva ou cole a resenha no editor e marque "Resolvi à mão". |
| Título de obra a revisar | O título da obra pode estar incompleto ou errado. | Confira na fonte, corrija no editor e marque "Resolvi à mão". |
| Revisão por amostra | Um conteúdo foi sorteado para uma conferência geral. | Leia o conteúdo. Se estiver bom, "Resolvi à mão"; se achar problema, corrija e depois marque. |
| Conteúdo novo aguardando aprovação | Conteúdo criado pela migração que ainda está em rascunho. | Leia. Estando bom, aplique a sugestão (publicar). |
| Outro | Qualquer caso que não se encaixa nos outros. | Siga o que a evidência explicar. |

### A tela de um caso

Em cima ficam o resumo, o conteúdo afetado e a **evidência**: o trecho ou o motivo que levou o
programa a levantar a dúvida. Logo abaixo, **O que a sugestão propõe**, em palavras simples
(por exemplo, "Acrescentar em Papel na história o termo vereador a este conteúdo").

Antes de aplicar, você pode **mudar o valor proposto**: escolher outro termo, corrigir o texto de
um campo ou o endereço do novo link. Escreva uma **observação** se quiser deixar registrado o motivo
da decisão (ela fica no histórico).

### O que cada botão faz

- **Aplicar sugestão**: faz a alteração no conteúdo agora e marca o caso como "Sugestão aplicada".
  Só aparece quando o caso tem uma sugestão que dá para aplicar. Se a alteração não puder ser feita
  (por exemplo, o link já não está no texto), o caso continua pendente e a tela explica o motivo.
- **Resolvi à mão**: você já corrigiu pelo editor (ou não era preciso corrigir). Nada é alterado
  por esta tela; o caso fica como "Resolvido à mão".
- **Rejeitar**: a sugestão não está certa ou não vale a pena. Nada é alterado.
- **Reabrir caso** (nos casos já decididos): devolve o caso à fila de pendentes. **Reabrir não desfaz
  nada** que já tenha sido alterado no conteúdo. Para desfazer uma troca de link, use as revisões
  do editor; para um campo ou termo, veja "Como estava antes" na tela do caso.

Depois de cada decisão, a tela abre sozinha o próximo caso pendente (do mesmo tipo, se você estiver
filtrando por tipo). Para olhar outro sem decidir, use **Pular para o próximo**; para sair, **Voltar
à lista**.

### O que acontece com o que já está publicado

Nada muda sem você decidir. O conteúdo que já está publicado **continua publicado**, do jeito que
está, até que você aplique uma sugestão sobre ele. Só o conteúdo **novo** (tipo "Conteúdo novo
aguardando aprovação") fica em **rascunho** até a aprovação: ele só vai ao ar quando você aplicar a
sugestão de publicar.

---

## Parte 2. Para quem administra

### Como funciona

Cada caso é uma linha da tabela `{prefixo}hbv_revisao`. Um script externo (Python) gera um TSV
com os casos; o WP-CLI importa; a revisora decide na tela. A tabela é criada na ativação do plugin e,
como o plugin já está ativo nos sites, também no primeiro acesso ao painel (ou primeiro comando
WP-CLI) depois da atualização para a 0.8.0: a option `hbv_core_revisao_db` guarda a versão do
esquema e, quando ela difere da do código, a tabela, a capability e o papel são conferidos de novo.

### Formato do TSV de entrada

UTF-8, separado por tabulação, cabeçalho obrigatório com estas 7 colunas, nesta ordem:

```
chave	tipo	post_id	resumo	evidencia	acao	parametros
```

| Coluna | Regra |
|---|---|
| `chave` | Identificador estável do caso, até 64 caracteres, único. Importar de novo a mesma chave não faz nada (a decisão fica como está). Sugestão: `tipo:post_id:detalhe`. |
| `tipo` | `papel`, `sem_biografia`, `link_quebrado`, `data_divergente`, `data_conflito`, `obito_sem_texto`, `duplicado`, `obra_capa`, `obra_resenha`, `obra_titulo`, `amostra`, `novo` ou `outro`. |
| `post_id` | `0` (sem conteúdo ligado) ou o ID de um conteúdo existente. Obrigatório (maior que 0) em todas as ações, menos `nenhuma`. |
| `resumo` | Frase curta que aparece na lista, até 255 caracteres. |
| `evidencia` | Texto livre (trecho, motivo). Pode ter várias linhas. Pode ficar vazio. |
| `acao` | `nenhuma`, `termo`, `meta`, `link` ou `publicar`. |
| `parametros` | Objeto JSON; ver abaixo. Vazio equivale a `{}`. |

Nos campos, escreva `\t` para uma tabulação, `\n` para uma quebra de linha e `\\` para uma barra
invertida. Não use aspas para cercar campos: aspas são texto comum.

Parâmetros por ação:

| Ação | Parâmetros | O que a tela faz ao aplicar |
|---|---|---|
| `termo` | `{"taxonomia":"hbv_papel","termo":"vereador"}` | Acrescenta o termo (pelo slug, que tem de existir) ao conteúdo, sem tirar os que ele já tem. A taxonomia tem de ser do tipo do conteúdo. |
| `meta` | `{"campo":"nascimento","valor":"12/03/1890"}` | Grava o campo da ficha (nome sem o prefixo `hbv_`; `fontes` também vale). O valor passa por `hbv_sanitize_field()`. |
| `link` | `{"de":"http://.../x","para":"/biografias/x/"}` ou `{"de":"...","para":"","remover":1}` | Troca o `href` exatamente igual a `de` por `para` (http, https ou começando com `/`), ou tira o `<a>` e mantém o texto. `para` pode vir vazio quando não há sugestão: a revisora informa o endereço na tela (sem ele, só remover). Grava com `wp_update_post`, que cria uma revisão do WordPress. |
| `publicar` | `{}` | Publica o conteúdo se estiver em rascunho ou pendente. |
| `nenhuma` | `{}` | Sem botão de aplicar: só "Resolvi à mão" e "Rejeitar". |

### Comandos

```bash
# Conferir o arquivo sem gravar
wp hbv revisao importar casos.tsv --simular

# Importar (lote padrão: revisao-AAAAMMDD-HHMMSS, em UTC)
wp hbv revisao importar casos.tsv --lote=revisao-1

# Quadro de casos por tipo e situação
wp hbv revisao status

# Guardar as decisões (ou levar de um ambiente a outro)
wp hbv revisao exportar > revisao-completa.tsv
wp hbv revisao exportar --situacao=rejeitado --tipo=papel > rejeitados.tsv

# Apagar os casos ainda pendentes de um lote (pede confirmação; --yes dispensa)
wp hbv revisao remover --lote=revisao-1
```

A importação valida cada linha (tipo e ação conhecidos, `post_id` existente, JSON com as chaves da
ação, termo e campo que existem) e grava um relatório em
`wp-content/uploads/hbv-migracao/<lote>.tsv` (colunas `linha`, `chave`, `resultado`, `motivo`), com
`<lote>-simulacao.tsv` na simulação. Resultados: `importado`, `já existe`, `inválido`. O comando
mostra também as contagens por tipo e resultado.

`remover` só apaga casos **pendentes** do lote; os já decididos ficam, para não perder decisões.
A saída de `exportar` usa as mesmas regras de `\t`, `\n` e `\\`; ela serve de arquivo morto das
decisões, mas o `importar` lê só as 7 colunas de entrada e cria sempre casos pendentes.

### Permissões

- Capability **`hbv_revisar`**: dá acesso ao menu e às decisões. É concedida ao papel
  Administrador.
- Papel **`hbv_revisor`** ("Revisor(a) de conteúdo"): as mesmas capabilities do papel Editor mais
  `hbv_revisar`. É criado automaticamente.
- Para aplicar uma sugestão, a pessoa também precisa poder editar o conteúdo (`edit_post`) e, para
  publicar, `publish_post`. A tela confere isso no servidor a cada clique.

Dar o papel à revisora: **Usuários > Editar** o usuário > campo **Papel** > "Revisor(a) de
conteúdo". Ou pelo WP-CLI:

```bash
wp user set-role <login> hbv_revisor
```

O plugin **não tem desinstalação** (`uninstall.php`) nesta versão. Desativar ou remover o plugin
não apaga a tabela, o papel nem a capability. Para remover tudo à mão:

```bash
wp role delete hbv_revisor
wp cap remove administrator hbv_revisar
wp db query "DROP TABLE IF EXISTS $(wp db prefix)hbv_revisao"
wp option delete hbv_core_revisao_db
```

### Observações técnicas

- O histórico de cada caso é uma linha por decisão: `AAAA-MM-DD HH:MM — login — ação — observação`.
- Casos aplicados guardam o valor anterior (`valor_anterior`, JSON) para desfazer à mão; no caso de
  link, o texto antigo fica nas revisões do WordPress.
- O CSS (`assets/admin-revisao.css`) só é carregado nessa tela; não há scripts.
