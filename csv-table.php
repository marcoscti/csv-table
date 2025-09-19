<?php

/**
 * Plugin Name: CSV Table
 * Description: Shortcode para ler um CSV remoto e renderizar uma tabela paginada do lado do servidor via AJAX. Otimizado para transmitir arquivos CSV grandes. Uso: [dinamic_table url="https://example.com/file.csv" per_page="10" cache_minutes="60" delimiter=";"]
 * Version: 1.0.0
 * Author: Marcos Cordeiro
 * Author URI: https://marcoscti.dev/
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: marcoscti
 */

if (! defined('ABSPATH')) {
    exit;
}

class CSV_Table_Shortcode
{
    private $cache_dir;

    public function __construct()
    {
        add_shortcode('csv_table', array($this, 'shortcode_handler'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_csv_table_fetch', array($this, 'ajax_fetch'));
        add_action('wp_ajax_nopriv_csv_table_fetch', array($this, 'ajax_fetch'));

        $upload_dir = wp_upload_dir();
        $this->cache_dir = trailingslashit($upload_dir['basedir']) . 'csv_table_cache/';
        if (! file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
    }

    public function enqueue_assets()
    {
        wp_enqueue_style(
            'datatables-css',
            'https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css',
            array(),
            '1.13.6'
        );
        wp_enqueue_script(
            'datatables-js',
            'https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js',
            array('jquery'),
            '1.13.6',
            true
        );
        wp_enqueue_script('csv-table-ajax-js', plugins_url('assets/js/csv-ajax.js', __FILE__), array(), false, true);
        wp_enqueue_style('csv-table-ajax-css', plugins_url('assets/css/csv-style.css', __FILE__));

        wp_localize_script('csv-table-ajax-js', 'CSVTableAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('csv_table_ajax_nonce'),
        ));
    }

    private function get_cache_paths($url)
    {
        $hash = md5($url);
        return array(
            'file' => $this->cache_dir . $hash . '.json',
            'meta' => $this->cache_dir . $hash . '.meta.json',
        );
    }

    private function ensure_local_copy($url, $cache_minutes = 60)
    {
        $paths = $this->get_cache_paths($url);
        $file = $paths['file'];
        $meta_file = $paths['meta'];

        // If cached and fresh, return path
        if (file_exists($file) && file_exists($meta_file)) {
            $meta = json_decode(file_get_contents($meta_file), true);

            if (isset($meta['saved_at']) && (time() - intval($meta['saved_at'])) < (intval($cache_minutes) * MINUTE_IN_SECONDS)) {
                return $file;
            }
        }

        // Download the CSV file
        $tmpfile = $file . '.tmp';
        if (file_exists($tmpfile)) @unlink($tmpfile);

        $args = array(
            'timeout'   => 60,
            'stream'    => true,
            'filename'  => $tmpfile,
            'sslverify' => false,
        );
        $resp = wp_remote_get(esc_url_raw($url), $args);

        if (is_wp_error($resp)) {
            if (file_exists($tmpfile)) @unlink($tmpfile);
            return new WP_Error('download_failed', $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            if (file_exists($tmpfile)) @unlink($tmpfile);
            return new WP_Error('bad_status', 'HTTP status ' . $code);
        }

        // Convert CSV to JSON format
        if (file_exists($tmpfile)) {
            try {
                // Read CSV and convert to JSON
                $csv_data = array();
                $header = array();
                $first_line = true;

                $csv_file = new SplFileObject($tmpfile, 'r');
                $csv_file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
                $csv_file->setCsvControl(';'); // Assuming semicolon delimiter for CSV

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

                // Create JSON structure
                $json_data = array(
                    'header' => $header,
                    'data' => $csv_data
                );

                // Save as JSON
                if (file_exists($file)) @unlink($file);
                file_put_contents($file, wp_json_encode($json_data));

                // Remove temp file
                @unlink($tmpfile);

                // Save meta
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
    // Segurança
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

    if (empty($url)) {
       wp_send_json_error('Missing URL parameter', 400);
        wp_die();
    }

    $local = $this->ensure_local_copy($url, $cache_minutes);
    if (is_wp_error($local)) {
        wp_send_json_error('Failed to load CSV: ' . $local->get_error_message(), 500);
        wp_die();
    }

    // Ler JSON do arquivo
    $json_data = $this->get_json_data($local);
    $header = $json_data['header'];
    $all_data = $json_data['data'];

   
    // SEMPRE paginar - remover a lógica do get_all
    $filtered_data = array();
    if ($search === '') {
        $filtered_data = $all_data;
    } else {
        $search_lower = trim(strtolower($search));
        foreach ($all_data as $row) {
            $found = false;
            foreach ($row as $cell) {
                $cell_value = strval($cell);
                if (strpos(strtolower($cell_value), $search_lower) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $filtered_data[] = $row;
            }
        }
    }

    $total_rows = count($filtered_data);
    $total_pages = max(1, ceil($total_rows / $per_page));

    if ($page > $total_pages) {
        $page = $total_pages;
    }

    $start = ($page - 1) * $per_page;
    $page_data = array_slice($filtered_data, $start, $per_page);

   
    // Cabeçalho numérico se não existir
    if (empty($header) && $has_header && !empty($page_data)) {
        $col_count = count($page_data[0]);
        $header = array();
        for ($i = 0; $i < $col_count; $i++) {
            $header[] = 'Coluna ' . ($i + 1);
        }
    }

    // Normalizar linhas
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
    public function shortcode_handler($atts)
    {
        $atts = shortcode_atts(array(
            'url' => '',
            'per_page' => 10,
            'cache_minutes' => 60,
            'delimiter' => ',',
            'has_header' => 1,
            'class' => '',
        ), $atts, 'dinamic_table');

        if (empty($atts['url'])) {
            return '<div class="csv-table-error">Dynamic Table: falta o atributo <code>url</code>.</div>';
        }

        $uid = 'dinamic_table_' . uniqid();
        ob_start();
?>
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
