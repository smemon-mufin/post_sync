<?php

/*
 * Plugin name: Salman_interview
 * Description: Post Sync + On site Translation
 * Version: 1.1
 * Author: Salman Memon
 */

if (!defined('ABSPATH')) exit;

class WP_Sync_Translate {
    const VERSION = '0.1';
    const DB_VERSION = '1';
    const OPTION_KEY = 'wsts_options';
    const LOG_TABLE = 'wsts_sync_logs';
    const MAP_TABLE = 'wsts_id_map';



    private static $instance;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // ensure tables exist (in case activation hook didn't run)
         add_action('init', array($this, 'ensure_tables_exist'));
        add_action('plugins_loaded', array($this, 'ensure_tables_exist'));

        register_activation_hook(__FILE__, array($this,'activate'));
        register_deactivation_hook(__FILE__, array($this,'deactivate'));

        add_action('admin_menu', array($this,'admin_menu'));
        add_action('rest_api_init', array($this,'register_routes'));

        // Host hooks
        add_action('transition_post_status', array($this,'post_status_change'), 10, 3);
    }

    public function activate() {
        $this->create_tables();
        // default options
        $opts = get_option(self::OPTION_KEY, array());
        if (!isset($opts['mode'])) $opts['mode'] = 'host';
        update_option(self::OPTION_KEY, $opts);
    }

    public function deactivate() {
        // nothing destructive
    }

    public function ensure_tables_exist() {
        global $wpdb;
        $logs = $wpdb->prefix . self::LOG_TABLE;
        $map = $wpdb->prefix . self::MAP_TABLE;

        // If either table is missing, attempt to create both
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs));
        $exists2 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $map));
        if ($exists !== $logs || $exists2 !== $map) {
            $this->create_tables();
        }
    }

 private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $logs = $wpdb->prefix . self::LOG_TABLE;
        $map = $wpdb->prefix . self::MAP_TABLE;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $sql = "CREATE TABLE IF NOT EXISTS $logs (
            id BIGINT NOT NULL AUTO_INCREMENT,
            site_role VARCHAR(10) NOT NULL,
            action VARCHAR(20) NOT NULL,
            host_post_id BIGINT NULL,
            target_post_id BIGINT NULL,
            target_url TEXT,
            status VARCHAR(20),
            message TEXT,
            time_taken FLOAT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        dbDelta($sql);

        $sql2 = "CREATE TABLE IF NOT EXISTS $map (
            id BIGINT NOT NULL AUTO_INCREMENT,
            host_post_id BIGINT NOT NULL,
            target_url VARCHAR(191) NOT NULL,
            target_post_id BIGINT NOT NULL,
            last_synced DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY host_target (host_post_id, target_url)
        ) $charset_collate;";
        dbDelta($sql2);
    }



    public function admin_menu() {
        add_menu_page('Post Sync', 'Post Sync', 'manage_options', 'post-sync', array($this,'admin_page'));
    }

    private function get_options() {
        return get_option(self::OPTION_KEY, array());
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) return;
        $opts = $this->get_options();
        // simple admin UI (POST handling omitted for brevity)
        ?>
        <div class="wrap">
            <h1>WP Sync & Translate</h1>
            <form method="post">
            <?php wp_nonce_field('wsts_save_options','wsts_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th>Mode</th>
                    <td>
                        <label><input type="radio" name="mode" value="host" <?php checked('host', $opts['mode'] ?? 'host'); ?>> Host</label>
                        <label><input type="radio" name="mode" value="target" <?php checked('target', $opts['mode'] ?? 'host'); ?>> Target</label>
                    </td>
                </tr>
            </table>

            <?php if (($opts['mode'] ?? 'host') === 'host') : ?>
                <h2>Targets</h2>
                <p>Add Targets (target URL + key auto-generated).</p>
                <table class="widefat">
                <thead><tr><th>Target URL</th><th>Key</th><th>Actions</th></tr></thead>
                <tbody id="targets-body">
                <?php
                $targets = $opts['targets'] ?? array();
                foreach ($targets as $i => $t) :
                ?>
                    <tr>
                        <td><input type="text" name="targets[<?php echo $i;?>][url]" value="<?php echo esc_attr($t['url']);?>" style="width:100%"></td>
                        <td><input type="text" readonly name="targets[<?php echo $i;?>][key]" value="<?php echo esc_attr($t['key']);?>"></td>
                        <td><a class="button" href="#" onclick="this.closest('tr').remove();return false">Remove</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                </table>
                <p><a class="button" href="#" id="add-target">Add New row</a></p>
                <script>
                (function(){
                    let body=document.getElementById('targets-body');
                    document.getElementById('add-target').addEventListener('click', function(e){
                        e.preventDefault();
                        let idx = body.children.length;
                        let tr = document.createElement('tr');
                        tr.innerHTML = '<td><input type="text" name="targets['+idx+'][url]" style="width:100%"></td><td><input readonly type="text" name="targets['+idx+'][key]" value="'+(Math.random().toString(36).slice(2,18))+'"></td><td><a class="button" href="#" onclick="this.closest(\\'tr\\').remove();return false">Remove</a></td>';
                        body.appendChild(tr);
                    });
                })();
                </script>
            <?php else: ?>
                <h2>Target Settings</h2>
                <table class="form-table">
                    <tr><th>Key (paste)</th><td><input type="text" name="target_key" value="<?php echo esc_attr($opts['target_key'] ?? ''); ?>" style="width:400px"></td></tr>
                    <tr><th>Translation language</th><td>
                        <select name="translation_lang">
                            <option value="fr" <?php selected('fr', $opts['translation_lang'] ?? 'fr');?>>French</option>
                            <option value="es" <?php selected('es', $opts['translation_lang'] ?? 'fr');?>>Spanish</option>
                            <option value="hi" <?php selected('hi', $opts['translation_lang'] ?? 'fr');?>>Hindi</option>
                        </select>
                    </td></tr>
                    <tr><th>ChatGPT Key</th><td><input type="text" name="chatgpt_key" value="<?php echo esc_attr($opts['chatgpt_key'] ?? ''); ?>" style="width:400px"></td></tr>
                </table>
            <?php endif; ?>

            <p class="submit"><button class="button-primary" type="submit">Save</button></p>
            </form>
        </div>
        <?php

        // Handle POST save
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!check_admin_referer('wsts_save_options','wsts_nonce')) return;
            $mode = sanitize_text_field($_POST['mode'] ?? 'host');
            $opts['mode'] = $mode;
            if ($mode === 'host') {
                $incoming = $_POST['targets'] ?? array();
                $clean = array();
                foreach ($incoming as $row) {
                    $url = esc_url_raw($row['url']);
                    $key = sanitize_text_field($row['key'] ?? '');
                    if (!$key) $key = wp_generate_password(20,false,false);
                    if ($url) $clean[] = array('url'=>$url,'key'=>$key);
                }
                $opts['targets'] = $clean;
                // Save
                update_option(self::OPTION_KEY, $opts);
                echo '<div class="updated"><p>Saved.</p></div>';
                // avoid resubmit
                echo '<script>setTimeout(()=>location.reload(),600);</script>';
            } else {
                $opts['target_key'] = sanitize_text_field($_POST['target_key'] ?? '');
                $opts['translation_lang'] = sanitize_text_field($_POST['translation_lang'] ?? 'fr');
                $opts['chatgpt_key'] = sanitize_text_field($_POST['chatgpt_key'] ?? '');
                update_option(self::OPTION_KEY, $opts);
                echo '<div class="updated"><p>Saved.</p></div>';
                echo '<script>setTimeout(()=>location.reload(),600);</script>';
            }
        }
    }

    public function register_routes() {
    
    error_log('[WSTS] Registering REST route /wsts/v1/receive'); // Debug line
        register_rest_route('wsts/v1', '/receive', array(
            'methods' => 'POST',
            'callback' => array($this,'receive_post'),
            'permission_callback' => '__return_true',
        ));

    }

    


    // Host: when post transitions to publish or is updated, push to targets
    public function post_status_change($new_status, $old_status, $post) {
        if ($post->post_type !== 'post') return;
        if ($new_status === 'publish' || ($old_status === 'publish' && $new_status !== 'trash')) {
            // push
            $this->push_to_targets($post->ID, $new_status === 'publish' ? 'publish' : 'update');
        }
    }

    private function push_to_targets($post_id, $action='publish') {
        $opts = $this->get_options();
        if (($opts['mode'] ?? 'host') !== 'host') return;
        $targets = $opts['targets'] ?? array();
        $post = get_post($post_id);
        if (!$post) return;

        foreach ($targets as $t) {
            $start = microtime(true);
            $payload = $this->build_payload($post);
            $json = wp_json_encode($payload);
            $key = $t['key'];
            $domain = parse_url(rtrim($t['url'],'/'), PHP_URL_HOST) ?: parse_url(home_url(), PHP_URL_HOST);
            $url = rtrim($t['url'],'/') . '/wp-json/wsts/v1/receive';
            $headers = $this->sign_headers($json, $key, $domain);
            $response = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => $json,
                'timeout' => 30,
            ));
            $time_taken = microtime(true)-$start;
            if (is_wp_error($response)) {
                $this->write_log('host',$action,$post_id,null,$url,'failed',$response->get_error_message(),$time_taken);
            } else {
                $code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $status = $code>=200 && $code<300 ? 'ok' : 'failed';
                $this->write_log('host',$action,$post_id,null,$url,$status,"HTTP $code: $body",$time_taken);
                if ($status==='ok') {
                    // record mapping if target returned mapped ID
                    $data = json_decode($body,true);
                    if (!empty($data['target_post_id'])) {
                        $this->save_mapping($post_id, $t['url'], intval($data['target_post_id']));
                    }
                }
            }
        }
    }

    private function build_payload($post) {
        // gather fields
        $tags = wp_get_post_tags($post->ID, array('fields'=>'names'));
        $cats = wp_get_post_categories($post->ID, array('fields'=>'names'));
        $featured = get_post_thumbnail_id($post->ID);
        $featured_url = $featured ? wp_get_attachment_url($featured) : '';
        return array(
            'action' => 'upsert',
            'host_site' => home_url(),
            'host_post_id' => $post->ID,
            'post' => array(
                'title' => $post->post_title,
                'content' => $post->post_content,
                'excerpt' => $post->post_excerpt,
                'categories' => $cats,
                'tags' => $tags,
                'featured_image' => $featured_url,
            ),
            'timestamp' => time(),
        );
    }

    private function sign_headers($body, $key, $domain) {
        // HMAC signature: sha256 of body + domain
        $data = $body . '|' . $domain;
        $sig = hash_hmac('sha256', $data, $key);
        return array(
            'Content-Type' => 'application/json',
            'X-WSTS-Key' => $key,
            'X-WSTS-Signature' => $sig,
            'X-WSTS-Domain' => $domain,
        );
    }

    // Target: receive endpoint
public function receive_post($request) {
    $opts = $this->get_options();
    if (($opts['mode'] ?? 'host') !== 'target') {
        return new WP_REST_Response(array('error'=>'not a target'), 400);
    }

    $body = $request->get_body();
    $key = $request->get_header('x-wsts-key');
    $sig = $request->get_header('x-wsts-signature');
    $domain = $request->get_header('x-wsts-domain');

    //  Check API key match
    if (empty($opts['target_key']) || $key !== $opts['target_key']) {
        $this->write_log('target','receive',null,null,$domain,'failed','invalid key',0);
        return new WP_REST_Response(array('error'=>'invalid key'), 401);
    }


    //  Verify HMAC signature
    $expected = hash_hmac('sha256', $body . '|' . $domain, $key);
    if (!hash_equals($expected, $sig)) {
        $this->write_log('target','receive',null,null,$domain,'failed','signature mismatch',0);
        return new WP_REST_Response(array('error'=>'signature mismatch'), 401);
    }

    $data = json_decode($body, true);
    if (!$data || empty($data['post'])) {
        return new WP_REST_Response(array('error'=>'bad payload'), 400);
    }

    $start = microtime(true);
    $translated = $this->translate_post_payload($data['post']);

    $host_post_id = intval($data['host_post_id'] ?? 0);
    $postarr = array(
    'post_title'   => wp_strip_all_tags($translated['title']), // allow HTML title
    'post_content' => wp_strip_all_tags($translated['content']),
    'post_excerpt' => $translated['excerpt'], // allow HTML in excerpt
    'post_status'  => 'publish',
    'post_type'    => 'post',
);
    $existing_target_id = $this->get_mapping($host_post_id, $data['host_site'] ?? '');
    if ($existing_target_id) {
        $postarr['ID'] = $existing_target_id;
        $new_id = wp_update_post($postarr, true);
    } else {
        $new_id = wp_insert_post($postarr, true);
    }

    if (!is_wp_error($new_id)) {
        if (!empty($translated['categories'])) {
            $cat_ids = array();
            foreach ($translated['categories'] as $cname) {
                $term = get_term_by('name', $cname, 'category');
                if (!$term) $term = wp_insert_term($cname, 'category');
                if (!is_wp_error($term)) $cat_ids[] = is_array($term) ? $term['term_id'] : $term->term_id;
            }
            wp_set_post_categories($new_id, $cat_ids);
        }

        if (!empty($translated['tags'])) {
            wp_set_post_tags($new_id, $translated['tags']);
        }

        if (!empty($translated['featured_image'])) {
            $this->import_featured_image_from_url($translated['featured_image'], $new_id);
        }
    }

    $time_taken = microtime(true) - $start;
    $this->write_log('target', 'upsert', $host_post_id, $new_id, home_url(), 'ok', 'synced', $time_taken);

    return new WP_REST_Response(array('status' => 'ok', 'target_post_id' => $new_id), 200);
}


    private function translate_post_payload($post) {
        $opts = $this->get_options();
        $lang = $opts['translation_lang'] ?? 'fr';
        $key = $opts['chatgpt_key'] ?? '';
        // simple map
        $lang_map = array('fr'=>'French','es'=>'Spanish','hi'=>'Hindi');
        $target_lang = $lang_map[$lang] ?? 'French';

        // chunk content: split by paragraphs roughly into 2000 char chunks
        $content = $post['content'] ?? '';
        $chunks = $this->chunk_text_preserve_html($content, 2000);
        $translated_chunks = array();
        foreach ($chunks as $chunk) {
            $translated_chunks[] = $this->call_chatgpt_translate($chunk, $target_lang, $key);
        }
        $merged_content = implode("\n", $translated_chunks);

        // title and excerpt (short) - translate as whole
        $title = $this->call_chatgpt_translate($post['title'] ?? '', $target_lang, $key);
        $excerpt = $this->call_chatgpt_translate($post['excerpt'] ?? '', $target_lang, $key);

        return array(
            'title' => $title,
            'content' => $merged_content,
            'excerpt' => $excerpt,
            'categories' => $post['categories'] ?? array(),
            'tags' => $post['tags'] ?? array(),
            'featured_image' => $post['featured_image'] ?? '',
        );
    }

    private function chunk_text_preserve_html($html, $approx=2000) {
        // naive approach: split by </p> or by <br> or by block tags, fallback to char chunks
        $blocks = preg_split('#(</p>|<br\s*/?>)#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $chunks = array();
        $current = '';
        foreach ($blocks as $part) {
            if ($part === '' ) continue;
            $current .= $part;
            if (mb_strlen(strip_tags($current)) >= $approx) {
                $chunks[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') $chunks[] = $current;
        // ensure chunk sizes between ~1500-2500 by merging small ones
        return $chunks;
    }

    private function call_chatgpt_translate($text, $target_lang, $api_key) {
        // Basic use of ChatGPT (OpenAI) - example for gpt-4o-mini; user supplies key.
        if (empty($api_key) || trim($text)==='') return $text;

        $prompt = "Translate the following HTML-preserving content to $target_lang. Keep HTML structure intact; only translate visible text. Return only the translated HTML.\n\n$text";

        $payload = array(
            'model' => 'gpt-4o-mini',
            'messages' => array(
                array('role'=>'system','content'=>'You translate HTML content preserving structure; do not add extra commentary.'),
                array('role'=>'user','content'=>$prompt),
            ),
            'temperature' => 0.0,
        );

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, wp_json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) return $text;
        $data = json_decode($res, true);
        if (!$data || empty($data['choices'][0]['message']['content'])) return $text;
        return $this->strip_code_fences($data['choices'][0]['message']['content']);
    }

    private function import_featured_image_from_url($url, $post_id) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $tmp = download_url($url);
        if (is_wp_error($tmp)) return false;
        $file_array = array();
        preg_match('/\\/([\\w\\-\\.]+)$/', $url, $matches);
        $file_array['name'] = $matches[1] ?? basename($url);
        $file_array['tmp_name'] = $tmp;
        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) { @unlink($tmp); return false; }
        set_post_thumbnail($post_id, $id);
        return true;
    }

    private function save_mapping($host_post_id, $target_url, $target_post_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::MAP_TABLE;
        $exists = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE host_post_id=%d AND target_url=%s", $host_post_id, $target_url));
        if ($exists) {
            $wpdb->update($table, array('target_post_id'=>$target_post_id,'last_synced'=>current_time('mysql')), array('id'=>$exists->id));
        } else {
            $wpdb->insert($table, array('host_post_id'=>$host_post_id,'target_url'=>$target_url,'target_post_id'=>$target_post_id,'last_synced'=>current_time('mysql')));
        }
    }

    private function get_mapping($host_post_id, $target_url) {
        global $wpdb;
        $table = $wpdb->prefix . self::MAP_TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE host_post_id=%d AND target_url=%s", $host_post_id, $target_url));
        return $row ? intval($row->target_post_id) : 0;
    }

    private function write_log($role,$action,$host_post_id,$target_post_id,$target_url,$status,$message,$time_taken) {
        global $wpdb;
        $table = $wpdb->prefix . self::LOG_TABLE;
        // ensure table exists before inserting to avoid DB errors
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            $this->create_tables();
        }
        $wpdb->insert($table, array(
            'site_role'=>$role,
            'action'=>$action,
            'host_post_id'=>$host_post_id,
            'target_post_id'=>$target_post_id,
            'target_url'=>$target_url,
            'status'=>$status,
            'message'=>$message,
            'time_taken'=>$time_taken,
            'created_at'=>current_time('mysql'),
        ));
    }
}

WP_Sync_Translate::instance();

?>
