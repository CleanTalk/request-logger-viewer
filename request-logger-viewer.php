<?php
/**
 * Plugin Name: Request Logger & Viewer
 * Description: Logs and displays request statistics with performance metrics
 * Version: 1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

class RequestLoggerViewer {
    private $log_file;
    private $db_log_file;
    private $start_time;
    private $db_queries = array();

    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/request-logs.log';
        $this->db_log_file = WP_CONTENT_DIR . '/db-queries.log';
        $this->start_time = microtime(true);
        
        // Logging hooks
        add_action('init', array($this, 'log_request'));
        add_action('shutdown', array($this, 'update_last_log_entry'));
        
        // Admin interface hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Add AJAX handler for clearing logs
        add_action('wp_ajax_clear_request_logs', array($this, 'handle_clear_logs'));
        
        if (!defined('SAVEQUERIES')) {
            define('SAVEQUERIES', true);
        }

        add_filter('comment_flood_filter', '__return_false');
    }

    public function enqueue_scripts($hook) {
        if ('tools_page_request-logger-viewer' !== $hook) {
            return;
        }
        wp_enqueue_style('request-logger-viewer', plugins_url('css/style.css', __FILE__));
        wp_enqueue_script('request-logger-viewer', plugins_url('js/script.js', __FILE__), array('jquery'), '1.0', true);
        
        // Add nonce to JavaScript
        wp_localize_script('request-logger-viewer', 'requestLoggerViewer', array(
            'nonce' => wp_create_nonce('clear_request_logs'),
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'Request Logger',
            'Request Logger',
            'manage_options',
            'request-logger-viewer',
            array($this, 'display_logs')
        );
    }

    public function update_last_log_entry() {
        global $wpdb;
        
        $total_queries = count($wpdb->queries);
        
        // Read the last line of the log file
        $lines = file($this->log_file);
        if (empty($lines)) {
            return;
        }
        
        $last_line = array_pop($lines);

        $updated_entry = preg_replace(
            '/\[queries:\s*(\d+)\]/',
            sprintf('[queries:%3d]', $total_queries),
            $last_line
        );

        // Write back all lines except the last one
        file_put_contents($this->log_file, implode('', $lines));
        // Append the updated last line
        file_put_contents($this->log_file, $updated_entry, FILE_APPEND);
    }

    public function log_request() {
        $request_uri = $_SERVER['REQUEST_URI'];
        $request_method = $_SERVER['REQUEST_METHOD'];
        $content_length = isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : 0;
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $timestamp = current_time('mysql');
        $time_total = microtime(true) - $this->start_time;
        
        // Initial log entry with placeholder values for queries and time
        $log_entry = sprintf(
            "[%s] [bytes:%6d] [time:%8.4f][queries:%3d] [method:%-4s] [uri:%s] [ip:%s] [user-agent:%s]\n",
            $timestamp,
            $content_length,
            $time_total,
            0,  // placeholder for query count
            $request_method,
            $request_uri,
            $ip_address,
            $user_agent
        );

        if (!file_exists($this->log_file)) {
            touch($this->log_file);
            chmod($this->log_file, 0644);
        }

        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }

    private function parse_log_line($line) {
        $pattern = '/\[(.*?)\] \[bytes:\s*(\d+)\] \[time:\s*([\d.]+)\]\[queries:\s*(\d+)\] \[method:\s*(\w+)\s*\] \[uri:(.*?)\] \[ip:(.*?)\] \[user-agent:(.*?)\]/';
        
        if (preg_match($pattern, $line, $matches)) {
            return array(
                'timestamp' => strtotime($matches[1]),
                'timestamp_formatted' => $matches[1],
                'bytes' => (int)$matches[2],
                'time' => (float)$matches[3],
                'queries' => (int)$matches[4],
                'method' => trim($matches[5]),
                'uri' => trim($matches[6]),
                'ip' => trim($matches[7]),
                'user_agent' => trim($matches[8])
            );
        }
        return null;
    }

    private function calculate_statistics($logs) {
        $current_time = time();
        $ten_minutes_ago = $current_time - (10 * 60);
        
        $stats = array(
            'total_requests' => 0,
            'total_post_requests' => 0,
            'requests_10min' => 0,
            'post_requests_10min' => 0,
            'post_size_10min' => array(),
            'get_time_10min' => array(),
            'post_time_10min' => array(),
            'get_queries_10min' => array(),
            'post_queries_10min' => array(),
            'earliest_timestamp' => PHP_INT_MAX,
            'latest_timestamp' => 0,
        );

        foreach ($logs as $log) {
            $stats['earliest_timestamp'] = min($stats['earliest_timestamp'], $log['timestamp']);
            $stats['latest_timestamp'] = max($stats['latest_timestamp'], $log['timestamp']);
            
            $stats['total_requests']++;
            
            if ($log['method'] === 'POST') {
                $stats['total_post_requests']++;
            }

            if ($log['timestamp'] >= $ten_minutes_ago) {
                $stats['requests_10min']++;

                if ($log['method'] === 'POST') {
                    $stats['post_requests_10min']++;
                    $stats['post_size_10min'][] = $log['bytes'];
                    $stats['post_time_10min'][] = $log['time'];
                    $stats['post_queries_10min'][] = $log['queries'];
                } else if ($log['method'] === 'GET') {
                    $stats['get_time_10min'][] = $log['time'];
                    $stats['get_queries_10min'][] = $log['queries'];
                }
            }
        }

        $stats['avg_requests_10min'] = $stats['requests_10min'] / 10;
        $stats['avg_post_requests_10min'] = $stats['post_requests_10min'] / 10;
        $stats['avg_post_size_10min'] = !empty($stats['post_size_10min']) ? 
            array_sum($stats['post_size_10min']) / count($stats['post_size_10min']) : 0;
        $stats['avg_get_time_10min'] = !empty($stats['get_time_10min']) ? 
            array_sum($stats['get_time_10min']) / count($stats['get_time_10min']) : 0;
        $stats['avg_post_time_10min'] = !empty($stats['post_time_10min']) ? 
            array_sum($stats['post_time_10min']) / count($stats['post_time_10min']) : 0;
        $stats['avg_get_queries_10min'] = !empty($stats['get_queries_10min']) ? 
            array_sum($stats['get_queries_10min']) / count($stats['get_queries_10min']) : 0;
        $stats['avg_post_queries_10min'] = !empty($stats['post_queries_10min']) ? 
            array_sum($stats['post_queries_10min']) / count($stats['post_queries_10min']) : 0;

        $time_diff = $stats['latest_timestamp'] - $stats['earliest_timestamp'];
        $stats['time_range'] = array(
            'seconds' => $time_diff,
            'minutes' => floor($time_diff / 60),
            'hours' => floor($time_diff / 3600),
            'days' => floor($time_diff / 86400),
        );

        return $stats;
    }

    public function handle_clear_logs() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'clear_request_logs')) {
            wp_send_json_error('Invalid security token');
            return;
        }

        // Verify user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        // Clear the log file
        if (file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            wp_send_json_success('Logs cleared successfully');
        } else {
            wp_send_json_error('Log file not found');
        }
    }

    public function display_logs() {
        if (!file_exists($this->log_file)) {
            echo '<div class="wrap"><h1>Request Logger</h1><p>No logs found.</p></div>';
            return;
        }

        $logs = array_filter(array_map(
            array($this, 'parse_log_line'),
            file($this->log_file)
        ));

        $stats = $this->calculate_statistics($logs);
        ?>
        <div class="wrap">
            <h1>Request Logger</h1>
            
            <div class="stats-grid">
                <div class="stat-box time-range">
                    <h3>Log Time Range</h3>
                    <div class="stat-value">
                        <?php 
                        $start_date = date('Y-m-d H:i:s', $stats['earliest_timestamp']);
                        $end_date = date('Y-m-d H:i:s', $stats['latest_timestamp']);
                        echo esc_html($start_date);
                        echo '<br>to<br>';
                        echo esc_html($end_date);
                        ?>
                    </div>
                    <div class="stat-subtext">
                        <?php
                        $range = $stats['time_range'];
                        if ($range['days'] > 0) {
                            echo esc_html($range['days'] . ' days, ');
                            echo esc_html(($range['hours'] % 24) . ' hours');
                        } elseif ($range['hours'] > 0) {
                            echo esc_html($range['hours'] . ' hours, ');
                            echo esc_html(($range['minutes'] % 60) . ' minutes');
                        } else {
                            echo esc_html($range['minutes'] . ' minutes, ');
                            echo esc_html(($range['seconds'] % 60) . ' seconds');
                        }
                        ?>
                    </div>
                </div>
                
                <div class="stat-box">
                    <h3>Total Requests</h3>
                    <div class="stat-value"><?php echo number_format($stats['total_requests']); ?></div>
                    <div class="stat-subtext">Avg: <?php echo number_format($stats['avg_requests_10min'], 1); ?>/min (10m)</div>
                </div>
                
                <div class="stat-box">
                    <h3>Total POST Requests</h3>
                    <div class="stat-value"><?php echo number_format($stats['total_post_requests']); ?></div>
                    <div class="stat-subtext">Avg: <?php echo number_format($stats['avg_post_requests_10min'], 1); ?>/min (10m)</div>
                </div>
                
                <div class="stat-box">
                    <h3>Average POST Size (10m)</h3>
                    <div class="stat-value"><?php echo number_format($stats['avg_post_size_10min']); ?> bytes</div>
                </div>
                
                <div class="stat-box">
                    <h3>Average Response Time (10m)</h3>
                    <div class="stat-value">
                        GET: <?php echo number_format($stats['avg_get_time_10min'], 4); ?>s<br>
                        POST: <?php echo number_format($stats['avg_post_time_10min'], 4); ?>s
                    </div>
                </div>
                
                <div class="stat-box">
                    <h3>Average Queries (10m)</h3>
                    <div class="stat-value">
                        GET: <?php echo number_format($stats['avg_get_queries_10min'], 1); ?><br>
                        POST: <?php echo number_format($stats['avg_post_queries_10min'], 1); ?>
                    </div>
                </div>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <button class="button" id="clear-logs" data-nonce="<?php echo wp_create_nonce('clear_request_logs'); ?>">Clear Logs</button>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Method</th>
                        <th>URI</th>
                        <th>Bytes</th>
                        <th>Time (s)</th>
                        <th>Queries</th>
                        <th>IP</th>
                        <th>User Agent</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['timestamp_formatted']); ?></td>
                        <td><?php echo esc_html($log['method']); ?></td>
                        <td><?php echo esc_html($log['uri']); ?></td>
                        <td><?php echo number_format($log['bytes']); ?></td>
                        <td><?php echo number_format($log['time'], 4); ?></td>
                        <td><?php echo number_format($log['queries']); ?></td>
                        <td><?php echo esc_html($log['ip']); ?></td>
                        <td class="user-agent"><?php echo esc_html($log['user_agent']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

new RequestLoggerViewer(); 