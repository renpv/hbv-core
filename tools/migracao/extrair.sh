#!/usr/bin/env bash
# Exporta os posts publicados do banco do ambiente local (hbv-local) para posts.tsv,
# com categorias, tamanho, marcadores de texto e o início do texto sem HTML.
# Uso: tools/migracao/extrair.sh <pasta-do-hbv-local> [saida.tsv]
set -euo pipefail
LOCAL="${1:?informe a pasta do hbv-local}"
SAIDA="$(realpath -m "${2:-posts.tsv}")"
cd "$LOCAL"
docker compose exec -T db sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" --batch --raw -e "
SELECT p.ID, REPLACE(REPLACE(p.post_title,CHAR(9),\" \"),CHAR(10),\" \") AS title, p.post_name, DATE(p.post_date) d, DATE(p.post_modified) m,
 (SELECT GROUP_CONCAT(t.slug ORDER BY t.slug) FROM wp_term_relationships r JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=r.term_taxonomy_id AND tt.taxonomy=\"category\" JOIN wp_terms t ON t.term_id=tt.term_id WHERE r.object_id=p.ID) cats,
 CHAR_LENGTH(p.post_content) len,
 (SELECT COUNT(*) FROM wp_posts a WHERE a.post_parent=p.ID AND a.post_type=\"attachment\") imgs,
 p.post_content REGEXP \"(?i)nasceu|nascido|nascida|filho de|filha de|faleceu|falecido|falecida\" bio,
 p.post_content REGEXP \"(?i)lei (municipal )?n[º°o.]\" lei,
 p.post_content REGEXP \"(?i)denomina[çc][ãa]o|denominad[ao]\" denom,
 p.post_content REGEXP \"(?i)as informa[çc][õo]es (b[áa]sicas|gerais)\" infobas,
 p.post_content REGEXP \"(?i)bibliografia|refer[êe]ncias\" biblio,
 (CHAR_LENGTH(p.post_content)-CHAR_LENGTH(REPLACE(p.post_content,\"<a \",\"\")))/3 nlinks,
 REPLACE(REPLACE(REPLACE(LEFT(TRIM(REGEXP_REPLACE(REGEXP_REPLACE(p.post_content,\"<[^>]+>\",\" \"),\"[[:space:]]+\",\" \")),240),CHAR(9),\" \"),CHAR(10),\" \"),CHAR(13),\" \") snippet
FROM wp_posts p WHERE p.post_type=\"post\" AND p.post_status=\"publish\" ORDER BY p.ID" 2>/dev/null' > "$SAIDA"
echo "$(($(wc -l < "$SAIDA") - 1)) posts em $SAIDA"
