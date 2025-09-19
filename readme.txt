=== CSV Table Shortcode ===
Contributors: marcoscti
Donate link: https://marcoscti.dev
Tags: csv, table, shortcode, datatables, ajax
Requires at least: 5.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Renderize arquivos CSV remotos em tabelas dinâmicas com paginação, busca e filtros usando DataTables e AJAX.

== Description ==

O **CSV Table Shortcode** permite exibir dados de arquivos CSV remotos em uma tabela dinâmica com:

* Paginação via AJAX (carregamento otimizado para grandes arquivos CSV).
* Busca global e filtros por coluna.
* Opção de cache local (para reduzir requisições ao arquivo remoto).
* Integração com DataTables para ordenação, responsividade e experiência avançada.

Usage:
[csv_table url="https://example.com/file.csv" per_page="10" delimiter=";" cache_minutes="60" ]

Description:
- Transmite um CSV remoto para um arquivo de cache em wp-content/uploads/csv_table_cache/
- Utiliza SplFileObject para buscar as linhas da página solicitada, evitando carregar o CSV inteiro na memória.
- Suporta busca opcional (varre o arquivo inteiro e, portanto, é mais lento).
- Armazena em cache o CSV baixado por 'cache_minutes'.

Notes & Requirements:
- O servidor deve permitir requisições remotas (wp_safe_remote_get) e ser capaz de gravar em wp-content/uploads/.
- Para CSVs muito grandes, garanta espaço em disco suficiente para o cache.
- Se a busca for usada, o plugin deve varrer o arquivo inteiro; a paginação sem busca é otimizada.

Security:
- Requisições AJAX usam WP nonces.
- URLs remotas são higienizadas. Tenha cuidado com fontes CSV. Use HTTPS sempre que possível.