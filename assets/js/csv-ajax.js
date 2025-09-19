(function ($) {
    $(document).ready(function () {
        $('.csv-table-ajax-wrap').each(function () {
            var wrap = $(this);
            var url = wrap.data('url');
            var perPageSelect = wrap.find('.csv-table-perpage');
            var searchInput = wrap.find('.csv-table-search');
            var table = wrap.find('.csv-table');
            var tbody = table.find('tbody');
            var thead = table.find('thead');
            var paginationEl = wrap.find('.csv-table-pagination');
            var cache_minutes = wrap.data('cache_minutes') || 60;
            var has_header = wrap.data('has_header') === '1' || wrap.data('has_header') === 'true';
            var currentPage = 1;
            var isLoading = false;
            var currentSearchTerm = '';
            var searchTimer = null;
            var currentSort = { column: null, direction: 'asc' }; // Novo: controle de ordenação

            // Função para buscar dados
            function fetchData() {
                if (isLoading) return;
                isLoading = true;
                wrap.addClass('loading');

                // Mostrar loading apenas na primeira vez
                if (tbody.children().length === 0) {
                    tbody.html('<tr><td colspan="10" style="text-align: center; padding: 20px;">Carregando dados...</td></tr>');
                }

                var data = {
                    action: 'csv_table_fetch',
                    nonce: CSVTableAjax.nonce,
                    url: url,
                    per_page: perPageSelect.val(),
                    page: currentPage,
                    cache_minutes: cache_minutes,
                    has_header: has_header ? 1 : 0,
                    search: currentSearchTerm,
                    sort_column: currentSort.column, // Novo: coluna para ordenar
                    sort_direction: currentSort.direction // Novo: direção da ordenação
                };

                $.ajax({
                    url: CSVTableAjax.ajax_url,
                    type: 'POST',
                    data: data,
                    success: function (response) {
                        isLoading = false;
                        wrap.removeClass('loading');
                        if (response.success) {
                            renderTable(response.data);
                        } else {
                            console.error('Error:', response.data);
                            var errorMsg = typeof response.data === 'string' ? response.data : 'Erro desconhecido';
                            tbody.html('<tr><td colspan="10" style="color: red; text-align: center;">Erro: ' + errorMsg + '</td></tr>');
                            paginationEl.empty();
                        }
                    },
                    error: function (xhr, status, error) {
                        isLoading = false;
                        wrap.removeClass('loading');
                        console.error('AJAX Error:', error, xhr.responseText);
                        tbody.html('<tr><td colspan="10" style="color: red; text-align: center;">Erro de conexão</td></tr>');
                        paginationEl.empty();
                    }
                });
            }

            // Função para renderizar a tabela
            function renderTable(data) {
                thead.empty();
                tbody.empty();

                if (!data || !data.rows || data.rows.length === 0) {
                    tbody.html('<tr><td colspan="10" style="text-align: center;">Nenhum dado encontrado</td></tr>');
                    updatePagination(data);
                    return;
                }

                var headers = data.header || [];
                if (headers.length === 0 && data.rows.length > 0) {
                    headers = Array.from({ length: data.rows[0].length }, (_, i) => 'Coluna ' + (i + 1));
                }

                // Renderizar cabeçalho com cliques para ordenação
                var headerRow = $('<tr>');
                headers.forEach(function (header, columnIndex) {
                    var th = $('<th>').text(header);
                    
                    // Adicionar indicador de ordenação
                    if (currentSort.column === columnIndex) {
                        th.append(' ' + (currentSort.direction === 'asc' ? '↑' : '↓'));
                        th.addClass('sorted');
                    }
                    
                    // Evento de clique para ordenação
                    th.css('cursor', 'pointer').attr('title', 'Clique para ordenar');
                    th.on('click', function () {
                        handleSort(columnIndex);
                    });
                    
                    headerRow.append(th);
                });
                thead.append(headerRow);

                // Renderizar linhas de dados
                data.rows.forEach(function (rowData, rowIndex) {
                    var row = $('<tr>').addClass('csv-table-row');
                    
                    // Adicionar data attributes com informações da linha
                    row.attr('data-row-index', rowIndex);
                    row.attr('data-page', currentPage);
                    
                    for (var i = 0; i < headers.length; i++) {
                        var cellValue = i < rowData.length ? rowData[i] : '';
                        row.append($('<td>').text(cellValue));
                    }
                    
                    tbody.append(row);
                });

                // Adicionar evento de clique para as linhas
                tbody.off('click', 'tr').on('click', 'tr', function (e) {
                    e.stopPropagation();
                    
                    // Remover seleção anterior
                    tbody.find('tr.selected').removeClass('selected');
                    
                    // Adicionar seleção à linha clicada
                    $(this).addClass('selected');
                    
                    // Coletar dados da linha selecionada
                    var rowData = [];
                    $(this).find('td').each(function () {
                        rowData.push($(this).text());
                    });
                    
                    // Disparar evento customizado
                    $(document).trigger('csvTableRowSelected', [rowData, $(this)]);
                });

                updatePagination(data);
            }

            // Função para lidar com a ordenação
            function handleSort(columnIndex) {
                // Se já está ordenando por esta coluna, inverter a direção
                if (currentSort.column === columnIndex) {
                    currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    // Nova coluna, ordenar ascendente por padrão
                    currentSort.column = columnIndex;
                    currentSort.direction = 'asc';
                }
                
                // Resetar para primeira página e buscar dados
                currentPage = 1;
                fetchData();
            }

            // Função para atualizar a paginação
            function updatePagination(data) {
                paginationEl.empty();
                var total_pages = data && data.total_pages ? data.total_pages : 1;
                var total_rows = data && data.total_rows ? data.total_rows : 0;
                var current_page = data && data.page ? data.page : 1;
                
                // Atualizar currentPage com o valor retornado pelo servidor
                currentPage = current_page;
                
                var infoText = 'Exibindo página ' + currentPage + ' de ' + total_pages +
                    ' | ' + total_rows + ' registro(s) total';
                $('<div>').addClass('pagination-info').text(infoText).appendTo(paginationEl);

                if (total_pages <= 1) return;

                var paginationControls = $('<div>').addClass('pagination-controls');
                
                // Botão Anterior
                if (currentPage > 1) {
                    $('<button>')
                        .addClass('page-btn prev-btn')
                        .html('&laquo; Anterior')
                        .click(function () {
                            currentPage--;
                            fetchData();
                        })
                        .appendTo(paginationControls);
                }

                // Números de página
                var startPage = Math.max(1, currentPage - 2);
                var endPage = Math.min(total_pages, startPage + 4);
                
                // Ajustar startPage se necessário
                if (endPage - startPage < 4) {
                    startPage = Math.max(1, endPage - 4);
                }
                
                for (var p = startPage; p <= endPage; p++) {
                    $('<button>')
                        .addClass('page-btn ' + (p === currentPage ? 'active' : ''))
                        .text(p)
                        .click(function () {
                            currentPage = parseInt($(this).text());
                            fetchData();
                        })
                        .appendTo(paginationControls);
                }

                // Botão Próximo
                if (currentPage < total_pages) {
                    $('<button>')
                        .addClass('page-btn next-btn')
                        .html('Próximo &raquo;')
                        .click(function () {
                            currentPage++;
                            fetchData();
                        })
                        .appendTo(paginationControls);
                }

                paginationControls.appendTo(paginationEl);
            }

            // Event listener para mudança de itens por página
            perPageSelect.off('change').on('change', function () {
                currentPage = 1;
                fetchData();
            });

            // Busca via backend
            searchInput.on('input', function () {
                clearTimeout(searchTimer);
                currentSearchTerm = $(this).val();
                searchTimer = setTimeout(function () {
                    currentPage = 1;
                    fetchData();
                }, 500);
            });

            // Remover seleção ao clicar fora da tabela
            $(document).on('click', function (e) {
                if (!$(e.target).closest('.csv-table tbody tr').length) {
                    tbody.find('tr.selected').removeClass('selected');
                }
            });

            // Carga inicial
            fetchData();
        });
    });

    // Exemplo de como usar o evento de seleção de linha
    $(document).on('csvTableRowSelected', function (e, rowData, rowElement) {
        console.log('Linha selecionada:', rowData);
        console.log('Elemento da linha:', rowElement);
    });

})(jQuery);