<?php

$kampyle_options = get_option('kampyle');

add_action('admin_menu', 'kampyle_admin_menu');
function kampyle_admin_menu()
{
    add_submenu_page('edit-comments.php','Add code','Add code','manage_options', 'kampyle-integrator-for-wordpress/options.php');
}

add_action('wp_head', 'kampyle_wp_head');
function kampyle_wp_head()
{
    global $kampyle_options;
    
    if (is_home())
	echo $kampyle_options['head_home'];
    
    echo $kampyle_options['head'];
}

add_action('wp_footer', 'kampyle_wp_footer');
function kampyle_wp_footer()
{
    global $kampyle_options;
    
    echo $kampyle_options['footer'];
}
?>
