# Ferramentas de migração

Scripts da análise e da geração do plano de conversão (ver `docs/MIGRADOR.md`).

| Arquivo | Função |
|---|---|
| `extrair.sh` | Exporta os posts publicados do `hbv-local` para `posts.tsv` |
| `classificar.py` | Classifica cada post e gera `classificacao.tsv` e `plano.tsv` |
| `explorar.py` | Estatísticas por categoria (exploração) |
| `medidas.py` | Medidas complementares (bibliografias, duplicatas) |
| `exportar_revisao.php` | (`wp eval-file`) Exporta do banco, em JSON, o que `gerar_casos.py` precisa |
| `gerar_casos.py` | Monta o TSV de casos da Revisão editorial a partir dos relatórios da migração (ver `docs/REVISAO.md`) |

Os arquivos gerados (`*.tsv`, `*.json`) e o `casos-manuais.tsv` (casos escritos à mão: `tipo post_id resumo evidencia`) têm conteúdo do acervo e ficam fora do git.
