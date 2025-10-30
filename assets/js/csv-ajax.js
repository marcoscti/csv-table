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
                                    var paginationEl = wrap.find('.csv-table-pagination'); // Original pagination element
                                    var csvTableControls = wrap.find('.csv-table-controls'); // Element to place new pagination after
                                    var bottomPaginationEl = $('<div class="csv-table-pagination bottom-pagination"></div>'); // New pagination element
                                    if (csvTableControls.length) {
                                        csvTableControls.after(bottomPaginationEl);
                                    } else {
                                        wrap.append(bottomPaginationEl); // Fallback if controls not found
                                    }
                                    var cache_minutes = wrap.data('cache_minutes') || 60;            var has_header = wrap.data('has_header') === '1' || wrap.data('has_header') === 'true';
            var remove_rows = wrap.data('remove_rows');
            var remove_cols = wrap.data('remove_cols');
            var currentPage = 1;
            var isLoading = false;
            var currentSearchTerm = '';
            var searchTimer = null;
            var currentSort = { column: null, direction: 'asc' };
            var columnFilters = {};

            function fetchData() {
                if (isLoading) return;
                isLoading = true;
                wrap.addClass('loading');

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
                    sort_column: currentSort.column,
                    sort_direction: currentSort.direction,
                    remove_rows: remove_rows,
                    remove_cols: remove_cols,
                    column_filters: columnFilters
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

                var headerRow = $('<tr>');
                headers.forEach(function (header, columnIndex) {
                    var th = $('<th>');

                    if (header.length > 20) {
                        var shortText = header.substring(0, 10) + '...';
                        var headerContent = $('<span>').text(shortText);
                        var toggleLink = $('<a>', { href: '#', text: ' [+ mais]' }).addClass('toggle-text');
                        
                        th.append(headerContent).append(toggleLink);

                        toggleLink.on('click', function(e){
                            e.preventDefault();
                            e.stopPropagation();
                            var isShort = headerContent.text() === shortText;
                            headerContent.text(isShort ? header : shortText);
                            $(this).text(isShort ? ' [- menos]' : ' [+ mais]');
                        });
                    } else {
                        th.text(header);
                    }

                    var filterIcon = $('<span>').addClass('filter-icon').html(' &#128269;');
                    var filterInput = $('<input>').addClass('filter-input').attr('data-column', columnIndex); // Removed .hide()
                    var clearFilter = $('<span>').addClass('clear-filter').html(' &times;').css('cursor', 'pointer').hide();
                    
                    th.append(filterIcon);
                    th.append(filterInput);
                    th.append(clearFilter);

                    // If there's a filter, ensure input and clear icon are visible and populated
                    if (columnFilters[columnIndex] && columnFilters[columnIndex] !== '') {
                        filterInput.val(columnFilters[columnIndex]); // No .show() needed if not hidden initially
                        clearFilter.show();
                    }

                    filterIcon.on('click', function(e) {
                        e.stopPropagation();
                        // If filterInput is visible, hide it and clearFilter.
                        // If filterInput is hidden, show it.
                        if (filterInput.is(':visible')) {
                            filterInput.hide();
                            clearFilter.hide();
                        } else {
                            filterInput.show();
                            if (filterInput.val() !== '') { // Only show clearFilter if there's text
                                clearFilter.show();
                            }
                        }
                    });

                    clearFilter.on('click', function(e) {
                        e.stopPropagation();
                        filterInput.val(''); // Clear value, but don't hide input
                        $(this).hide(); // Hide clear icon
                        delete columnFilters[columnIndex];
                        currentPage = 1;
                        fetchData();
                    });

                    filterInput.on('click', function(e) {
                        e.stopPropagation();
                    });

                    filterInput.on('input', function() {
                        var searchTerm = $(this).val();
                        columnFilters[columnIndex] = searchTerm;
                        
                        if (searchTerm !== '') {
                            clearFilter.show();
                        } else {
                            clearFilter.hide();
                        }

                        clearTimeout(searchTimer);
                        searchTimer = setTimeout(function () {
                            currentPage = 1;
                            fetchData();
                        }, 500);
                    });
                    
                    if (currentSort.column === columnIndex) {
                        th.append(' ' + (currentSort.direction === 'asc' ? '↑' : '↓'));
                        th.addClass('sorted');
                    }
                    
                    th.css('cursor', 'pointer').attr('title', 'Clique para ordenar');
                    th.on('click', function () {
                        handleSort(columnIndex);
                    });
                    
                    headerRow.append(th);
                });
                thead.append(headerRow);

                data.rows.forEach(function (rowData, rowIndex) {
                    var row = $('<tr>').addClass('csv-table-row');
                    row.attr('data-row-index', rowIndex);
                    row.attr('data-page', currentPage);
                    
                    for (var i = 0; i < headers.length; i++) {
                        var cellValue = i < rowData.length ? rowData[i] : '';
                        row.append($('<td>').text(cellValue));
                    }
                    
                    tbody.append(row);
                });

                tbody.off('click', 'tr').on('click', 'tr', function (e) {
                    e.stopPropagation();
                    tbody.find('tr.selected').removeClass('selected');
                    $(this).addClass('selected');
                    var rowData = [];
                    $(this).find('td').each(function () {
                        rowData.push($(this).text());
                    });
                    $(document).trigger('csvTableRowSelected', [rowData, $(this)]);
                });

                updatePagination(data);
            }

            function handleSort(columnIndex) {
                if (currentSort.column === columnIndex) {
                    currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort.column = columnIndex;
                    currentSort.direction = 'asc';
                }
                
                currentPage = 1;
                fetchData();
            }

            function updatePagination(data) {
                paginationEl.empty();
                bottomPaginationEl.empty(); // Clear the new pagination element as well

                var total_pages = data && data.total_pages ? data.total_pages : 1;
                var total_rows = data && data.total_rows ? data.total_rows : 0;
                var current_page = data && data.page ? data.page : 1;
                
                currentPage = current_page;
                
                var infoText = 'Exibindo página ' + currentPage + ' de ' + total_pages +
                    ' | ' + total_rows + ' registro(s) total';
                
                // Append to both original and new pagination elements
                $('<div>').addClass('pagination-info').text(infoText).appendTo(paginationEl);
                $('<div>').addClass('pagination-info').text(infoText).appendTo(bottomPaginationEl);

                if (total_pages <= 1) return;

                var paginationControls = $('<div>').addClass('pagination-controls');
                var bottomPaginationControls = $('<div>').addClass('pagination-controls'); // New controls for bottom

                if (currentPage > 1) {
                    var prevBtn = $('<button>')
                        .addClass('page-btn prev-btn')
                        .html('&laquo; Anterior')
                        .click(function () {
                            currentPage--;
                            fetchData();
                        });
                    prevBtn.clone(true).appendTo(paginationControls); // Clone for original
                    prevBtn.appendTo(bottomPaginationControls); // Append to new
                }

                var startPage = Math.max(1, currentPage - 2);
                var endPage = Math.min(total_pages, startPage + 4);
                
                if (endPage - startPage < 4) {
                    startPage = Math.max(1, endPage - 4);
                }
                
                for (var p = startPage; p <= endPage; p++) {
                    var pageBtn = $('<button>')
                        .addClass('page-btn ' + (p === currentPage ? 'active' : ''))
                        .text(p)
                        .click(function () {
                            currentPage = parseInt($(this).text());
                            fetchData();
                        });
                    pageBtn.clone(true).appendTo(paginationControls); // Clone for original
                    pageBtn.appendTo(bottomPaginationControls); // Append to new
                }

                if (currentPage < total_pages) {
                    var nextBtn = $('<button>')
                        .addClass('page-btn next-btn')
                        .html('Próximo &raquo;')
                        .click(function () {
                            currentPage++;
                            fetchData();
                        });
                    nextBtn.clone(true).appendTo(paginationControls); // Clone for original
                    nextBtn.appendTo(bottomPaginationControls); // Append to new
                }

                paginationControls.appendTo(paginationEl);
                bottomPaginationControls.appendTo(bottomPaginationEl);
            }

            perPageSelect.off('change').on('change', function () {
                currentPage = 1;
                fetchData();
            });

            searchInput.on('input', function () {
                clearTimeout(searchTimer);
                currentSearchTerm = $(this).val();
                searchTimer = setTimeout(function () {
                    currentPage = 1;
                    fetchData();
                }, 500);
            });

            $(document).on('click', function (e) {
                if (!$(e.target).closest('.csv-table tbody tr').length) {
                    tbody.find('tr.selected').removeClass('selected');
                }
            });

            fetchData();
        });
    });

    $(document).on('csvTableRowSelected', function (e, rowData, rowElement) {
        console.log('Linha selecionada:', rowData);
        console.log('Elemento da linha:', rowElement);
    });

})(jQuery);