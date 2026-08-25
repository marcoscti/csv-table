<?php

/**
 * Plugin Name: CSV Table
 * Description: Shortcode para ler um CSV remoto e renderizar uma tabela paginada do lado do servidor via AJAX. Otimizado para transmitir arquivos CSV grandes. Uso: [csv_table url="https://example.com/file.csv" per_page="10" cache_minutes="60" delimiter=","]
 * Version: 2.1.1
 * Author:            Marcos Cordeiro
 * Author URI:        https://github.com/marcoscti
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: marcoscti
 */

if (! defined('ABSPATH')) {
    exit;
}
define('CSV_TABLE_VERSION', '2.1.1');
class CSV_Table_Shortcode
{
    private $cache_dir;

    public function __construct()
    {
        add_shortcode('csv_table', array($this, 'shortcode_handler'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_csv_table_fetch', array($this, 'ajax_fetch'));
        add_action('wp_ajax_nopriv_csv_table_fetch', array($this, 'ajax_fetch'));
        add_action('wp_ajax_csv_table_download_json', array($this, 'ajax_download_json'));
        add_action('wp_ajax_nopriv_csv_table_download_json', array($this, 'ajax_download_json'));
        add_action('wp_ajax_csv_table_download_xml', array($this, 'ajax_download_xml'));
        add_action('wp_ajax_nopriv_csv_table_download_xml', array($this, 'ajax_download_xml'));
        add_action('wp_ajax_csv_table_download_xlsx', array($this, 'ajax_download_xlsx'));
        add_action('wp_ajax_nopriv_csv_table_download_xlsx', array($this, 'ajax_download_xlsx'));

        $upload_dir = wp_upload_dir();
        $this->cache_dir = trailingslashit($upload_dir['basedir']) . 'csv_table_cache/';
        if (! file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
    }

    public function enqueue_assets()
    {
        wp_enqueue_style('datatables-css', 'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css', array(), '1.13.6');
        wp_enqueue_script('datatables-js', 'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js', array('jquery'), '1.13.6', true);
        wp_enqueue_script('csv-table-ajax-js', plugins_url('assets/js/csv-ajax.js', __FILE__), array(), CSV_TABLE_VERSION, "all");
        wp_enqueue_style('csv-table-ajax-css', plugins_url('assets/css/csv-style.css', __FILE__), array(), CSV_TABLE_VERSION, "all");
        wp_enqueue_style('csv-table-filter-css', plugins_url('assets/css/csv-filter.css', __FILE__), array(), CSV_TABLE_VERSION, "all");
        wp_localize_script('csv-table-ajax-js', 'CSVTableAjax', array('ajax_url' => admin_url('admin-ajax.php'), 'nonce'    => wp_create_nonce('csv_table_ajax_nonce'),));
    }

    private function get_cache_paths($url)
    {
        $hash = md5($url);
        return array(
            'file' => $this->cache_dir . $hash . '.json',
            'meta' => $this->cache_dir . $hash . '.meta.json',
        );
    }

    private function ensure_local_copy($url, $cache_minutes = 60, $delimiter = ';')
    {
        $paths = $this->get_cache_paths($url);
        $file = $paths['file'];
        $meta_file = $paths['meta'];

        if (file_exists($file) && file_exists($meta_file)) {
            $meta = json_decode(file_get_contents($meta_file), true);

            if (isset($meta['saved_at']) && (time() - intval($meta['saved_at'])) < (intval($cache_minutes) * MINUTE_IN_SECONDS)) {
                return $file;
            }
        }

        $tmpfile = $file . '.tmp';
        if (file_exists($tmpfile)) @unlink($tmpfile);

        $args = array(
            'timeout'   => 120,
            'redirection' => 5,
            'httpversion' => '1.1',
            'stream'    => true,
            'filename'  => $tmpfile,
            'sslverify' => false,
        );
        $resp = wp_remote_get(esc_url_raw($url), $args);

        if (is_wp_error($resp)) {
            if (file_exists($tmpfile)) @unlink($tmpfile);
            if (file_exists($file)) {
                return $file;
            }
            return new WP_Error('download_failed', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            if (file_exists($tmpfile)) @unlink($tmpfile);
            return new WP_Error('bad_status', 'HTTP status ' . $code);
        }

        if (file_exists($tmpfile)) {
            try {
                $csv_data = array();
                $header = array();
                $first_line = true;

                $csv_file = new SplFileObject($tmpfile, 'r');
                $csv_file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
                $csv_file->setCsvControl($delimiter);

                foreach ($csv_file as $row) {
                    if ($row === null || (is_array($row) && count($row) === 1 && $row[0] === null)) {
                        continue;
                    }

                    if ($first_line) {
                        $header = $row;
                        $first_line = false;
                    } else {
                        $csv_data[] = $row;
                    }
                }

                $json_data = array(
                    'header' => $header,
                    'data' => $csv_data
                );

                if (file_exists($file)) @unlink($file);
                file_put_contents($file, wp_json_encode($json_data));

                @unlink($tmpfile);

                $meta = array('saved_at' => time(), 'url' => $url);
                file_put_contents($meta_file, wp_json_encode($meta));

                return $file;
            } catch (Exception $e) {
                if (file_exists($tmpfile)) @unlink($tmpfile);
                return new WP_Error('conversion_error', 'CSV to JSON conversion failed: ' . $e->getMessage());
            }
        } else {
            return new WP_Error('no_file', 'No file downloaded');
        }
    }

    private function get_json_data($filepath)
    {
        if (!file_exists($filepath)) {
            return array('header' => array(), 'data' => array());
        }

        $json_content = file_get_contents($filepath);
        $data = json_decode($json_content, true);

        if (!is_array($data)) {
            return array('header' => array(), 'data' => array());
        }

        return array(
            'header' => isset($data['header']) ? $data['header'] : array(),
            'data' => isset($data['data']) ? $data['data'] : array()
        );
    }

    public function ajax_fetch()
    {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csv_table_ajax_nonce')) {
            wp_send_json_error('Nonce verification failed', 403);
            wp_die();
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $per_page = isset($_POST['per_page']) ? max(1, intval($_POST['per_page'])) : 10;
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $cache_minutes = isset($_POST['cache_minutes']) ? max(0, intval($_POST['cache_minutes'])) : 60;
        $has_header = isset($_POST['has_header']) ? ($_POST['has_header'] === '1' || $_POST['has_header'] === 'true') : true;
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $delimiter = isset($_POST['delimiter']) ? sanitize_text_field(wp_unslash($_POST['delimiter'])) : ',';
        $column_filters = isset($_POST['column_filters']) && is_array($_POST['column_filters']) ? array_map('sanitize_text_field', $_POST['column_filters']) : array();

        if (empty($url)) {
            wp_send_json_error('Missing URL parameter', 400);
            wp_die();
        }

        $local = $this->ensure_local_copy($url, $cache_minutes, $delimiter);
        if (is_wp_error($local)) {
            wp_send_json_error('Failed to load CSV: ' . $local->get_error_message(), 500);
            wp_die();
        }

        $json_data = $this->get_json_data($local);
        $header = $json_data['header'];
        $all_data = $json_data['data'];

        $filtered_data = $all_data;
        if ($search !== '') {
            $search_lower = trim(strtolower($search));
            $filtered_data = array_filter($filtered_data, function ($row) use ($search_lower) {
                foreach ($row as $cell) {
                    if (strpos(strtolower(strval($cell)), $search_lower) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        if (!empty($column_filters)) {
            foreach ($column_filters as $col_index => $filter_value) {
                if ($filter_value !== '') {
                    $filter_value_lower = trim(strtolower($filter_value));
                    $filtered_data = array_filter($filtered_data, function ($row) use ($col_index, $filter_value_lower) {
                        return isset($row[$col_index]) && strpos(strtolower(strval($row[$col_index])), $filter_value_lower) !== false;
                    });
                }
            }
        }

        $total_rows = count($filtered_data);
        $total_pages = max(1, ceil($total_rows / $per_page));

        if ($page > $total_pages) {
            $page = $total_pages;
        }

        $start = ($page - 1) * $per_page;
        $page_data = array_slice(array_values($filtered_data), $start, $per_page);

        if (empty($header) && $has_header && !empty($page_data)) {
            $col_count = count($page_data[0]);
            $header = array();
            for ($i = 0; $i < $col_count; $i++) {
                $header[] = 'Coluna ' . ($i + 1);
            }
        }

        $safe_rows = array();
        foreach ($page_data as $row) {
            $safe_row = array();
            foreach ($row as $cell) {
                $safe_row[] = is_null($cell) ? '' : strval($cell);
            }
            $safe_rows[] = $safe_row;
        }

        $safe_header = array();
        foreach ($header as $cell) {
            $safe_header[] = is_null($cell) ? '' : strval($cell);
        }


        wp_send_json_success(array(
            'header' => $safe_header,
            'rows' => $safe_rows,
            'total_rows' => $total_rows,
            'total_pages' => $total_pages,
            'page' => $page,
        ));
        wp_die();
    }

    public function ajax_download_json()
    {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'csv_table_ajax_nonce')) {
            wp_die('Acesso negado.', 403);
        }

        $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
        if (empty($url)) {
            wp_die('Parâmetro url ausente.', 400);
        }

        $hash = md5($url);
        $file = $this->cache_dir . $hash . '.json';

        if (!file_exists($file)) {
            wp_die('Arquivo JSON não encontrado. Carregue a tabela primeiro.', 404);
        }

        $filename = sanitize_file_name(basename(parse_url($url, PHP_URL_PATH), '.csv') . '.json');

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($file);
        exit;
    }

    public function ajax_download_xml()
    {
        if (
            !isset($_GET['nonce']) ||
            !wp_verify_nonce($_GET['nonce'], 'csv_table_ajax_nonce')
        ) {
            wp_die('Acesso negado.', 403);
        }

        $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';

        if (empty($url)) {
            wp_die('Parâmetro url ausente.', 400);
        }

        $hash = md5($url);
        $file = $this->cache_dir . $hash . '.json';

        if (!file_exists($file)) {
            wp_die(
                'Arquivo JSON não encontrado. Carregue a tabela primeiro.',
                404
            );
        }

        $data = json_decode(file_get_contents($file), true);

        if (!is_array($data)) {
            wp_die('Arquivo JSON inválido.', 500);
        }

        $headers = isset($data['header']) && is_array($data['header'])
            ? $data['header']
            : [];

        $rows = isset($data['data']) && is_array($data['data'])
            ? $data['data']
            : [];

        /*
     * Cria o XML
     */
        $xml = new SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?><data></data>'
        );

        /*
     * Guarda os nomes de tags já utilizados.
     * Isso evita duplicidade caso duas colunas diferentes
     * resultem no mesmo nome depois da sanitização.
     */
        $used_tags = [];

        /*
     * Gera os nomes das colunas dinamicamente
     */
        $tags = [];

        foreach ($headers as $i => $header) {

            $tag = (string) $header;

            /*
         * Remove espaços no início/fim
         */
            $tag = trim($tag);

            /*
         * Remove acentos.
         *
         * Exemplo:
         * "Descrição" -> "Descricao"
         * "Admissão"  -> "Admissao"
         */
            $tag = remove_accents($tag);

            /*
         * Substitui qualquer caractere que não seja
         * letra, número ou underscore.
         */
            $tag = preg_replace('/[^a-zA-Z0-9_]/', '_', $tag);

            /*
         * Remove underscores duplicados
         */
            $tag = preg_replace('/_+/', '_', $tag);

            /*
         * Remove underscore no início/fim
         */
            $tag = trim($tag, '_');

            /*
         * Se ficou vazio, cria um nome genérico.
         */
            if ($tag === '') {
                $tag = 'field_' . $i;
            }

            /*
         * XML não permite que o nome do elemento
         * comece com número.
         */
            if (preg_match('/^[0-9]/', $tag)) {
                $tag = 'field_' . $tag;
            }

            /*
         * XML não permite nomes começando com "xml"
         * em qualquer combinação de maiúsculas/minúsculas.
         */
            if (preg_match('/^xml/i', $tag)) {
                $tag = 'field_' . $tag;
            }

            /*
         * Garante que não existam duas tags iguais.
         */
            $base_tag = $tag;
            $suffix = 1;

            while (isset($used_tags[$tag])) {
                $tag = $base_tag . '_' . $suffix;
                $suffix++;
            }

            $used_tags[$tag] = true;

            /*
         * Guarda a relação:
         *
         * índice da coluna -> nome da tag XML
         */
            $tags[$i] = $tag;
        }

        /*
     * Gera os registros
     */
        foreach ($rows as $row) {

            $record = $xml->addChild('record');

            foreach ($headers as $i => $header) {

                /*
             * Usa o nome gerado dinamicamente acima
             */
                $tag = $tags[$i];

                /*
             * Obtém o valor da célula
             */
                $value = isset($row[$i]) ? $row[$i] : '';

                /*
             * Garante que seja string
             */
                if (is_array($value) || is_object($value)) {
                    $value = wp_json_encode(
                        $value,
                        JSON_UNESCAPED_UNICODE
                    );
                }

                $value = (string) $value;

                /*
             * Remove caracteres que não são permitidos
             * em XML 1.0.
             *
             * Isso é importante para CSVs que possam conter
             * caracteres de controle invisíveis.
             */
                $value = preg_replace(
                    '/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u',
                    '',
                    $value
                );

                /*
             * NÃO usar htmlspecialchars aqui.
             *
             * SimpleXMLElement já escapa:
             *
             * & -> &amp;
             * < -> &lt;
             * > -> &gt;
             * etc.
             */
                $record->addChild($tag, $value);
            }
        }

        /*
     * Nome do arquivo
     */
        $path = parse_url($url, PHP_URL_PATH);
        $base = pathinfo($path, PATHINFO_FILENAME);

        if (empty($base)) {
            $base = 'exportacao';
        }

        $filename = sanitize_file_name($base . '.xml');

        /*
     * Remove qualquer saída anterior.
     */
        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: application/xml; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="' . $filename . '"'
        );
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $xml->asXML();
        exit;
    }

    public function ajax_download_xlsx()
    {
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'csv_table_ajax_nonce')) {
            wp_die('Acesso negado.', 403);
        }

        $url = isset($_GET['url']) ? esc_url_raw($_GET['url']) : '';
        if (empty($url)) {
            wp_die('Parâmetro url ausente.', 400);
        }

        if (!class_exists('ZipArchive')) {
            wp_die('A extensão ZipArchive não está disponível no servidor.', 500);
        }

        $hash = md5($url);
        $file = $this->cache_dir . $hash . '.json';

        if (!file_exists($file)) {
            wp_die('Arquivo JSON não encontrado. Carregue a tabela primeiro.', 404);
        }

        $data    = json_decode(file_get_contents($file), true);
        $headers = isset($data['header']) ? $data['header'] : array();
        $rows    = isset($data['data'])   ? $data['data']   : array();

        $xlsx_content = $this->generate_xlsx($headers, $rows);
        $filename     = sanitize_file_name(basename(parse_url($url, PHP_URL_PATH), '.csv') . '.xlsx');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xlsx_content));
        header('Cache-Control: no-cache, must-revalidate');
        echo $xlsx_content;
        exit;
    }

    private function generate_xlsx($headers, $rows)
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'csvtbl_') . '.xlsx';

        $zip = new ZipArchive();
        $zip->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
                '<Default Extension="xml" ContentType="application/xml"/>' .
                '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
                '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
                '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
                '</Types>'
        );

        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
                '</Relationships>'
        );

        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
                '<sheets><sheet name="Planilha1" sheetId="1" r:id="rId1"/></sheets>' .
                '</workbook>'
        );

        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
                '</Relationships>'
        );

        $zip->addFromString(
            'xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
                '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
                '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>' .
                '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
                '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
                '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
                '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>' .
                '</styleSheet>'
        );

        // Build worksheet XML
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        // Header row
        $sheet .= '<row r="1">';
        foreach ($headers as $ci => $col) {
            $ref    = $this->xlsx_col_letter($ci) . '1';
            $escaped = htmlspecialchars((string) $col, ENT_XML1, 'UTF-8');
            $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
        }
        $sheet .= '</row>';

        // Data rows
        foreach ($rows as $ri => $row) {
            $er = $ri + 2;
            $sheet .= '<row r="' . $er . '">';
            foreach ($headers as $ci => $col) {
                $ref   = $this->xlsx_col_letter($ci) . $er;
                $value = isset($row[$ci]) ? (string) $row[$ci] : '';
                if ($value !== '' && is_numeric($value)) {
                    $sheet .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                } else {
                    $escaped = htmlspecialchars($value, ENT_XML1, 'UTF-8');
                    $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
                }
            }
            $sheet .= '</row>';
        }

        $sheet .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $content = file_get_contents($tmpfile);
        @unlink($tmpfile);
        return $content;
    }

    private function xlsx_col_letter($index)
    {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index  = intval($index / 26) - 1;
        }
        return $letter;
    }

    public function shortcode_handler($atts)
    {
        $atts = shortcode_atts(array(
            'url' => '',
            'per_page' => 10,
            'cache_minutes' => 60,
            'delimiter' => ';',
            'has_header' => 1,
            'hide_csv' => 0,
            'hide_xml' => 0,
            'hide_xlsx' => 0,
            'hide_json' => 0
        ), $atts, 'csv_table');

        if (empty($atts['url'])) {
            return '<div class="csv-table-error">Dynamic Table: falta o atributo <code>url</code>.</div>';
        }

        $json_url = add_query_arg(array(
            'action' => 'csv_table_download_json',
            'url'    => rawurlencode($atts['url']),
            'nonce'  => wp_create_nonce('csv_table_ajax_nonce'),
        ), admin_url('admin-ajax.php'));

        $xml_url = add_query_arg(array(
            'action' => 'csv_table_download_xml',
            'url'    => rawurlencode($atts['url']),
            'nonce'  => wp_create_nonce('csv_table_ajax_nonce'),
        ), admin_url('admin-ajax.php'));

        $xlsx_url = add_query_arg(array(
            'action' => 'csv_table_download_xlsx',
            'url'    => rawurlencode($atts['url']),
            'nonce'  => wp_create_nonce('csv_table_ajax_nonce'),
        ), admin_url('admin-ajax.php'));

        $uid = 'csv_table_' . uniqid();
        ob_start();
?>
        <div class="links-download">
            <?php if (empty($atts['hide_csv'])): ?>
                <a href="<?php echo esc_url($atts['url']); ?>" class="download-icon" target="_blank" rel="noopener noreferrer" title="Baixar arquivo no formato CSV"><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="40px" height="40px" viewBox="0 0 40 40" version="1.1">
                        <g id="surface1">
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 39.988281 18.28125 C 39.984375 18.207031 39.96875 18.132812 39.945312 18.0625 C 39.9375 18.035156 39.929688 18.007812 39.917969 17.984375 C 39.878906 17.898438 39.828125 17.816406 39.757812 17.746094 C 39.753906 17.746094 39.753906 17.746094 39.753906 17.746094 L 35 12.988281 L 35 8.332031 C 35 8.316406 34.992188 8.300781 34.988281 8.28125 C 34.984375 8.207031 34.96875 8.136719 34.945312 8.066406 C 34.9375 8.039062 34.929688 8.015625 34.917969 7.988281 C 34.878906 7.898438 34.828125 7.816406 34.753906 7.746094 L 27.253906 0.246094 C 27.183594 0.171875 27.097656 0.121094 27.011719 0.0820312 C 26.984375 0.0703125 26.960938 0.0625 26.933594 0.0546875 C 26.863281 0.0273438 26.789062 0.015625 26.714844 0.0117188 C 26.699219 0.0078125 26.683594 0 26.667969 0 L 5.832031 0 C 5.375 0 5 0.375 5 0.832031 L 5 12.988281 L 0.246094 17.746094 C 0.246094 17.746094 0.242188 17.746094 0.242188 17.746094 C 0.171875 17.816406 0.121094 17.898438 0.0820312 17.984375 C 0.0703125 18.007812 0.0625 18.035156 0.0546875 18.0625 C 0.03125 18.132812 0.015625 18.207031 0.0117188 18.28125 C 0.0078125 18.300781 0 18.316406 0 18.332031 L 0 34.167969 C 0 34.625 0.375 35 0.832031 35 L 5 35 L 5 39.167969 C 5 39.625 5.375 40 5.832031 40 L 34.167969 40 C 34.625 40 35 39.625 35 39.167969 L 35 35 L 39.167969 35 C 39.625 35 40 34.625 40 34.167969 L 40 18.332031 C 40 18.316406 39.992188 18.300781 39.988281 18.28125 Z M 37.15625 17.5 L 35 17.5 L 35 15.34375 Z M 32.15625 7.5 L 27.5 7.5 L 27.5 2.84375 Z M 6.667969 1.667969 L 25.832031 1.667969 L 25.832031 8.332031 C 25.832031 8.792969 26.207031 9.167969 26.667969 9.167969 L 33.332031 9.167969 L 33.332031 17.5 L 6.667969 17.5 Z M 5 15.34375 L 5 17.5 L 2.84375 17.5 Z M 33.332031 38.332031 L 6.667969 38.332031 L 6.667969 35 L 33.332031 35 Z M 38.332031 33.332031 L 1.667969 33.332031 L 1.667969 19.167969 L 38.332031 19.167969 Z M 38.332031 33.332031 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 19.519531 23.238281 C 19.640625 23.125 19.777344 23.042969 19.929688 22.988281 C 20.078125 22.9375 20.234375 22.910156 20.398438 22.910156 C 20.953125 22.910156 21.417969 23.132812 21.785156 23.578125 L 22.71875 22.359375 C 22.445312 22.039062 22.109375 21.796875 21.707031 21.628906 C 21.304688 21.464844 20.851562 21.382812 20.339844 21.382812 C 19.992188 21.382812 19.648438 21.441406 19.308594 21.558594 C 18.964844 21.675781 18.660156 21.859375 18.390625 22.105469 C 18.125 22.351562 17.90625 22.660156 17.742188 23.03125 C 17.574219 23.40625 17.492188 23.851562 17.492188 24.371094 C 17.492188 24.785156 17.546875 25.140625 17.65625 25.441406 C 17.761719 25.738281 17.910156 25.996094 18.09375 26.21875 C 18.277344 26.441406 18.492188 26.632812 18.730469 26.792969 C 18.972656 26.953125 19.230469 27.101562 19.503906 27.234375 C 19.9375 27.449219 20.296875 27.6875 20.582031 27.941406 C 20.863281 28.195312 21.003906 28.539062 21.003906 28.976562 C 21.003906 29.417969 20.886719 29.761719 20.652344 30.007812 C 20.414062 30.253906 20.117188 30.378906 19.757812 30.378906 C 19.4375 30.378906 19.125 30.300781 18.816406 30.152344 C 18.507812 30 18.246094 29.792969 18.03125 29.527344 L 17.109375 30.773438 C 17.390625 31.105469 17.769531 31.378906 18.242188 31.597656 C 18.714844 31.8125 19.230469 31.921875 19.785156 31.921875 C 20.175781 31.921875 20.542969 31.855469 20.890625 31.722656 C 21.242188 31.589844 21.546875 31.394531 21.804688 31.136719 C 22.066406 30.875 22.273438 30.558594 22.429688 30.179688 C 22.585938 29.800781 22.664062 29.367188 22.664062 28.875 C 22.664062 28.449219 22.597656 28.082031 22.464844 27.769531 C 22.332031 27.460938 22.164062 27.191406 21.953125 26.964844 C 21.746094 26.738281 21.515625 26.542969 21.261719 26.382812 C 21.003906 26.222656 20.753906 26.078125 20.511719 25.957031 C 20.09375 25.75 19.757812 25.53125 19.503906 25.296875 C 19.25 25.066406 19.121094 24.738281 19.121094 24.3125 C 19.121094 24.066406 19.15625 23.855469 19.226562 23.675781 C 19.300781 23.496094 19.394531 23.351562 19.519531 23.238281 Z M 19.519531 23.238281 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 12.648438 24.046875 C 12.816406 23.714844 13.03125 23.453125 13.285156 23.257812 C 13.539062 23.066406 13.835938 22.96875 14.175781 22.96875 C 14.792969 22.96875 15.292969 23.246094 15.679688 23.804688 L 16.65625 22.613281 C 16.382812 22.21875 16.027344 21.914062 15.59375 21.699219 C 15.160156 21.488281 14.652344 21.382812 14.078125 21.382812 C 13.519531 21.382812 13.011719 21.511719 12.554688 21.769531 C 12.097656 22.03125 11.707031 22.394531 11.386719 22.863281 C 11.066406 23.328125 10.816406 23.886719 10.640625 24.535156 C 10.46875 25.179688 10.378906 25.886719 10.378906 26.652344 C 10.378906 27.417969 10.46875 28.121094 10.640625 28.761719 C 10.816406 29.40625 11.0625 29.960938 11.378906 30.425781 C 11.695312 30.894531 12.082031 31.261719 12.539062 31.523438 C 13 31.789062 13.511719 31.921875 14.078125 31.921875 C 14.703125 31.921875 15.21875 31.800781 15.628906 31.554688 C 16.039062 31.308594 16.378906 30.988281 16.640625 30.589844 L 15.664062 29.441406 C 15.503906 29.6875 15.300781 29.894531 15.0625 30.066406 C 14.824219 30.234375 14.535156 30.320312 14.207031 30.320312 C 13.855469 30.320312 13.550781 30.222656 13.292969 30.03125 C 13.03125 29.835938 12.816406 29.574219 12.648438 29.246094 C 12.476562 28.914062 12.347656 28.523438 12.265625 28.074219 C 12.179688 27.625 12.136719 27.152344 12.136719 26.652344 C 12.136719 26.140625 12.179688 25.664062 12.265625 25.214844 C 12.351562 24.765625 12.476562 24.375 12.648438 24.046875 Z M 12.648438 24.046875 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 26.671875 29.683594 L 26.628906 29.683594 L 24.957031 21.636719 L 23.058594 21.636719 L 25.511719 31.667969 L 27.664062 31.667969 L 30.15625 21.636719 L 28.34375 21.636719 Z M 26.671875 29.683594 " />
                        </g>
                    </svg>
                    <span>Baixar</span>
                </a>
            <?php endif; ?>
            <?php if (empty($atts['hide_json'])): ?>
                <a href="<?php echo esc_url($json_url); ?>" class="download-icon" target="_blank" rel="noopener noreferrer" title="Baixar arquivo no formato JSON">
                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="40px" height="40px" viewBox="0 0 40 40" version="1.1">
                        <g id="surface1">
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 35.136719 8.40625 L 34.199219 7.464844 L 27.113281 0.378906 C 26.867188 0.132812 26.542969 0 26.195312 0 L 6.179688 0 C 5.363281 0 4.484375 0.632812 4.484375 2.019531 L 4.484375 38.621094 C 4.484375 39.199219 5.0625 39.761719 5.75 39.9375 C 5.785156 39.945312 5.816406 39.960938 5.855469 39.96875 C 5.960938 39.988281 6.070312 40 6.179688 40 L 33.820312 40 C 33.929688 40 34.039062 39.988281 34.144531 39.96875 C 34.183594 39.960938 34.214844 39.945312 34.25 39.9375 C 34.9375 39.761719 35.515625 39.199219 35.515625 38.621094 L 35.515625 9.640625 C 35.515625 9.109375 35.453125 8.71875 35.136719 8.40625 Z M 27.242188 2.457031 L 33.058594 8.277344 L 27.242188 8.277344 Z M 6.179688 38.621094 C 6.132812 38.621094 6.089844 38.601562 6.042969 38.585938 C 5.9375 38.535156 5.863281 38.429688 5.863281 38.300781 L 5.863281 28.277344 L 34.136719 28.277344 L 34.136719 38.300781 C 34.136719 38.429688 34.0625 38.535156 33.957031 38.585938 C 33.910156 38.601562 33.867188 38.621094 33.820312 38.621094 Z M 5.863281 26.898438 L 5.863281 2.019531 C 5.863281 1.867188 5.886719 1.378906 6.179688 1.378906 L 25.902344 1.378906 C 25.878906 1.464844 25.863281 1.554688 25.863281 1.648438 L 25.863281 9.503906 C 25.496094 9.171875 25.015625 8.964844 24.484375 8.964844 C 24.101562 8.964844 23.792969 9.273438 23.792969 9.65625 C 23.792969 10.035156 24.101562 10.34375 24.484375 10.34375 C 24.863281 10.34375 25.171875 10.65625 25.171875 11.035156 L 25.171875 13.792969 C 25.171875 14.621094 25.546875 15.355469 26.125 15.863281 C 25.546875 16.367188 25.171875 17.101562 25.171875 17.929688 L 25.171875 20.691406 C 25.171875 21.070312 24.863281 21.378906 24.484375 21.378906 C 24.101562 21.378906 23.792969 21.6875 23.792969 22.070312 C 23.792969 22.449219 24.101562 22.757812 24.484375 22.757812 C 25.625 22.757812 26.550781 21.832031 26.550781 20.691406 L 26.550781 17.929688 C 26.550781 17.171875 27.171875 16.550781 27.929688 16.550781 C 28.3125 16.550781 28.621094 16.242188 28.621094 15.863281 C 28.621094 15.480469 28.3125 15.171875 27.929688 15.171875 C 27.171875 15.171875 26.550781 14.554688 26.550781 13.792969 L 26.550781 11.035156 C 26.550781 10.503906 26.34375 10.023438 26.015625 9.65625 L 33.867188 9.65625 C 33.960938 9.65625 34.050781 9.636719 34.136719 9.613281 C 34.136719 9.625 34.136719 9.628906 34.136719 9.640625 L 34.136719 26.898438 Z M 5.863281 26.898438 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 11.277344 35.46875 C 11.265625 35.777344 11.160156 35.996094 10.960938 36.125 C 10.765625 36.253906 10.5 36.316406 10.167969 36.316406 C 10.035156 36.316406 9.894531 36.300781 9.742188 36.269531 C 9.589844 36.238281 9.445312 36.203125 9.308594 36.160156 C 9.171875 36.121094 9.042969 36.078125 8.925781 36.03125 C 8.808594 35.984375 8.71875 35.9375 8.65625 35.894531 L 8.175781 36.65625 C 8.304688 36.75 8.457031 36.835938 8.636719 36.910156 C 8.820312 36.988281 9.011719 37.054688 9.207031 37.113281 C 9.40625 37.175781 9.597656 37.21875 9.785156 37.246094 C 9.96875 37.273438 10.132812 37.289062 10.269531 37.289062 C 10.546875 37.289062 10.816406 37.261719 11.074219 37.207031 C 11.335938 37.15625 11.566406 37.0625 11.769531 36.925781 C 11.96875 36.792969 12.128906 36.613281 12.25 36.390625 C 12.367188 36.167969 12.429688 35.890625 12.429688 35.5625 L 12.429688 30.152344 L 11.277344 30.152344 Z M 11.277344 35.46875 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 17.296875 33.835938 C 17.082031 33.679688 16.847656 33.546875 16.597656 33.4375 C 16.34375 33.328125 16.113281 33.214844 15.898438 33.097656 C 15.683594 32.980469 15.503906 32.847656 15.359375 32.691406 C 15.214844 32.539062 15.144531 32.335938 15.144531 32.082031 C 15.144531 31.984375 15.167969 31.878906 15.21875 31.769531 C 15.269531 31.65625 15.339844 31.558594 15.425781 31.46875 C 15.515625 31.382812 15.613281 31.308594 15.730469 31.25 C 15.84375 31.1875 15.960938 31.152344 16.085938 31.140625 C 16.3125 31.121094 16.5 31.117188 16.644531 31.132812 C 16.789062 31.144531 16.90625 31.164062 16.992188 31.195312 C 17.082031 31.230469 17.148438 31.261719 17.195312 31.292969 C 17.242188 31.324219 17.285156 31.347656 17.324219 31.367188 C 17.328125 31.359375 17.347656 31.328125 17.378906 31.273438 C 17.410156 31.214844 17.449219 31.144531 17.492188 31.058594 C 17.535156 30.976562 17.585938 30.882812 17.632812 30.785156 C 17.683594 30.691406 17.730469 30.601562 17.765625 30.519531 C 17.582031 30.398438 17.34375 30.3125 17.050781 30.257812 C 16.753906 30.207031 16.460938 30.179688 16.171875 30.179688 C 15.890625 30.179688 15.621094 30.222656 15.367188 30.308594 C 15.113281 30.398438 14.890625 30.527344 14.695312 30.691406 C 14.503906 30.859375 14.351562 31.0625 14.238281 31.300781 C 14.125 31.539062 14.070312 31.8125 14.070312 32.121094 C 14.070312 32.460938 14.140625 32.742188 14.285156 32.96875 C 14.429688 33.195312 14.613281 33.390625 14.832031 33.558594 C 15.050781 33.726562 15.289062 33.871094 15.539062 33.992188 C 15.789062 34.113281 16.023438 34.238281 16.242188 34.359375 C 16.460938 34.484375 16.640625 34.621094 16.785156 34.769531 C 16.929688 34.921875 17 35.101562 17 35.316406 C 17 35.667969 16.898438 35.933594 16.695312 36.113281 C 16.492188 36.292969 16.195312 36.382812 15.8125 36.382812 C 15.6875 36.382812 15.550781 36.371094 15.40625 36.34375 C 15.261719 36.320312 15.117188 36.285156 14.972656 36.246094 C 14.828125 36.203125 14.691406 36.160156 14.554688 36.113281 C 14.417969 36.066406 14.308594 36.019531 14.21875 35.96875 L 14.023438 36.777344 C 14.128906 36.875 14.257812 36.953125 14.402344 37.019531 C 14.550781 37.085938 14.707031 37.136719 14.871094 37.175781 C 15.035156 37.214844 15.195312 37.238281 15.355469 37.253906 C 15.515625 37.269531 15.671875 37.277344 15.824219 37.277344 C 16.175781 37.277344 16.488281 37.226562 16.765625 37.117188 C 17.042969 37.011719 17.273438 36.867188 17.464844 36.691406 C 17.652344 36.511719 17.796875 36.304688 17.902344 36.066406 C 18.007812 35.832031 18.058594 35.589844 18.058594 35.335938 C 18.058594 34.964844 17.988281 34.660156 17.84375 34.425781 C 17.695312 34.191406 17.515625 33.996094 17.296875 33.835938 Z M 17.296875 33.835938 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 24.050781 31.085938 C 23.789062 30.789062 23.484375 30.5625 23.136719 30.410156 C 22.785156 30.253906 22.402344 30.179688 21.988281 30.179688 C 21.574219 30.179688 21.191406 30.253906 20.84375 30.410156 C 20.496094 30.5625 20.191406 30.789062 19.929688 31.085938 C 19.667969 31.378906 19.464844 31.75 19.316406 32.191406 C 19.167969 32.632812 19.09375 33.148438 19.09375 33.734375 C 19.09375 34.316406 19.167969 34.832031 19.316406 35.28125 C 19.464844 35.726562 19.667969 36.097656 19.929688 36.390625 C 20.191406 36.6875 20.496094 36.910156 20.84375 37.0625 C 21.191406 37.210938 21.574219 37.289062 21.988281 37.289062 C 22.402344 37.289062 22.785156 37.210938 23.136719 37.0625 C 23.484375 36.910156 23.789062 36.6875 24.050781 36.390625 C 24.308594 36.097656 24.515625 35.726562 24.664062 35.28125 C 24.808594 34.832031 24.882812 34.316406 24.882812 33.734375 C 24.882812 33.148438 24.808594 32.632812 24.664062 32.191406 C 24.515625 31.75 24.308594 31.378906 24.050781 31.085938 Z M 23.582031 34.964844 C 23.488281 35.300781 23.359375 35.574219 23.191406 35.78125 C 23.027344 35.988281 22.835938 36.136719 22.625 36.226562 C 22.414062 36.316406 22.195312 36.363281 21.960938 36.363281 C 21.734375 36.363281 21.519531 36.316406 21.316406 36.21875 C 21.109375 36.121094 20.929688 35.96875 20.769531 35.757812 C 20.609375 35.542969 20.480469 35.273438 20.390625 34.941406 C 20.300781 34.605469 20.25 34.203125 20.246094 33.734375 C 20.25 33.25 20.300781 32.839844 20.394531 32.507812 C 20.492188 32.175781 20.621094 31.902344 20.785156 31.691406 C 20.953125 31.480469 21.140625 31.332031 21.351562 31.242188 C 21.5625 31.15625 21.785156 31.113281 22.015625 31.113281 C 22.242188 31.113281 22.460938 31.160156 22.664062 31.253906 C 22.867188 31.347656 23.050781 31.503906 23.210938 31.714844 C 23.371094 31.929688 23.496094 32.203125 23.585938 32.53125 C 23.679688 32.863281 23.726562 33.261719 23.734375 33.734375 C 23.726562 34.21875 23.675781 34.628906 23.582031 34.964844 Z M 23.582031 34.964844 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 30.351562 35.082031 L 27.628906 30.292969 L 26.476562 30.292969 L 26.476562 37.242188 L 27.628906 37.242188 L 27.628906 32.453125 L 30.351562 37.242188 L 31.503906 37.242188 L 31.503906 30.292969 L 30.351562 30.292969 Z M 30.351562 35.082031 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 14.136719 13.792969 L 14.136719 11.035156 C 14.136719 10.65625 14.445312 10.34375 14.828125 10.34375 C 15.207031 10.34375 15.515625 10.035156 15.515625 9.65625 C 15.515625 9.273438 15.207031 8.964844 14.828125 8.964844 C 13.6875 8.964844 12.757812 9.894531 12.757812 11.035156 L 12.757812 13.792969 C 12.757812 14.554688 12.140625 15.171875 11.378906 15.171875 C 10.996094 15.171875 10.691406 15.480469 10.691406 15.863281 C 10.691406 16.242188 10.996094 16.550781 11.378906 16.550781 C 12.140625 16.550781 12.757812 17.171875 12.757812 17.929688 L 12.757812 20.691406 C 12.757812 21.832031 13.6875 22.757812 14.828125 22.757812 C 15.207031 22.757812 15.515625 22.449219 15.515625 22.070312 C 15.515625 21.6875 15.207031 21.378906 14.828125 21.378906 C 14.445312 21.378906 14.136719 21.070312 14.136719 20.691406 L 14.136719 17.929688 C 14.136719 17.101562 13.765625 16.367188 13.183594 15.863281 C 13.765625 15.355469 14.136719 14.621094 14.136719 13.792969 Z M 14.136719 13.792969 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 20.691406 13.449219 C 20.691406 12.875 20.226562 12.414062 19.65625 12.414062 C 19.082031 12.414062 18.621094 12.875 18.621094 13.449219 C 18.621094 14.019531 19.082031 14.484375 19.65625 14.484375 C 20.226562 14.484375 20.691406 14.019531 20.691406 13.449219 Z M 20.691406 13.449219 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 19.65625 17.242188 C 19.273438 17.242188 18.964844 17.550781 18.964844 17.929688 L 18.964844 20 C 18.964844 20.378906 19.273438 20.691406 19.65625 20.691406 C 20.035156 20.691406 20.34375 20.378906 20.34375 20 L 20.34375 17.929688 C 20.34375 17.550781 20.035156 17.242188 19.65625 17.242188 Z M 19.65625 17.242188 " />
                        </g>
                    </svg>
                    <span>Baixar</span>
                </a>
            <?php endif; ?>
            <?php if (empty($atts['hide_xml'])): ?>
                <a href="<?php echo esc_url($xml_url); ?>" class="download-icon" target="_blank" rel="noopener noreferrer" title="Baixar arquivo no formato XML">
                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="40px" height="40px" viewBox="0 0 40 40" version="1.1">
                        <g id="surface1">
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 39.167969 30 L 36.667969 30 L 36.667969 24.167969 C 36.667969 23.707031 36.292969 23.332031 35.832031 23.332031 C 35.375 23.332031 35 23.707031 35 24.167969 L 35 30.832031 C 35 31.292969 35.375 31.667969 35.832031 31.667969 L 39.167969 31.667969 C 39.625 31.667969 40 31.292969 40 30.832031 C 40 30.375 39.625 30 39.167969 30 Z M 39.167969 30 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 32.761719 23.375 C 32.421875 23.261719 32.046875 23.378906 31.832031 23.667969 L 30 26.109375 L 28.167969 23.667969 C 27.953125 23.378906 27.578125 23.261719 27.238281 23.375 C 26.898438 23.488281 26.667969 23.808594 26.667969 24.167969 L 26.667969 30.832031 C 26.667969 31.292969 27.039062 31.667969 27.5 31.667969 C 27.960938 31.667969 28.332031 31.292969 28.332031 30.832031 L 28.332031 26.667969 L 29.332031 28 C 29.648438 28.421875 30.351562 28.421875 30.667969 28 L 31.667969 26.667969 L 31.667969 30.832031 C 31.667969 31.292969 32.039062 31.667969 32.5 31.667969 C 32.960938 31.667969 33.332031 31.292969 33.332031 30.832031 L 33.332031 24.167969 C 33.332031 23.808594 33.101562 23.488281 32.761719 23.375 Z M 32.761719 23.375 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 22.707031 27.5 L 24.835938 24.667969 C 25.113281 24.296875 25.035156 23.777344 24.667969 23.5 C 24.300781 23.226562 23.78125 23.296875 23.5 23.667969 L 21.667969 26.113281 L 19.832031 23.667969 C 19.554688 23.296875 19.035156 23.222656 18.667969 23.5 C 18.296875 23.777344 18.222656 24.296875 18.5 24.667969 L 20.625 27.5 L 18.5 30.332031 C 18.226562 30.703125 18.300781 31.222656 18.667969 31.5 C 18.820312 31.613281 18.992188 31.667969 19.167969 31.667969 C 19.421875 31.667969 19.671875 31.550781 19.835938 31.332031 L 21.667969 28.890625 L 23.5 31.332031 C 23.664062 31.550781 23.914062 31.667969 24.167969 31.667969 C 24.339844 31.667969 24.515625 31.613281 24.667969 31.5 C 25.035156 31.222656 25.109375 30.703125 24.832031 30.332031 Z M 22.707031 27.5 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 29.167969 21.667969 C 29.625 21.667969 30 21.292969 30 20.832031 L 30 0.832031 C 30 0.375 29.625 0 29.167969 0 L 10.832031 0 C 10.722656 0 10.613281 0.0234375 10.511719 0.0664062 C 10.464844 0.0859375 10.425781 0.121094 10.382812 0.148438 C 10.335938 0.179688 10.285156 0.203125 10.242188 0.242188 L 0.242188 10.242188 C 0.203125 10.28125 0.183594 10.332031 0.152344 10.378906 C 0.125 10.421875 0.0859375 10.460938 0.0664062 10.511719 C 0.0234375 10.613281 0 10.722656 0 10.832031 L 0 39.167969 C 0 39.625 0.375 40 0.832031 40 L 29.167969 40 C 29.625 40 30 39.625 30 39.167969 L 30 34.167969 C 30 33.707031 29.625 33.332031 29.167969 33.332031 C 28.707031 33.332031 28.332031 33.707031 28.332031 34.167969 L 28.332031 38.332031 L 1.667969 38.332031 L 1.667969 11.667969 L 10.832031 11.667969 C 11.292969 11.667969 11.667969 11.292969 11.667969 10.832031 L 11.667969 1.667969 L 28.332031 1.667969 L 28.332031 20.832031 C 28.332031 21.292969 28.707031 21.667969 29.167969 21.667969 Z M 10 10 L 2.84375 10 L 10 2.84375 Z M 10 10 " />
                        </g>
                    </svg>
                    <span>Baixar</span>
                </a>
            <?php endif; ?>
            <?php if (empty($atts['hide_xls'])): ?>
                <a href="<?php echo esc_url($xlsx_url); ?>" class="download-icon" target="_blank" rel="noopener noreferrer" title="Baixar arquivo no formato XLSX">
                    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="40px" height="40px" viewBox="0 0 40 40" version="1.1">
                        <g id="surface1">
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 34.261719 40 L 1.546875 40 C 0.902344 40 0.382812 39.480469 0.382812 38.835938 L 0.382812 20.070312 C 0.382812 19.429688 0.902344 18.910156 1.546875 18.910156 C 2.1875 18.910156 2.707031 19.429688 2.707031 20.070312 L 2.707031 37.675781 L 33.101562 37.675781 L 33.101562 35.109375 C 33.101562 34.46875 33.621094 33.949219 34.261719 33.949219 C 34.902344 33.949219 35.425781 34.46875 35.425781 35.109375 L 35.425781 38.835938 C 35.425781 39.480469 34.902344 40 34.261719 40 Z M 34.261719 40 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 38.453125 15.980469 L 35.425781 15.980469 L 35.425781 1.164062 C 35.425781 0.519531 34.902344 0 34.261719 0 L 13.71875 0 C 13.703125 0 13.6875 0 13.675781 0.00390625 C 13.660156 0.00390625 13.648438 0.00390625 13.636719 0.00390625 C 13.585938 0.0078125 13.535156 0.0117188 13.488281 0.0234375 C 13.484375 0.0234375 13.484375 0.0234375 13.484375 0.0234375 C 13.433594 0.0351562 13.386719 0.046875 13.339844 0.0625 C 13.328125 0.0664062 13.316406 0.0703125 13.304688 0.078125 C 13.257812 0.09375 13.214844 0.113281 13.171875 0.136719 C 13.171875 0.136719 13.167969 0.136719 13.167969 0.140625 C 13.121094 0.164062 13.082031 0.191406 13.039062 0.21875 C 13.027344 0.226562 13.019531 0.234375 13.007812 0.242188 C 12.96875 0.273438 12.925781 0.308594 12.890625 0.34375 L 0.71875 12.644531 C 0.691406 12.671875 0.667969 12.703125 0.640625 12.730469 C 0.625 12.753906 0.613281 12.773438 0.597656 12.792969 C 0.589844 12.804688 0.582031 12.8125 0.574219 12.824219 C 0.558594 12.847656 0.542969 12.875 0.527344 12.902344 C 0.523438 12.910156 0.519531 12.914062 0.519531 12.921875 C 0.503906 12.949219 0.492188 12.976562 0.480469 13.003906 C 0.476562 13.011719 0.472656 13.015625 0.46875 13.023438 C 0.460938 13.050781 0.449219 13.078125 0.441406 13.105469 C 0.4375 13.113281 0.433594 13.121094 0.433594 13.128906 C 0.425781 13.15625 0.417969 13.179688 0.414062 13.207031 C 0.410156 13.21875 0.40625 13.230469 0.40625 13.242188 C 0.402344 13.265625 0.398438 13.289062 0.394531 13.3125 C 0.394531 13.324219 0.390625 13.339844 0.390625 13.351562 C 0.386719 13.375 0.386719 13.398438 0.386719 13.425781 C 0.386719 13.4375 0.382812 13.449219 0.382812 13.464844 L 0.382812 13.835938 C 0.382812 14.480469 0.902344 15 1.546875 15 L 13.71875 15 C 14.359375 15 14.878906 14.480469 14.878906 13.835938 L 14.878906 2.324219 L 33.101562 2.324219 L 33.101562 15.980469 L 15.324219 15.980469 C 14.683594 15.980469 14.164062 16.5 14.164062 17.144531 L 14.164062 29.929688 C 14.164062 30.570312 14.683594 31.089844 15.324219 31.089844 L 38.453125 31.089844 C 39.097656 31.089844 39.617188 30.570312 39.617188 29.929688 L 39.617188 17.144531 C 39.617188 16.5 39.097656 15.980469 38.453125 15.980469 Z M 3.960938 12.675781 L 12.554688 3.988281 L 12.554688 12.675781 Z M 37.292969 28.765625 L 16.488281 28.765625 L 16.488281 18.304688 L 37.292969 18.304688 Z M 37.292969 28.765625 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 19.976562 24.523438 L 18.71875 26.65625 C 18.660156 26.75 18.539062 26.792969 18.402344 26.792969 C 18.03125 26.792969 17.507812 26.5 17.507812 26.128906 C 17.507812 26.050781 17.535156 25.976562 17.585938 25.890625 L 19.074219 23.59375 L 17.644531 21.304688 C 17.585938 21.210938 17.558594 21.125 17.558594 21.039062 C 17.558594 20.675781 18.050781 20.402344 18.429688 20.402344 C 18.617188 20.402344 18.746094 20.46875 18.832031 20.625 L 19.976562 22.621094 L 21.121094 20.625 C 21.207031 20.46875 21.335938 20.402344 21.523438 20.402344 C 21.902344 20.402344 22.394531 20.675781 22.394531 21.039062 C 22.394531 21.125 22.367188 21.210938 22.308594 21.304688 L 20.878906 23.59375 L 22.367188 25.890625 C 22.417969 25.976562 22.445312 26.050781 22.445312 26.128906 C 22.445312 26.5 21.921875 26.792969 21.550781 26.792969 C 21.414062 26.792969 21.285156 26.75 21.230469 26.65625 Z M 19.976562 24.523438 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 23.5625 26.742188 C 23.269531 26.742188 22.976562 26.601562 22.976562 26.328125 L 22.976562 20.859375 C 22.976562 20.574219 23.3125 20.453125 23.648438 20.453125 C 23.984375 20.453125 24.320312 20.574219 24.320312 20.859375 L 24.320312 25.570312 L 26.28125 25.570312 C 26.539062 25.570312 26.667969 25.863281 26.667969 26.15625 C 26.667969 26.449219 26.539062 26.742188 26.28125 26.742188 Z M 23.5625 26.742188 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 29.789062 25.054688 C 29.789062 24.015625 27.0625 24.195312 27.0625 22.183594 C 27.0625 20.890625 28.1875 20.402344 29.265625 20.402344 C 29.71875 20.402344 30.976562 20.488281 30.976562 21.160156 C 30.976562 21.390625 30.820312 21.863281 30.441406 21.863281 C 30.132812 21.863281 29.96875 21.539062 29.265625 21.539062 C 28.652344 21.539062 28.402344 21.785156 28.402344 22.054688 C 28.402344 22.914062 31.128906 22.75 31.128906 24.917969 C 31.128906 26.15625 30.226562 26.828125 28.980469 26.828125 C 27.851562 26.828125 26.90625 26.277344 26.90625 25.707031 C 26.90625 25.414062 27.164062 24.984375 27.492188 24.984375 C 27.894531 24.984375 28.152344 25.621094 28.953125 25.621094 C 29.351562 25.621094 29.789062 25.46875 29.789062 25.054688 Z M 29.789062 25.054688 " />
                            <path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,0%);fill-opacity:1;" d="M 33.863281 24.523438 L 32.609375 26.65625 C 32.550781 26.75 32.429688 26.792969 32.292969 26.792969 C 31.921875 26.792969 31.398438 26.5 31.398438 26.128906 C 31.398438 26.050781 31.421875 25.976562 31.472656 25.890625 L 32.960938 23.59375 L 31.535156 21.304688 C 31.472656 21.210938 31.449219 21.125 31.449219 21.039062 C 31.449219 20.675781 31.9375 20.402344 32.316406 20.402344 C 32.507812 20.402344 32.636719 20.46875 32.722656 20.625 L 33.867188 22.621094 L 35.007812 20.625 C 35.09375 20.46875 35.222656 20.402344 35.414062 20.402344 C 35.792969 20.402344 36.28125 20.675781 36.28125 21.039062 C 36.28125 21.125 36.257812 21.210938 36.195312 21.304688 L 34.769531 23.59375 L 36.257812 25.890625 C 36.308594 25.976562 36.332031 26.050781 36.332031 26.128906 C 36.332031 26.5 35.808594 26.792969 35.4375 26.792969 C 35.300781 26.792969 35.171875 26.75 35.121094 26.65625 Z M 33.863281 24.523438 " />
                        </g>
                    </svg>
                    <span>Baixar</span>
                </a>
            <?php endif; ?>
        </div>
        <div class="csv-table-ajax-wrap <?php echo esc_attr($atts['class']); ?>" id="<?php echo esc_attr($uid); ?>-wrap"
            data-url="<?php echo esc_url($atts['url']); ?>"
            data-per_page="<?php echo intval($atts['per_page']); ?>"
            data-cache_minutes="<?php echo intval($atts['cache_minutes']); ?>"
            data-delimiter="<?php echo esc_attr($atts['delimiter']); ?>"
            data-has_header="<?php echo intval($atts['has_header']); ?>">
            <div class="csv-table-controls">
                <label><input type="search" class="csv-table-search" placeholder="Digite a sua pesquisa"></label>
                <label>
                    <select class="csv-table-perpage">
                        <?php foreach (array(5, 10, 20, 50, 100) as $n) : ?>
                            <option value="<?php echo $n; ?>" <?php selected($n, intval($atts['per_page'])); ?>><?php echo $n; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="csv-table-container">
                <table class="csv-table" id="<?php echo esc_attr($uid); ?>">
                    <thead>
                        <tr>
                            <td>
                                <div class="loader-container">
                                    <div class="loader">
                                        <div class="dot"></div>
                                        <div class="dot"></div>
                                        <div class="dot"></div>
                                        <div class="dot"></div>
                                        <div class="dot"></div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="csv-table-pagination" data-target="<?php echo esc_attr($uid); ?>-pagination"></div>
        </div>
<?php
        return ob_get_clean();
    }
}

new CSV_Table_Shortcode();
