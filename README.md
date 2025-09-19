# CSV Table Shortcode

## 📌 Descrição
Plugin WordPress para exibir arquivos **CSV remotos** em tabelas dinâmicas com paginação e cache.  

- Faz download do CSV remoto e armazena em **`wp-content/uploads/csv_table_cache/`**.  
- Usa **SplFileObject** para carregar apenas as linhas necessárias (sem ocupar muita memória).  
- Suporte a **busca opcional** (varre o arquivo todo, pode ser mais lenta).  
- Cache configurável via parâmetro `cache_minutes`.  

## ⚙️ Requisitos
- O servidor deve permitir **requisições remotas** (`wp_safe_remote_get`).  
- Precisa de permissão de escrita em `wp-content/uploads/`.  
- Para CSVs grandes, garanta espaço suficiente em disco.  

## 🔒 Segurança
- AJAX protegido com **nonces do WordPress**.  
- URLs remotas são higienizadas.  
- Recomenda-se usar **HTTPS** e fontes confiáveis de CSV.  

## 🚀 Uso
Adicione o shortcode em qualquer post ou página:

```php
[dinamic_table 
  url="https://example.com/file.csv" 
  per_page="10" 
  delimiter=";" 
  cache_minutes="60" 
  has_header="1" 
]
