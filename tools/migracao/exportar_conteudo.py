#!/usr/bin/env python3
"""
Exporta título, slug e conteúdo dos posts de certos tipos do plano para um JSON.
Uso (na pasta do hbv-local): python3 exportar_conteudo.py classificacao.tsv saida.json bibliografia,discografia
"""
import csv, json, subprocess, sys
csv.field_size_limit(10**8)
classif, saida, tipos = sys.argv[1], sys.argv[2], set(sys.argv[3].split(','))
ids = [r['ID'] for r in csv.DictReader(open(classif, encoding='utf-8'), delimiter='\t', quoting=csv.QUOTE_NONE) if r['tipo'] in tipos]
sql = ("SELECT JSON_OBJECTAGG(ID, JSON_OBJECT('title', post_title, 'name', post_name, 'content', post_content)) "
       f"FROM wp_posts WHERE ID IN ({','.join(ids)})")
out = subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'sh', '-c',
                      'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" --batch --raw -N 2>/dev/null'],
                     input=sql, capture_output=True, text=True, check=True).stdout
dados = json.loads(out)
json.dump(dados, open(saida, 'w', encoding='utf-8'), ensure_ascii=False)
print(len(dados), 'posts exportados para', saida)
