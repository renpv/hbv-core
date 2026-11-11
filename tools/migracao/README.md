# Ferramentas de migração

Scripts da análise e da geração do plano de conversão (ver `docs/MIGRADOR.md`).

| Arquivo | Função |
|---|---|
| `extrair.sh` | Exporta os posts publicados do `hbv-local` para `posts.tsv` |
| `classificar.py` | Classifica cada post e gera `classificacao.tsv` e `plano.tsv` |
| `explorar.py` | Estatísticas por categoria (exploração) |
| `medidas.py` | Medidas complementares (bibliografias, duplicatas) |

Os arquivos gerados (`*.tsv`) têm conteúdo do acervo e ficam fora do git.
