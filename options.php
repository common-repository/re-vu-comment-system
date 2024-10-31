<?php

if (function_exists('load_plugin_textdomain')) {
    load_plugin_textdomain('kampyle-integrator', 'wp-content/plugins/kampyle-integrator');
}
function kampyle_request($name, $default=null) 
{
	if (!isset($_REQUEST[$name])) return $default;
	return stripslashes_deep($_REQUEST[$name]);
}
	

function kampyle_field_textarea($name, $label='', $tips='', $attrs='')
{
  global $options;
  
  if (strpos($attrs, 'cols') === false) $attrs .= 'cols="70"';
  if (strpos($attrs, 'rows') === false) $attrs .= 'rows="5"';
  
  echo '<th scope="row">';
  echo '<label for="options[' . $name . ']">' . $label . '</label></th>';
  echo '<td><textarea wrap="off" ' . $attrs . ' name="options[' . $name . ']">' . 
    htmlspecialchars($options[$name]) . '</textarea>';
 
  echo '</td>';
}	

if (isset($_POST['save']))
{
    if (!wp_verify_nonce($_POST['_wpnonce'], 'save')) die('Securety violated');
    $options = kampyle_request('options');
	
	update_option('kampyle', $options);
}
else 
{
    $options = get_option('kampyle');
}
?>	

<div class="wrap">

<form method="post">
<?php wp_nonce_field('save') ?>
<h2>Add your Re-vu code</h2>


<table class="form-table">
<tr valign="top"><?php kampyle_field_textarea('head', __('Enter Your Code Here', 'kampyle-integrator'), __('head hint', 'kampyle-integrator'), 'rows="10"'); ?></tr>

</table>

<p class="submit"><input type="submit" name="save" value="<?php _e('save'); ?>"></p>

</form>
</div>
