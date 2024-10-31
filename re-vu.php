<?php
/*
Plugin Name: re-vu Comment System
Plugin URI: http://re-vu.com/
Description: The re-vu comment system replaces your WordPress comment system with your comments hosted and powered by The re-vu. Head over to the Comments admin page to set up your The re-vu Comment System.
Author: re-vu.com <team@re-vu.com>
Version: 2.66
Author URI: http://re-vu.com/
*/

/*.
    require_module 'standard';
    require_module 'pcre';
    require_module 'mysql';
.*/


/**
 * @param string $file
 * @return string
 */
function revu_plugin_basename($file) {
    $file = dirname($file);

    // From WP2.5 wp-includes/plugin.php:plugin_basename()
    $file = str_replace('\\','/',$file); // sanitize for Win32 installs
    $file = preg_replace('|/+|','/', $file); // remove any duplicate slash
    $file = preg_replace('|^.*/' . PLUGINDIR . '/|','',$file); // get relative path from plugins dir

    if ( strstr($file, '/') === false ) {
        return $file;
    }

    $pieces = explode('/', $file);
    return !empty($pieces[count($pieces)-1]) ? $pieces[count($pieces)-1] : $pieces[count($pieces)-2];
}

if ( !defined('WP_CONTENT_URL') ) {
    define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content');
}
if ( !defined('PLUGINDIR') ) {
    define('PLUGINDIR', 'wp-content/plugins'); // Relative to ABSPATH.  For back compat.
}

define('DSQ_PLUGIN_URL', WP_CONTENT_URL . '/plugins/' . revu_plugin_basename(__FILE__));



$dsq_response = '';

$DSQ_QUERY_COMMENTS = false;


$DSQ_QUERY_POST_IDS = array();



/**
 * @return bool
 */
function revu_can_replace() {
    global $id, $post;

   
   

    if ( is_feed() )                       { return false; }
    if ( 'draft' == $post->post_status )   { return false; }
    
    else if ( 'all' == $replace )          { return true; }

    if ( !isset($post->comment_count) ) {
        $num_comments = 0;
    } else {
        if ( 'empty' == $replace ) {
            // Only get count of comments, not including pings.

            // If there are comments, make sure there are comments (that are not track/pingbacks)
            if ( $post->comment_count > 0 ) {
                // Yuck, this causes a DB query for each post.  This can be
                // replaced with a lighter query, but this is still not optimal.
                $comments = get_approved_comments($post->ID);
                foreach ( $comments as $comment ) {
                    if ( $comment->comment_type != 'trackback' && $comment->comment_type != 'pingback' ) {
                        $num_comments++;
                    }
                }
            } else {
                $num_comments = 0;
            }
        }
        else {
            $num_comments = $post->comment_count;
        }
    }

    return ( ('empty' == $replace && 0 == $num_comments)
        || ('closed' == $replace && 'closed' == $post->comment_status) );
}


function revu_sync_comments($comments) {
    global $wpdb;

    // user MUST be logged out during this process
    wp_set_current_user(0);

    // we need the thread_ids so we can map them to posts
    $thread_map = array();
    foreach ( $comments as $comment ) {
        $thread_map[$comment->thread->id] = null;
    }
    $thread_ids = "'" . implode("', '", array_keys($thread_map)) . "'";

    $results = $wpdb->get_results( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = 'dsq_thread_id' AND meta_value IN ({$thread_ids}) LIMIT 1");
    foreach ( $results as $result ) {
        $thread_map[$result->meta_value] = $result->post_id;
    }
    unset($result);

    foreach ( $comments as $comment ) {
        $ts = strtotime($comment->created_at);
        if (!$thread_map[$comment->thread->id] && !empty($comment->thread->identifier)) {
            // legacy threads dont already have their meta stored
            foreach ( $comment->thread->identifier as $identifier ) {
                // we know identifier starts with post_ID
                if ($post_ID = (int)substr($identifier, 0, strpos($identifier, ' '))) {
                    $thread_map[$comment->thread->id] = $post_ID;
                    update_post_meta($post_ID, 'dsq_thread_id', $comment->thread->id);
                }
            }
            unset($identifier);
        }
       
        $results = $wpdb->get_results($wpdb->prepare("SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'dsq_post_id' AND meta_value = %s LIMIT 1", $comment->id));
        if (count($results)) {
            // already exists
           
            if (count($results) > 1) {
              
                $results = array_slice($results, 1);
                foreach ($results as $result) {
                    $wpdb->prepare("DELETE FROM $wpdb->commentmeta WHERE comment_id = %s LIMIT 1", $result);
                }
            }
            continue;
        }

        $commentdata = false;

        // first lets check by the id we have stored
        if ($comment->meta) {
            $meta = explode(';', $comment->meta);
            foreach ($meta as $value) {
                $value = explode('=', $value);
                $meta[$value[0]] = $value[1];
            }
            unset($value);
            if ($meta['wp_id']) {
                $commentdata = $wpdb->get_row($wpdb->prepare( "SELECT comment_ID, comment_parent FROM $wpdb->comments WHERE comment_ID = %s LIMIT 1", $meta['wp_id']), ARRAY_A);
            }
        }

        // skip comments that were imported but are missing meta information
       

      
        if (!$commentdata) {
            $commentdata = $wpdb->get_row($wpdb->prepare( "SELECT comment_ID, comment_parent FROM $wpdb->comments WHERE comment_agent = 're-vu:{$comment->id}' LIMIT 1"), ARRAY_A);
        }
        if (!$commentdata) {
            // Comment doesnt exist yet, lets insert it
            if ($comment->status == 'approved') {
                $approved = 1;
            } elseif ($comment->status == 'spam') {
                $approved = 'spam';
            } else {
                $approved = 0;
            }
            $commentdata = array(
                'comment_post_ID' => $thread_map[$comment->thread->id],
                'comment_date' => $comment->created_at,
                'comment_date_gmt' => $comment->created_at,
                'comment_content' => apply_filters('pre_comment_content', $comment->message),
                'comment_approved' => $approved,
                'comment_agent' => 're-vu:'.intval($comment->id),
                'comment_type' => '',
            );
            if ($comment->is_anonymous) {
                $commentdata['comment_author'] = $comment->anonymous_author->name;
                $commentdata['comment_author_email'] = $comment->anonymous_author->email;
                $commentdata['comment_author_url'] = $comment->anonymous_author->url;
                $commentdata['comment_author_IP'] = $comment->anonymous_author->ip_address;
            } else {
                $commentdata['comment_author'] = $comment->author->display_name;
                $commentdata['comment_author_email'] = $comment->author->email;
                $commentdata['comment_author_url'] = $comment->author->url;
                $commentdata['comment_author_IP'] = $comment->author->ip_address;
            }
            $commentdata = wp_filter_comment($commentdata);
            if ($comment->parent_post) {
                $parent_id = $wpdb->get_var($wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'dsq_post_id' AND meta_value = %s LIMIT 1", $comment->parent_post));
                if ($parent_id) {
                    $commentdata['comment_parent'] = $parent_id;
                }
            }

            // due to a race condition we need to test again for coment existance
           

            $commentdata['comment_ID'] = wp_insert_comment($commentdata);
           
        }
        if (!$commentdata['comment_parent'] && $comment->parent_post) {
            $parent_id = $wpdb->get_var($wpdb->prepare( "SELECT comment_id FROM $wpdb->commentmeta WHERE meta_key = 'dsq_post_id' AND meta_value = %s LIMIT 1", $comment->parent_post));
            if ($parent_id) {
                $wpdb->query($wpdb->prepare( "UPDATE $wpdb->comments SET comment_parent = %s WHERE comment_id = %s", $parent_id, $commentdata['comment_ID']));
                

            }
        }
        $comment_id = $commentdata['comment_ID'];
        update_comment_meta($comment_id, 'dsq_parent_post_id', $comment->parent_post);
        update_comment_meta($comment_id, 'dsq_post_id', $comment->id);
    }
    unset($comment);

    if( isset($_POST['dsq_api_key']) ) {
        if( isset($_GET['dsq_sync_action']) && isset($_GET['dsq_sync_comment_id']) ) {
            $comment_parts = explode('=', $_GET['dsq_sync_comment_id']);

            if (!($comment_id = intval($comment_parts[1])) > 0) {
                return;
            }

            if( 'wp_id' != $comment_parts[0] ) {
                $comment_id = $wpdb->get_var($wpdb->prepare('SELECT comment_ID FROM ' . $wpdb->comments . ' WHERE comment_post_ID = %d AND comment_agent LIKE %s', intval($post->ID), 're-vu:' . $comment_id));
            }

            switch( $_GET['dsq_sync_action'] ) {
                case 'mark_spam':
                    wp_set_comment_status($comment_id, 'spam');
                    echo "<!-- dsq_sync: wp_set_comment_status($comment_id, 'spam') -->";
                    break;
                case 'mark_approved':
                    wp_set_comment_status($comment_id, 'approve');
                    echo "<!-- dsq_sync: wp_set_comment_status($comment_id, 'approve') -->";
                    break;
                case 'mark_killed':
                    wp_set_comment_status($comment_id, 'hold');
                    echo "<!-- dsq_sync: wp_set_comment_status($comment_id, 'hold') -->";
                    break;
            }
        }
    }
}

// ugly global hack for comments closing
$EMBED = false;
function revu_comments_template($value) {
    global $EMBED;
    global $post;
    global $comments;

    if ( !( is_singular() && ( have_comments() || 'open' == $post->comment_status ) ) ) {
        return;
    }

   

  
    $EMBED = true;
    return dirname(__FILE__) . '/comments.php';
}

// Mark entries in index to replace comments link.
// As of WordPress 3.1 this is required to return a numerical value
function revu_comments_number($count) {
    global $post;

    return $count;
}

function revu_comments_text($comment_text) {
    global $post;

    if ( revu_can_replace() ) {
        return '<span class="dsq-postid" rel="'.htmlspecialchars(revu_identifier_for_post($post)).'">'.$comment_text.'</span>';
    } else {
        return $comment_text;
    }
}

function revu_bloginfo_url($url) {
    if ( get_feed_link('comments_rss2') == $url && revu_can_replace() ) {
      
    } else {
        return $url;
    }
}


/**
 * Hide the default comment form to stop spammers by marking all comments
 * as closed.
 */
function revu_comments_open($open, $post_id=null) {
    global $EMBED;
    if ($EMBED) return false;
    return $open;
}
add_filter('comments_open', 'revu_comments_open');


function revu_add_pages() {
     add_submenu_page(
         'edit-comments.php',
         're-vu',
         're-vu',
         'moderate_comments',
         're-vu',
         'revu_manage'
     );
}
add_action('admin_menu', 'revu_add_pages', 10);



// only active on dashboard
function revu_dash_comment_counts() {
    global $wpdb;
// taken from wp-includes/comment.php - WP 2.8.5
    $count = $wpdb->get_results("
        SELECT comment_approved, COUNT( * ) AS num_comments
        FROM {$wpdb->comments}
        WHERE comment_type != 'trackback'
        AND comment_type != 'pingback'
        GROUP BY comment_approved
    ", ARRAY_A );
    $total = 0;
    $approved = array('0' => 'moderated', '1' => 'approved', 'spam' => 'spam');
    $known_types = array_keys( $approved );
    foreach( (array) $count as $row_num => $row ) {
        $total += $row['num_comments'];
        if ( in_array( $row['comment_approved'], $known_types ) )
            $stats[$approved[$row['comment_approved']]] = $row['num_comments'];
    }

    $stats['total_comments'] = $total;
    foreach ( $approved as $key ) {
        if ( empty($stats[$key]) )
            $stats[$key] = 0;
    }
    $stats = (object) $stats;
?>
<style type="text/css">
#dashboard_right_now .inside,
#dashboard_recent_comments div.trackback {
    display: none;
}
</style>

<?php
}
function revu_wp_dashboard_setup() {
    add_action('admin_head', 'revu_dash_comment_counts');
}
add_action('wp_dashboard_setup', 'revu_wp_dashboard_setup');

function revu_manage() {
    if (revu_does_need_update() && isset($_POST['upgrade'])) {
        dsq_install();
    }

    if (revu_does_need_update() && isset($_POST['uninstall'])) {
        include_once(dirname(__FILE__) . '/upgrade.php');
    } else {
        include_once(dirname(__FILE__) . '/manage.php');
    }
}

function revu_admin_head() {
    if (isset($_GET['page']) && $_GET['page'] == 're-vu') {
?>
<link rel='stylesheet' href='<?php echo DSQ_PLUGIN_URL; ?>/media/styles/manage.css' type='text/css' />
<style type="text/css">
.dsq-importing, .dsq-imported, .dsq-import-fail, .dsq-exporting, .dsq-exported, .dsq-export-fail {
    background: url(<?php echo admin_url('images/loading.gif'); ?>) left center no-repeat;
    line-height: 16px;
    padding-left: 20px;
}
p.status {
    padding-top: 0;
    padding-bottom: 0;
    margin: 0;
}
.dsq-imported, .dsq-exported {
    background: url(<?php echo admin_url('images/yes.png'); ?>) left center no-repeat;
}
.dsq-import-fail, .dsq-export-fail {
    background: url(<?php echo admin_url('images/no.png'); ?>) left center no-repeat;
}
</style>
<script type="text/javascript">
jQuery(function($) {
    $('#dsq-tabs li').click(function() {
        $('#dsq-tabs li.selected').removeClass('selected');
        $(this).addClass('selected');
        $('.dsq-main, .dsq-advanced').hide();
        $('.' + $(this).attr('rel')).show();
    });
    if (location.href.indexOf('#adv') != -1) {
        $('#dsq-tab-advanced').click();
    }
    dsq_fire_export();
    dsq_fire_import();
});
dsq_fire_export = function() {
    var $ = jQuery;
    $('#dsq_export a.button, #dsq_export_retry').unbind().click(function() {
        $('#dsq_export').html('<p class="status"></p>');
        $('#dsq_export .status').removeClass('dsq-export-fail').addClass('dsq-exporting').html('Processing...');
        dsq_export_comments();
        return false;
    });
}
dsq_export_comments = function() {
    var $ = jQuery;
    var status = $('#dsq_export .status');
    var export_info = (status.attr('rel') || '0|' + (new Date().getTime()/1000)).split('|');
    $.get(
        '<?php echo admin_url('index.php'); ?>',
        {
            cf_action: 'export_comments',
            post_id: export_info[0],
            timestamp: export_info[1]
        },
        function(response) {
            switch (response.result) {
                case 'success':
                    status.html(response.msg).attr('rel', response.post_id + '|' + response.timestamp);
                    switch (response.status) {
                        case 'partial':
                            dsq_export_comments();
                            break;
                        case 'complete':
                            status.removeClass('dsq-exporting').addClass('dsq-exported');
                            break;
                    }
                break;
                case 'fail':
                    status.parent().html(response.msg);
                    dsq_fire_export();
                break;
            }
        },
        'json'
    );
}
dsq_fire_import = function() {
    var $ = jQuery;
    $('#dsq_import a.button, #dsq_import_retry').unbind().click(function() {
        var wipe = $('#dsq_import_wipe').is(':checked');
        $('#dsq_import').html('<p class="status"></p>');
        $('#dsq_import .status').removeClass('dsq-import-fail').addClass('dsq-importing').html('Processing...');
        dsq_import_comments(wipe);
        return false;
    });
}
dsq_import_comments = function(wipe) {
    var $ = jQuery;
    var status = $('#dsq_import .status');
    var last_comment_id = status.attr('rel') || '0';
    $.get(
        '<?php echo admin_url('index.php'); ?>',
        {
            cf_action: 'import_comments',
            last_comment_id: last_comment_id,
            wipe: (wipe ? 1 : 0)
        },
        function(response) {
            switch (response.result) {
                case 'success':
                    status.html(response.msg).attr('rel', response.last_comment_id);
                    switch (response.status) {
                        case 'partial':
                            dsq_import_comments();
                            break;
                        case 'complete':
                            status.removeClass('dsq-importing').addClass('dsq-imported');
                            break;
                    }
                break;
                case 'fail':
                    status.parent().html(response.msg);
                    dsq_fire_import();
                break;
            }
        },
        'json'
    );
}
</script>
<?php
// HACK: Our own styles for older versions of WordPress.
        global $wp_version;
        if ( version_compare($wp_version, '2.5', '<') ) {
            echo "<link rel='stylesheet' href='" . DSQ_PLUGIN_URL . "/media/styles/manage-pre25.css' type='text/css' />";
        }
    }
}
add_action('admin_head', 'revu_admin_head');

function revu_warning() {
   
}



function revu_maybe_add_post_ids($posts) {
    global $DSQ_QUERY_COMMENTS;
    if ($DSQ_QUERY_COMMENTS) {
        revu_add_query_posts($posts);
    }
    return $posts;
}
add_action('the_posts', 'revu_maybe_add_post_ids');

function revu_add_query_posts($posts) {
    global $DSQ_QUERY_POST_IDS;
    if (count($posts)) {
        foreach ($posts as $post) {
            $post_ids[] = intval($post->ID);
        }
        $DSQ_QUERY_POST_IDS[md5(serialize($post_ids))] = $post_ids;
    }
}

// check to see if the posts in the loop match the original request or an explicit request, if so output the JS
function revu_loop_end($query) {
  
    global $DSQ_QUERY_POST_IDS;
    foreach ($query->posts as $post) {
        $loop_ids[] = intval($post->ID);
    }
    $posts_key = md5(serialize($loop_ids));
    if (isset($DSQ_QUERY_POST_IDS[$posts_key])) {
        dsq_output_loop_comment_js($DSQ_QUERY_POST_IDS[$posts_key]);
    }
}
add_action('loop_end', 'revu_loop_end');

// if someone has a better hack, let me know
// prevents duplicate calls to count.js
$_HAS_COUNTS = false;


$revu_prev_permalinks = array();

function revu_prev_permalink($post_id) {
// if post not published, return
    $post = &get_post($post_id);
    if ($post->post_status != 'publish') {
        return;
    }
    global $revu_prev_permalinks;
    $revu_prev_permalinks['post_'.$post_id] = get_permalink($post_id);
}
add_action('pre_post_update', 'revu_prev_permalink');

function revu_check_permalink($post_id) {
    global $revu_prev_permalinks;
    if (!empty($revu_prev_permalinks['post_'.$post_id]) && $revu_prev_permalinks['post_'.$post_id] != get_permalink($post_id)) {
        $post = get_post($post_id);
        dsq_update_permalink($post);
    }
}
add_action('edit_post', 'revu_check_permalink');

add_action('admin_notices', 'revu_warning');


add_filter('comments_template', 'revu_comments_template');
add_filter('comments_number', 'revu_comments_text');
add_filter('get_comments_number', 'revu_comments_number');
add_filter('bloginfo_url', 'revu_bloginfo_url');

/**
 * JSON ENCODE for PHP < 5.2.0
 * Checks if json_encode is not available and defines json_encode
 * to use php_json_encode in its stead
 * Works on iteratable objects as well - stdClass is iteratable, so all WP objects are gonna be iteratable
 */
if(!function_exists('cf_json_encode')) {
    function cf_json_encode($data) {
// json_encode is sending an application/x-javascript header on Joyent servers
// for some unknown reason.
//         if(function_exists('json_encode')) { return json_encode($data); }
//         else { return cfjson_encode($data); }
        return cfjson_encode($data);
    }

    function cfjson_encode_string($str) {
        if(is_bool($str)) {
            return $str ? 'true' : 'false';
        }

        return str_replace(
            array(
                '"'
                , '/'
                , "\n"
                , "\r"
            )
            , array(
                '\"'
                , '\/'
                , '\n'
                , '\r'
            )
            , $str
        );
    }

    function cfjson_encode($arr) {
        $json_str = '';
        if (is_array($arr)) {
            $pure_array = true;
            $array_length = count($arr);
            for ( $i = 0; $i < $array_length ; $i++) {
                if (!isset($arr[$i])) {
                    $pure_array = false;
                    break;
                }
            }
            if ($pure_array) {
                $json_str = '[';
                $temp = array();
                for ($i=0; $i < $array_length; $i++) {
                    $temp[] = sprintf("%s", cfjson_encode($arr[$i]));
                }
                $json_str .= implode(',', $temp);
                $json_str .="]";
            }
            else {
                $json_str = '{';
                $temp = array();
                foreach ($arr as $key => $value) {
                    $temp[] = sprintf("\"%s\":%s", $key, cfjson_encode($value));
                }
                $json_str .= implode(',', $temp);
                $json_str .= '}';
            }
        }
        else if (is_object($arr)) {
            $json_str = '{';
            $temp = array();
            foreach ($arr as $k => $v) {
                $temp[] = '"'.$k.'":'.cfjson_encode($v);
            }
            $json_str .= implode(',', $temp);
            $json_str .= '}';
        }
        else if (is_string($arr)) {
            $json_str = '"'. cfjson_encode_string($arr) . '"';
        }
        else if (is_numeric($arr)) {
            $json_str = $arr;
        }
        else if (is_bool($arr)) {
            $json_str = $arr ? 'true' : 'false';
        }
        else {
            $json_str = '"'. cfjson_encode_string($arr) . '"';
        }
        return $json_str;
    }
}

// Single Sign-on Integration

function revu_sso() {
   
    global $current_user, $dsq_api;
    get_currentuserinfo();
    if ($current_user->ID) {
        $avatar_tag = get_avatar($current_user->ID);
        $avatar_data = array();
        preg_match('/(src)=((\'|")[^(\'|")]*(\'|"))/i', $avatar_tag, $avatar_data);
        $avatar = str_replace(array('"', "'"), '', $avatar_data[2]);
        $user_data = array(
            'username' => $current_user->display_name,
            'id' => $current_user->ID,
            'avatar' => $avatar,
            'email' => $current_user->user_email,
        );
    }
    else {
        $user_data = array();
    }
    $user_data = base64_encode(cf_json_encode($user_data));
    $time = time();
    $hmac = revu_hmacsha1($user_data.' '.$time, $key);

    $payload = $user_data.' '.$hmac.' '.$time;

    if ($new) {
        return array('remote_auth_s3'=>$payload, 'api_key'=>$public);
    } else {
        return array('remote_auth_s2'=>$payload);
    }
}

// from: http://www.php.net/manual/en/function.sha1.php#39492
//Calculate HMAC-SHA1 according to RFC2104
// http://www.ietf.org/rfc/rfc2104.txt
function revu_hmacsha1($data, $key) {
    $blocksize=64;
    $hashfunc='sha1';
    if (strlen($key)>$blocksize)
        $key=pack('H*', $hashfunc($key));
    $key=str_pad($key,$blocksize,chr(0x00));
    $ipad=str_repeat(chr(0x36),$blocksize);
    $opad=str_repeat(chr(0x5c),$blocksize);
    $hmac = pack(
                'H*',$hashfunc(
                    ($key^$opad).pack(
                        'H*',$hashfunc(
                            ($key^$ipad).$data
                        )
                    )
                )
            );
    return bin2hex($hmac);
}

function revu_identifier_for_post($post) {
    return $post->ID . ' ' . $post->guid;
}

function revu_title_for_post($post) {
    $title = get_the_title($post);
   
    return $title;
}

function revu_link_for_post($post) {
    return get_permalink($post);
}

function revu_does_need_update() {
    $version = (string)get_option('re-vu_version');
    if (empty($version)) {
        $version = '0';
    }

    if (version_compare($version, '2.49', '<')) {
        return true;
    }

    return false;
}





add_action('admin_menu', 'kampyle_admin_menu');
function kampyle_admin_menu()
{
    add_submenu_page('edit-comments.php','Add code','Add code','manage_options', dirname(__FILE__) . '/options.php');
}


?>
