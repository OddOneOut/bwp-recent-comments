<?php
/**
 * Copyright (c) 2011 Khang Minh <betterwp.net>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * The template function to get a recent comment list
 *
 * If you don't provide any parameter, the list will be generated using options in the database (let's call this a global list).
 * The $instance parameter allows you to build a "unique" comment list that is different from other comment lists
 * Example use would be you create a comment list for post type A, and create another one for post type B
 * while you still have a global list to display everything in one place.
 * Support for different categories in my opinion is not necessary, it might be added in the future, if it has enough requests ;-)
 *
 * @param	array	$args	hold all parameters
 * @param	bool	$echo	determine whether to echo or return the output
 * @param	bool	$need_refresh	determine whether to refresh the output or not
 */
function bwp_get_recent_comments($args = array(), $echo = true, $need_refresh = false)
{
	global $bwp_rc;

	if (!is_array($args)) return;

	$defaults = $bwp_rc->get_default_parameters();

	$args = wp_parse_args($args, $defaults);

	extract($args);

	return $bwp_rc->get_recent_comments($echo, $need_refresh, $instance, $post_type, $post_id, $comment_type, $separate, $limit, $tb_limit, $order, $paged, $paged_limit);
}

/**
 * Derivative function for lazy people
 */
function bwp_get_recent_trackbacks()
{
	return bwp_get_recent_comments(array('comment_type' => 'tb'));
}

/**
 * Display a pagination for a static page
 *
 * @since 1.1.0
 */
function bwp_rc_get_pagination($limit, $prev_str = '', $next_str = '')
{
	global $bwp_rc_left;

	if (empty($limit)) return;

	if (is_singular())
	{
		$paged = (int) get_query_var('bwprc_paged');
		$paged = (empty($paged) || 0 > $paged) ? 1 : $paged;
		// Previous comment link
		$prev_var = ($paged <= 1) ? '' : '?bwprc_paged=' . ($paged - 1);
		$prev = ($paged > 1) ? sprintf('<a href="%s" title="' . __('Previous Comment Page', 'bwp-rc') . '">' . $prev_str . '</a> ', trailingslashit(get_permalink()) . $prev_var) : '';
		// Next comment link
		$next_var = ($paged == 0) ? '?bwprc_paged=2' : '?bwprc_paged=' . ($paged + 1);
		$next = ($bwp_rc_left > $limit) ? sprintf('<a href="%s" title="' . __('Next Comment Page', 'bwp-rc') . '">' . $next_str . '</a>', trailingslashit(get_permalink()) . $next_var) : '';
		echo $prev . $next;
	}
}

/**
 * Display a paginated comment list
 *
 * @since 1.1.0
 */
function bwp_rc_paged_list($args = array(), $limit = 5, $prev_str = '&laquo;&laquo;', $next_str = '&raquo;&raquo;')
{
	$limit = (int) $limit; // number of comments per page
	$paged = get_query_var('bwprc_paged'); // get the current page
	$paged = (empty($paged) || 0 > $paged) ? 1 : $paged;
	$args['paged'] = $paged;
	$args['paged_limit'] = $limit;
	bwp_get_recent_comments($args, true, true);
	bwp_rc_get_pagination($limit, $prev_str, $next_str); // << for previous page, >> for next page
}

if (!class_exists('BWP_FRAMEWORK'))
	require_once(dirname(__FILE__) . '/class-bwp-framework.php');

class BWP_RC extends BWP_FRAMEWORK {

	/**
	 * Hold all instances of lists
	 */
	var $instances;

	/**
	 * Default parameters for comments
	 */
	var $default_parameters;

	/**
	 * Constructor
	 */	
	function __construct($version = '1.1.0')
	{
		// Plugin's title
		$this->plugin_title = 'BetterWP Recent Comments';
		// Plugin's version
		$this->set_version($version);
		// Basic version checking
		if (!$this->check_required_versions())
			return;
		
		// The default options
		$options = array(
			'input_comments' 		=> 5,
			'input_tbs'		 		=> 0,
			'select_output_method' 	=> 'only_comments',
			'select_order'			=> 'desc',
			'enable_gravatars' 		=> 'yes', // cb1
			'input_gravatar_width' 	=> 40,
			'enable_smilies' 		=> 'yes', // cb2
			'input_title_trim'		=> 0,
			'input_trim'			=> 25,
			'select_long_method'	=> 'long_overflow',
			'input_chunk'			=> 15,
			'input_date'			=> 'M d, g:i A',
			'input_ignore_ids'		=> '',
			'disable_own_tb'		=> '', // cb4
			'enable_css'			=> 'yes',  // cb5
			'enable_credit'			=> '', // cb6
			'input_no_found'		=> __('No recent %s found.', 'bwp-rc'),
			'input_trimmed'			=> __('This %s has been trimmed to empty.', 'bwp-rc'),
			'template_comment' 		=> '<li class="recent-comment"><span class="recent-comment-avatar">%avatar%</span><span class="recent-comment-single"><span class="recent-comment-author">%author%</span><span class="recent-comment-text"> { %excerpt% } &ndash; </span> <a href="%link%" title="%post_title_attr%"><strong>%time%</strong></a></span></li>',
			'template_paged_comment'=> '<li class="recent-comment"><span class="recent-comment-avatar">%avatar%</span><span class="recent-comment-single"><span class="recent-comment-author">%author%</span><span class="recent-comment-text"> { %excerpt% } &ndash; </span> <a href="%link%" title="%post_title_attr%"><strong>%time%</strong></a></span></li>',
			'template_owner' 		=> '',
			'template_tbpb' 		=> '<li class="recent-comment recent-comment-tb"><span class="recent-comment-single"><span class="recent-comment-author"><a href="%author_url%"><strong>%author%</strong></a></span><span class="recent-comment-text"> { %excerpt% } </span></span></li>'
		);

		$this->build_properties('BWP_RC', 'bwp-rc', $options, 'BetterWP Recent Comments', dirname(dirname(__FILE__)) . '/bwp-recent-comments.php', 'http://betterwp.net/wordpress-plugins/bwp-recent-comments/', false);
		
		$this->add_option_key('BWP_RC_OPTION_GENERAL', 'bwp_rc_general', __('General Options', 'bwp-rc'));
		$this->add_option_key('BWP_RC_OPTION_TEMPLATE', 'bwp_rc_template', __('Template Options', 'bwp-rc'));
		$this->add_extra_option_key('BWP_RC_INSTANCES', 'bwp_rc_instances', __('Instances', 'bwp-rc'));				

		$this->default_parameters = array(
			'instance'	=> '', // the instance name for a separate comment list you would like to use.
			'post_type'	=> '', // get comments for a specific post type only
			'post_id'	=> '', // get comments from a specific post/page/custom post type, can be either the ID or postname
			'comment_type'	=> '', // get only a specific comment type, 'comment': comment, 'tb': pingback or trackback, 'all': all types of comment
			'limit'	=> 0, // determine how many comments to return, 0 to use option in db
			'tb_limit' => 0, // determine how many trackbacks to return, 0 to use option in db, only used when separate is true
			'order'	=> '', // determine how to sort the result, empty to use option in db
			'separate' => false, // should we separate between comments and pingbacks/trackbacks
			'paged' => NULL, // display paged comments
			'paged_limit' => 5 // number of comments per page
		);
			
		$this->init();
		
		// Define other constants		
		define('BWP_RC_COPYRIGHT', __('Generated by <a href="%s" title="Recent Comment Plugin">BWP Recent Comments</a>.', 'bwp-rc'));
		define('BWP_RC_LIST', 'bwp_rc_list');
		
		// initialize instances
		$db_instances = get_option(BWP_RC_INSTANCES);
		if (!$db_instances || !is_array($db_instances))
			$this->reset_instances();
		else
			$this->instances = $db_instances;
	}

	function insert_query_vars($vars)
	{
		array_push($vars, 'bwprc_paged');
		return $vars;
	}

	function add_hooks()
	{
		// Actions and Filters
		add_action('comment_post', array($this, 'comment_before_add'), 10, 2);
		// Clear cache when necessary
		add_action('edit_comment', array($this, 'clear_recent_comment_cache'));
		add_action('delete_comment', array($this, 'clear_recent_comment_cache'));
		add_action('delete_post', array($this, 'clear_recent_comment_cache'));
		add_action('switch_theme', array($this, 'clear_recent_comment_cache'));
		add_action('wp_set_comment_status', array($this, 'clear_recent_comment_cache'));
		add_action('bwp_rc_access_options', array($this, 'clear_recent_comment_cache'));
		add_action('bwp_rc_form_loaded', array($this, 'clear_recent_comment_cache'));
		// Pagination query var - @since 1.1.0
		add_filter('query_vars', array($this, 'insert_query_vars'));
		// Add the widget, only if WordPress's version is 2.9 or higher
		if (version_compare(get_bloginfo('version'), '2.9', '>='))
		{
			require_once(dirname(__FILE__) . '/class-bwp-rc-widget.php');
			add_action('widgets_init', 'bwp_recent_comment_register_widget');
		}
	}

	function enqueue_media()
	{		
		if ('yes' == $this->options['enable_css'] && !is_admin())
			wp_enqueue_style('bwp-rc', BWP_RC_CSS . '/bwp-recent-comments.css', array(), $this->plugin_ver);

		if (is_admin())
			wp_enqueue_style('bwp-rc', BWP_RC_CSS . '/bwp-rc-widget.css');
	}

	function uninstall()
	{
		$db_instances = get_option(BWP_RC_INSTANCES);
		if ($db_instances && is_array($db_instances))
		{			
			foreach ($db_instances as $key => $instance)
				delete_option($key);
			delete_option(BWP_RC_INSTANCES);
		}		
	}

	/**
	 * Build the Menus
	 */
	function build_menus()
	{
		add_menu_page(__('Better WordPress Recent Comments', 'bwp-rc'), 'BWP RC', BWP_RC_CAPABILITY, BWP_RC_OPTION_GENERAL, array($this, 'build_option_pages'), BWP_RC_IMAGES . '/icon_menu.png');
		// Sub menus
		add_submenu_page(BWP_RC_OPTION_GENERAL, __('BWP Recent Comments General Options', 'bwp-rc'), __('General Options', 'bwp-rc'), BWP_RC_CAPABILITY, BWP_RC_OPTION_GENERAL, array($this, 'build_option_pages'));
		add_submenu_page(BWP_RC_OPTION_GENERAL, __('BWP Recent Comments Template Options', 'bwp-rc'), __('Template Options', 'bwp-rc'), BWP_RC_CAPABILITY, BWP_RC_OPTION_TEMPLATE, array($this, 'build_option_pages'));
		add_submenu_page(BWP_RC_OPTION_GENERAL, __('BWP Recent Comments Instance List', 'bwp-rc'), __('Instances', 'bwp-rc'), BWP_RC_CAPABILITY, BWP_RC_INSTANCES, array($this, 'build_option_pages'));
	}

	/**
	 * Build the option pages
	 *
	 * Utilizes BWP Option Page Builder (@see BWP_OPTION_PAGE)
	 */	
	function build_option_pages()
	{
		if (!current_user_can(BWP_RC_CAPABILITY))
			wp_die(__('You do not have sufficient permissions to access this page.'));

		// Init the class
		$page = $_GET['page'];		
		$bwp_option_page = new BWP_OPTION_PAGE($page);
		
		$options = array();

if (!empty($page))
{	
	if ($page == BWP_RC_OPTION_GENERAL)
	{
		$bwp_option_page->set_current_tab(1);

		// Option Structures - Form
		$form = array(
			'items'			=> array('input', 'input', 'select', 'select', 'heading', 'checkbox', 'checkbox', 'input', 'input', 'select', 'input', 'input', 'heading', 'input', 'input', 'input', 'checkbox', 'checkbox', 'checkbox'),
			'item_labels'	=> array
			(
				__('Show at most', 'bwp-rc'),
				__('Show at most', 'bwp-rc'),
				__('Choose an output method', 'bwp-rc'),
				__('Show', 'bwp-rc'),
				__('Formatting Options', 'bwp-rc'),
				__('Enable gravatars?', 'bwp-rc'),
				__('Convert smilies?', 'bwp-rc'),
				__('Trim post titles to', 'bwp-rc'),
				__('Trim comments to', 'bwp-rc'),
				__('With long words, this plugin should', 'bwp-rc'),
				__('Split into chunks of', 'bwp-rc'),
				__('Choose a date format', 'bwp-rc'),
				__('Miscellaneous', 'bwp-rc'),
				__('Show this message', 'bwp-rc'),
				__('Show this message', 'bwp-rc'),
				__('Hide comments by specific ids', 'bwp-rc'),
				__('Disable trackbacks/pingbacks from this website?', 'bwp-rc'),
				__('Use CSS provided by this plugin?', 'bwp-rc'),
				__('Give the author credits?', 'bwp-rc')
			),
			'heading'		=> array(
				'h1'	=> __('<em>This section allows you to customize how recent comments would appear on your website. Currently, options in this section are global which mean they will affect all recent comment list instances you create.</em>', 'bwp-rc'),
				'h2'	=> __('<em>Other options that fit nowhere.</em>', 'bwp-rc'),
			),
			'item_names'	=> array('input_comments', 'input_tbs', 'select_output_method', 'select_order', 'h1', 'cb1', 'cb2', 'input_title_trim', 'input_trim', 'select_long_method', 'input_chunk', 'input_date', 'h2', 'input_no_found', 'input_trimmed', 'input_ignore_ids', 'cb4', 'cb5', 'cb6'),
			'checkbox'		=> array
			(
				'cb1' => array('and each gravatar will be' => 'enable_gravatars'),
				'cb2' => array(__('Smilies such as <code>:-)</code> and <code>:-P</code> will be converted to icons.', 'bwp-rc') => 'enable_smilies'),
				//'cb3' => array(__('Comments posted by you will be ignored.', 'bwp-rc') => 'disable_own_com'),
				'cb4' => array(sprintf(__('All trackbacks/pingbacks originating from <code>%s</code> will be ignored.', 'bwp-rc'), get_option('home')) => 'disable_own_tb'),
				'cb5' => array(__('If you disable this, be sure to add needed styles to your own stylesheets.', 'bwp-rc') => 'enable_css'),
				'cb6' => array(__('A link to this plugin\'s official page will be added to the end of the of output of the first recent comment instance (i.e. only once). Thank you!', 'bwp-rc') => 'enable_credit')
			),
			'select'		=> array
			(
				'select_output_method' => array(
					__('Do not show trackbacks/pingbacks', 'bwp-rc') => 'only_comments',
					__('Show all comment types together', 'bwp-rc') => 'all_comments',
					__('Show all comment types, but separately', 'bwp-rc') => 'all_sep_comments',
		 		),
				'select_order' => array(
					__('Newer comments first', 'bwp-rc') => 'desc',
					__('Older comments first', 'bwp-rc') => 'asc'

		 		),
				'select_long_method' => array(
					__('Let the style handle the overflow', 'bwp-rc') => 'long_overflow',
					__('Split the words into smaller chunks', 'bwp-rc') => 'long_break'					
		 		),
			),
			'input'			=> array
			(				
				'input_comments' 	=> array('size' => 5, 'label' => __('recent comments.', 'bwp-rc')),
				'input_tbs' 		=> array('size' => 5, 'label' => __('recent trackbacks/pingbacks (used when choose "separate" as output method.)', 'bwp-rc')),
				'input_date' 		=> array('size' => 10, 'label' => __('To choose an appropriate format, please consult <a href="http://codex.wordpress.org/Formatting_Date_and_Time" target="_blank">WordPress Codex</a>.', 'bwp-rc')),
				'input_gravatar_width' => array('size' => 5, 'label' => __('pxs wide.', 'bwp-rc')),
				'input_title_trim' => array('size' => 5, 'label' => __('words. If you specify <code>0</code>, no trim will occur.', 'bwp-rc')),
				'input_trim' => array('size' => 5, 'label' => __('words. If you specify <code>0</code>, no trim will occur (not recommended).', 'bwp-rc')),
				'input_chunk' => array('size' => 5, 'label' => __('characters. If you choose to split the long words, it will be split into chunks with such characters maximum.', 'bwp-rc')),
				'input_no_found' => array('size' => 40, 'label' => __('when no comment/pingbacks found.', 'bwp-rc')),
				'input_trimmed' => array('size' => 40, 'label' => __('when the comment excerpt is trimmed to empty.', 'bwp-rc')),
				'input_ignore_ids' => array('size' => 20, 'label' => __('Separate user ids by commas, e.g. 1,2,3. Leave blank if not used.', 'bwp-rc'))
			),
			'container'		=> array
			(
				'select_order' 	=> __('<em><strong>Note:</strong> If you use the template function <code>bwp_get_recent_comments()</code> or widgets to show the comment list, you can override the options here. Please note that you can not override options for the global list.</em>', 'bwp-rc'),
				'input_chunk' 	=> __('<em><strong>Note:</strong> Long words (without spaces) posted by visitors, such as <code>YEAHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHH</code>, might break your layout. If you use the default css provided by this plugin, it should handle such behaviour already (by adding <code>overflow: hidden</code> to the <code>recent-comment-text</code> class.) Splitting long words might result in unexpected results so it\'s best to just stick to css. If you want, you can use both methods, of course!</em>', 'bwp-rc')
			),
			'inline_fields' => array(
				'cb1' => array('input_gravatar_width' => 'input')
			)
		);

		// Get the default options
		$options = $bwp_option_page->get_options(array('input_comments', 'input_tbs', 'select_output_method', 'select_order', 'enable_gravatars', 'input_gravatar_width', 'enable_smilies', 'input_title_trim', 'input_trim', 'select_long_method', 'input_chunk', 'input_date', 'enable_css', 'enable_credit', 'input_ignore_ids', 'disable_own_tb', 'input_no_found', 'input_trimmed', 'enable_selective'), $this->options);

		// Get option from the database
		$options = $bwp_option_page->get_db_options($page, $options);
		
		$option_formats = array('input_comments' => 'int', 'input_tbs' => 'int', 'input_gravatar_width' => 'int', 'input_title_trim' => 'int', 'input_trim' => 'int', 'input_chunk' => 'int', 'input_no_found' => 'html', 'input_trimmed' => 'html');
	}
	else if ($page == BWP_RC_OPTION_TEMPLATE)
	{
		$bwp_option_page->set_current_tab(2);

		// Option Structures - Form
		$form = array(
			'items'			=> array('heading', 'textarea', 'textarea', 'textarea', 'textarea'),
			'item_labels'	=> array
			(
				__('Template for your recent comment list', 'bwp-rc'),
				__('Template for comments', 'bwp-rc'),				
				__('Template for trackbacks/pingbacks', 'bwp-rc'),
				__('Template for comments by you', 'bwp-rc'),
				sprintf(__('Template for comments with pagination &mdash; <a href="%s#pagination" target="_blank">more info</a>', 'bwp-rc'), $this->plugin_url)
			),
			'item_names'	=> array('h1', 'template_comment', 'template_tbpb', 'template_owner', 'template_paged_comment'),
			'heading'			=> array(
				'h1'	=> __('This section allows you to define your own template. Use tags listed below to add appropriate contents. Please note that after you press reset, you still have to press submit changes for the changes to be saved.', 'bwp-rc')
			),
			'textarea'			=> array
			(
				'template_comment' 	=> array('cols' => 70, 'rows' => 5),
				'template_paged_comment' 	=> array('cols' => 70, 'rows' => 5),
				'template_owner' 	=> array('cols' => 70, 'rows' => 5),
				'template_tbpb' 	=> array('cols' => 70, 'rows' => 5)
			),
			'inline'			=> array
			(
				'template_comment' => '<br /><br /><input type="submit" class="button" name="reset_comment" value="' . __('Reset', 'bwp-rc') . '" />',
				'template_paged_comment' => '<br /><br /><input type="submit" class="button" name="reset_paged" value="' . __('Reset', 'bwp-rc') . '" />',
				'template_owner' => '<br /><br /><em>' . __('Leave this blank if you do not wish to use.', 'bwp-rc') . '</em>',
				'template_tbpb' => '<br /><br /><input type="submit" class="button" name="reset_tbpb" value="' . __('Reset', 'bwp-rc') . '" />'
			),
			'container' => array(
				'h1' => '
	<code>%excerpt%</code> {<em>' . 		__('Trimmed down comment', 'bwp-rc') . '</em>}
	<code>%link%</code> {<em>' . 			__('The comment\'s permalink', 'bwp-rc') . '</em>}
	<code>%author%</code> {<em>' . 			__('The name of the visitor', 'bwp-rc') . '</em>}
	<code>%author_url%</code> {<em>' . 		__('Link to the author\'s website or trackback/pingback\'s source', 'bwp-rc') . '</em>}
	<code>%time%</code> {<em>' . 			__('The timestamp of the comment', 'bwp-rc') . '</em>}
	<code>%avatar%</code> {<em>' . 			__('Avatar of the visitor (in HTML)', 'bwp-rc') . '</em>}
	<code>%post_title%</code> {<em>' . 		__('Title of the post', 'bwp-rc') . '</em>}
	<code>%post_title_attr%</code> {<em>' . __('Title of the post that has been properly escaped for title attribute', 'bwp-rc') . '</em>}
	<code>%post_link%</code> {<em>' . 		__('The post\'s permalink', 'bwp-rc') . '</em>}
	<code>%home%</code> {<em>' . 			__('The URL to your homepage', 'bwp-rc') . '</em>}'
			)
		);

		// Get the default options
		$options = $bwp_option_page->get_options(array('template_comment', 'template_paged_comment', 'template_tbpb', 'template_owner'), $this->options);
		
		// Get option from the database
		$options = $bwp_option_page->get_db_options($page, $options);
		$option_formats = array('template_comment' => 'html', 'template_paged_comment' => 'html', 'template_tbpb' => 'html', 'template_owner' => 'html');
		
		// Reset button
		if (isset($_POST['reset_comment']))
			$options['template_comment'] = $this->options_default['template_comment'];
		if (isset($_POST['reset_tbpb']))
			$options['template_tbpb'] = $this->options_default['template_tbpb'];
		if (isset($_POST['reset_paged']))
			$options['template_paged_comment'] = $this->options_default['template_paged_comment'];
		// Update the option? or not...
		/*if (isset($_POST['reset_comment']) || isset($_POST['reset_tbpb']))
			update_option($page, $options);*/
	}
	else if ($page == BWP_RC_INSTANCES)
	{
		$bwp_option_page->set_current_tab(3);

		// Option Structures - Form
		$form = array(
			'items'			=> array('heading', 'select', 'heading'),
			'item_labels'	=> array
			(
				__('List of all instances you have created', 'bwp-rc'),
				__('Choose an instance to delete', 'bwp-rc'),
				__('Reset all instances', 'bwp-rc')
			),
			'item_names'	=> array('h1', 'sel1', 'h2'),
			'heading'			=> array(
				'h1'	=> __('Here you can see all instances you have created and currently you can delete any instance you don\'t use anymore.', 'bwp-rc'),
				'h2'	=> __('If for some reasons you can not delelete an instance above (mostly because of malformed instances produced by previous versions\' bugs), you will have to reset all your instances here. Don\'t worry, though, all instances should be re-created automatically after you refresh public pages with comment instances on them.', 'bwp-rc')
			),
			'select'		=> array
			(
				'sel1' => array(__('----------', 'bwp-rc') => '')
			),
			'container'		=> array
			(
				'sel1' 	=> __('<input type="submit" class="button" name="delete_instance" value="' . __('Delete the selected instance', 'bwp-rc') . '" />', 'bwp-rc'),
				'h2' 	=> __('<input type="submit" class="button" name="reset_instances" value="' . __('Reset all instances now!', 'bwp-rc') . '" />', 'bwp-rc')
			)
		);

		if (isset($_POST['delete_instance']) && !empty($_POST['sel1']) && isset($this->instances[$_POST['sel1']]))
		{
			check_admin_referer($page);
			delete_option($_POST['sel1']);
			unset($this->instances[$_POST['sel1']]);
			update_option(BWP_RC_INSTANCES, $this->instances);
		}
		else if (isset($_POST['reset_instances']))
			$this->reset_instances();

		$options = NULL;

		// @since 1.0.1
		if (!is_array($this->instances))
			$this->reset_instances();

		foreach ($this->instances as $instance_name => $instance)
		{
			if ($instance_name != BWP_RC_LIST)
				$form['select']['sel1'][$this->str_replace_first('bwp_rc_instance_', '', $instance_name)] = $instance_name;
		}

		$option_formats = array();
		
		add_filter('bwp_option_submit_button', create_function('', 'return "";'));
	}
}

		// Get option from user input
		if (isset($_POST['submit_' . $bwp_option_page->get_form_name()]) && isset($options) && is_array($options))
		{
			check_admin_referer($page);
			foreach ($options as $key => &$option)
			{
				if (isset($_POST[$key]))
					$bwp_option_page->format_field($key, $option_formats);
				if (!isset($_POST[$key]))
					$option = '';
				else if (isset($option_formats[$key]) && 0 == $_POST[$key] && 'int' == $option_formats[$key])
					$option = 0;
				else if (isset($option_formats[$key]) && empty($_POST[$key]) && 'int' == $option_formats[$key])
					$option = $this->options_default[$key];
				else if (!empty($_POST[$key]))
					$option = trim(stripslashes($_POST[$key]));
				else
					$option = '';
			}
			update_option($page, $options);
			// Do this for this plugin only
			$this->options = array_merge($this->options, $options);
		}

		// Assign the form and option array		
		$bwp_option_page->init($form, $options, $this->form_tabs);

		// Build the option page	
		echo $bwp_option_page->generate_html_form();
		
		// update the comment list automatically
		do_action('bwp_rc_form_loaded');
	}

	/**
	 * Make the instance name db safe
	 *
	 * @since 1.0.1
	 */
	function format_instance_name($name = '')
	{
		$name = trim(strtolower($name));
		return preg_replace('/[^a-z0-9-_\s]/ui', '', $name);
	}

	/**
	 * Reset all instances to its safe point
	 *
	 * @since 1.0.1
	 */
	function reset_instances()
	{
		$this->instances = array(BWP_RC_LIST => array());
		update_option(BWP_RC_INSTANCES, $this->instances);
	}

	function str_replace_first($search, $replace, $subject)
	{
		$pos = strpos($subject, $search);
		if ($pos !== false)
			$subject = substr_replace($subject, $replace, $pos, strlen($search));
		return $subject;
	}

	function get_instances()
	{
		return $this->instances;
	}

	function get_default_parameters()
	{
		return $this->default_parameters;
	}

	function is_credit_enable()
	{
		return $this->options['enable_credit'];
	}

	/**
	 * Trim a text to a certain number of words, adding a dotdotdot if necessary, and add break to long words.
	 *
	 * @param	string	$text	The text to trim
	 * @param	int		$length	The length you want to trim to
 	 * @param	boolean	$chunk Split long words to chunks of this length
	 * @param	boolean	$autop	Automatically add paragraph
	 */
	function trim_comment_excerpt($text = '', $length = 50, $chunk = 0, $autop = false)
	{
		if (empty($text))
			return '';
		// ensure that no comment has double spaces
		$text = preg_replace('/\s+/', ' ', $text);
		$actual_length = count(explode(' ', $text));
		$dotdotdot = ($actual_length > $length) ? '...' : '';
		$words = explode(' ', $text, $length + 1);
	
		if (count($words) > $length)
		{
			array_pop($words);
			$text = implode(' ', $words);
		}

		if (!empty($chunk))
			$text = preg_replace('#(\S{' . $chunk . ',})#e', "chunk_split('$1', $chunk, ' ')", $text);
			//$text = wordwrap($text, $chunk);
	
		$text .= $dotdotdot;

		if ($autop == true) $text = wpautop($text);
		
		return trim($text);
	}

	/**
	 * Generate a single comment
	 */
	function generate_a_comment($comment, $type = '')
	{
		if (!empty($type) && 'paged' == $type)
			$comment_template = $this->options['template_paged_comment'] . "\n";
		else if (!empty($this->options['template_owner']) && 1 == $comment['user_id'])
			$comment_template = $this->options['template_owner'] . "\n";
		else if (empty($comment['type']))
			$comment_template = $this->options['template_comment'] . "\n";
		else
			$comment_template = $this->options['template_tbpb'] . "\n";
		// Magic happens here
		$comment_string = $comment_template;
		foreach ($comment as $key => $value)
			$comment_string = str_replace('%' . $key . '%', $value, $comment_string);

		return $comment_string;
	}

	function is_comment_showable($comment_type, $commentdata)
	{
		switch ($comment_type)
		{
			case 'comment':
				if ($commentdata['comment_type'] == '')				
					return true;
			break;
			
			case 'tb':
				if ($commentdata['comment_type'] == 'trackback' || $commentdata['comment_type'] == 'pingback')				
					return true;
			break;
			
			case 'all':
			case '':
				return true;
			break;
		}
		return false;
	}

	/**
	 * Format comment contents
	 */
	function format_comment($commentdata)
	{
		$comment = array();

		$comment['home'] = get_option('home');
		$comment['user_id'] = $commentdata['user_id'];
		$date_format = (!empty($this->options['input_date'])) ? $this->options['input_date'] : $this->options_default['input_date'];
		$comment['time']	= date($date_format, strtotime($commentdata['comment_date']));
		$comment['time']	= apply_filters('bwp_rc_date_format', $comment['time'], $commentdata['comment_date']);
		// for dynamic comment_type loading
		$comment['comment_type'] = $commentdata['comment_type'];
		$comment['type']	= $commentdata['comment_type'];
		// Format post_title, also trim it if necessary - @since 1.1.0
		$trimmed_to = (!empty($this->options['input_title_trim'])) ? $this->options['input_title_trim'] : 0;
		$comment['post_title']	= __($commentdata['post_title']);
		if (!empty($trimmed_to)) $comment['post_title']	= $this->trim_comment_excerpt($comment['post_title'], $trimmed_to);
		$comment_on = ($commentdata['comment_type'] == '') ? __('Comment on', 'bwp-rc') : __(' to', 'bwp-rc');
		$comment['post_title_attr']	= sprintf('%s %s', ucfirst($commentdata['comment_type'] . $comment_on), esc_attr(__($comment['post_title'])));
		// Author
		$comment['author']	= $commentdata['comment_author'];
		$comment['author_url']	= $commentdata['comment_author_url'];
		$avatar_width = (!empty($this->options['input_gravatar_width'])) ? $this->options['input_gravatar_width'] : $this->options_default['input_gravatar_width'];
		$comment['avatar'] 	= ($this->options['enable_gravatars'] == 'yes') ? get_avatar($commentdata['comment_author_email'], $avatar_width, NULL, __('User Avatar', 'bwp-rc')) : '';
		// Links to post or comment page - @since 1.1.0
		$comment['post_link'] = get_permalink($commentdata['comment_post_ID']);
		$comment['link'] 	= get_comment_link($commentdata['comment_ID'], array('type' => 'comment'));
		// format the comment excerpt, let's start by stripping html, shortcodes		
		$comment['excerpt'] = strip_tags(strip_shortcodes($commentdata['comment_content']));
		$chunk = ($this->options['select_long_method'] == 'long_break' && !empty($this->options['input_chunk'])) ? $this->options['input_chunk'] : 0;
		$trimmed_to = (!empty($this->options['input_trim'])) ? $this->options['input_trim'] : $this->options_default['input_trim'];
		$comment['excerpt']	= $this->trim_comment_excerpt($comment['excerpt'], $trimmed_to, $chunk);
		$comment['excerpt']	= ($this->options['enable_smilies'] == 'yes') ? convert_smilies($comment['excerpt']) : $comment['excerpt'];
		// if exerpt is empty after these, add something to it
		$comment_type = (!empty($comment['type'])) ? $comment['type'] : __('comment');
		$comment['excerpt'] = (empty($comment['excerpt'])) ? sprintf($this->options['input_trimmed'], $comment_type) : $comment['excerpt'];
		// since we insert the comment using update_option, we need to stripslashes for the excerpt		
		$comment['excerpt']	= stripslashes($comment['excerpt']);

		return $comment;		
	}

	/**
	 * Sort the comment asc or desc
	 */
	 function sort_comments($data, $order)
	 {
			$comments = array();		
			// ascending order
			if ($order == 'asc')
			{
				foreach ($data as $comment)
				{
					$comments[] = array_pop($data);
				}
			}
			else
				$comments = $data;

			return $comments;
	 }

	/**
	 * Generate the recent comment list
	 */
	 function get_recent_comments($echo = true, $need_refresh = false, $instance = '', $post_type = '', $post_id = NULL, $comment_type = '', $separate = false, $limit = 0, $tb_limit = 0, $order = '', $paged = NULL, $paged_limit = 5)
	 {
	 	// $bwp_rc_left - determine how many recent comments left - @since 1.1.0
		global $wpdb, $bwp_rc_left;

		$bwp_rc_left = 0;

		$credit = ($this->is_credit_enable() && !defined('BWP_RC_CREDIT_ADDED')) ? "\n" . '<li class="recent-comment recent-comment-credit">' . sprintf(BWP_RC_COPYRIGHT, BWP_RC_PLUGIN_URL) . '</li>' . "\n" : '';
		// make sure we add credit only once
		if (!defined('BWP_RC_CREDIT_ADDED'))
			define('BWP_RC_CREDIT_ADDED', true);
		
		// Comment type alias
		$comment_alias = array(
			'comment' => __('comment'),
			'' => __('comment'),
			'tb' => __('trackback'),
			'all' => __('comment')
		);
		
		if (!isset($comment_alias[$comment_type])) $comment_type = '';
		
		$parameters = array('post_type' => $post_type, 'post_id' => $post_id, 'comment_type' => $comment_type, 'separate' => $separate, 'limit' => $limit, 'tb_limit' => $tb_limit, 'order' => $order);

		// Determine whether this is a global list or a unique list
		$instance = $this->format_instance_name($instance);
		$the_instance = $instance;
		if (!empty($instance))
			$instance = 'bwp_rc_instance_' . str_replace(' ', '_', $instance);
		else
			$instance = BWP_RC_LIST;

		// If the instance is not defined or missing a new parameter, add it
		if (!isset($this->instances[$instance]) || !array_key_exists('post_id', $this->instances[$instance]))
		{
			$this->instances[$instance] = $parameters;
			update_option(BWP_RC_INSTANCES, $this->instances);
		}
		
		if (!empty($this->instances[$instance]))
			$saved_parameters = $this->instances[$instance];
		else
			$saved_parameters = $parameters;

		// No need to refresh the comment list?
		if (!$need_refresh)
		{
			$output = '';		
			// check the cache
			$bwp_cached = get_option($instance);

			if (is_array($bwp_cached))
			{
				// if the instance is defined, update its parameters only one time
				// do not update if only change comment_type, order, separate
				$diff = array_diff_assoc($this->instances[$instance], $parameters);
				// never update global list
				$updated_constant = 'UPDATED_' . strtoupper($the_instance);
				if (!defined($updated_constant) && $instance != BWP_RC_LIST && sizeof($diff) > 0 && (sizeof($diff) > 3 || (isset($diff['post_type']) || isset($diff['post_id']) || isset($diff['limit']) || isset($diff['tb_limit']) || isset($diff['separate']))))
				{
					$this->instances[$instance] = $parameters;
					update_option(BWP_RC_INSTANCES, $this->instances);
					$output = $this->get_recent_comments(false, true, $the_instance, $saved_parameters['post_type'], $saved_parameters['post_id'], $comment_type, $saved_parameters['separate'], $saved_parameters['limit'], $saved_parameters['tb_limit'], $order);
				}
				else
				{
					// Get the output out of cache
					$saved_output = $bwp_cached;
					if (sizeof($saved_output) == 0)
						$output = $this->get_recent_comments(false, true, $the_instance, $saved_parameters['post_type'], $saved_parameters['post_id'], $comment_type, $saved_parameters['separate'], $saved_parameters['limit'], $saved_parameters['tb_limit'], $order);
					else
					{
						if ('asc' == $order)
							$saved_output = $this->sort_comments($saved_output, 'asc');
						foreach ($saved_output as $key => $comment)
						{
							if ((true == $saved_parameters['separate'] || ($instance == BWP_RC_LIST && $this->options['select_output_method'] == 'all_sep_comments')) && !$this->is_comment_showable($comment_type, $comment))
								continue;
							// Paginated comment list - @since 1.1.0
							if (isset($paged))
							{
								$start_page = ($paged - 1) * $paged_limit;
								if ($key < $start_page)
									continue;
								$bwp_rc_left++;
								if ($paged_limit < $bwp_rc_left)
									break;
								$output .= $this->generate_a_comment($comment, 'paged');
							}
							else
								$output .= $this->generate_a_comment($comment);
						}
					}
				}
				
				if (!defined($updated_constant))
					define($updated_constant, true);

				// Show no comment found message, make sure dynamic comment_type works
				if (empty($output))
					$output = sprintf($this->options['input_no_found'], $comment_alias[$comment_type]);

				if ($echo)
					echo $output . $credit;
				else 
					return $output . $credit;
				
				return;
			}
		}

		$returned_output = '';

		// @since 1.0.1
		if (!is_array($this->instances))
			$this->reset_instances();

		// We need to refresh the list so we will refresh all instances (if needed)
		foreach ($this->instances as $instance_name => $instance_data)
		{
		if ($instance_name == $instance || empty($the_instance))
		{
			$limit = (empty($instance_data['limit'])) ? $this->options['input_comments'] : $instance_data['limit'];
			$tb_limit = (empty($instance_data['tb_limit'])) ? $this->options['input_tbs'] : $instance_data['tb_limit'];
			$instance_data['order'] = (empty($instance_data['order'])) ? '' : $instance_data['order'];
			$order = (!empty($order)) ? $order : $instance_data['order'];
			$order = (!empty($order)) ? $order : $this->options['select_order'];
			$comment_type = (!empty($instance_data['comment_type'])) ? $instance_data['comment_type']: '';
			$post_type = (!empty($instance_data['post_type'])) ? $instance_data['post_type'] : NULL;
			// @since 1.1.0
			$post_id = (!empty($instance_data['post_id'])) ? $instance_data['post_id'] : NULL;
			$separate = (empty($instance_data['separate'])) ? false : $instance_data['separate'];
			$separate = (!empty($tb_limit) && ($separate == true || ($instance == BWP_RC_LIST && $this->options['select_output_method'] == 'all_sep_comments'))) ? true : false;
			$output = '';
			$saved_output = array();						

			// build the query
			// if we are separating, we will have to do another query, the first one will have to be comment type only
			if ($separate == true)
			{
				$comment_type_sql = "AND comment_type = ''";
			}
			else
			{
			if (empty($comment_type))
			{
				if ($this->options['select_output_method'] == 'only_comments') 
					$comment_type_sql = "AND comment_type = ''";
				else if ($this->options['select_output_method'] == 'all_comments') 
					$comment_type_sql = '';
			}
			else
			{
				$comment_type_sql 	= '';
				$comment_type_sql 	= ($comment_type == 'comment') ? "AND comment_type = ''" : $comment_type_sql;
				$comment_type_sql 	= ($comment_type == 'tb') ? "AND comment_type = 'trackback' OR comment_type = 'pingback'" : $comment_type_sql;				
			}
			}
			
			// Paged recent comment lists? - @since 1.1.0
			$paged_start		= (isset($paged)) ? ((int) $paged - 1)  * $paged_limit : NULL;
			$paged_limit_sql	= (isset($paged_start)) ? $paged_start . ', ' . ($paged_limit + 1) : '';
			$limit_sql			= (!empty($paged_limit_sql)) ? $paged_limit_sql : $limit;

			$post_type_sql		= (!empty($post_type)) ? $wpdb->prepare('AND wpposts.post_type = %s', $post_type) : '';
			// Get comments from a specific post? - @since 1.1.0
			$post_id_sql		= '';
			if (isset($post_id))
			{
				$post_id_cast = (int) $post_id;
				$post_id_sql = (0 == $post_id_cast) ? $wpdb->prepare('AND wpposts.post_name = %s', $post_id) :  $wpdb->prepare('AND wpposts.ID = %d', $post_id);
			}

			// No comments from some specific user ids? - @since 1.1.0
			$user_ids			= (!empty($this->options['input_ignore_ids'])) ? preg_replace('/\s/iu', '', $this->options['input_ignore_ids']) : '';
			$owner_sql			= (!empty($user_ids)) ? "AND user_id NOT IN ($user_ids)" : '';

			$bwp_query 	= 'SELECT *
						FROM ' . $wpdb->comments . ' wpcoms
							INNER JOIN ' . $wpdb->posts . ' wpposts
								ON (wpcoms.comment_post_ID = wpposts.ID' . "
									AND wpposts.post_status <> 'trash'"
								. $post_type_sql
								. $post_id_sql . ')
						WHERE comment_approved = 1 '
						 	. $comment_type_sql
							. $owner_sql . '
							ORDER BY comment_date DESC
							LIMIT ' . $limit_sql;
			// do the query	
			$bwp_qobj 	= $wpdb->get_results($bwp_query, ARRAY_A);
			// sort the first result set
			/*$bwp_qobj	= $this->sort_comments($bwp_qobj, $order);*/

			// if we are separating, do the second query here
			if ($separate == true)
			{
				$comment_type_sql = "AND comment_type = 'trackback' OR comment_type = 'pingback'";
				$disable_own_tb_sql = ($this->options['disable_own_tb'] == 'yes') ? "AND comment_author_url NOT LIKE '" . get_option('home') . "%'" : '';				
				$bwp_query 	= 'SELECT *
						FROM ' . $wpdb->comments . ' wpcoms
							INNER JOIN ' . $wpdb->posts . ' wpposts
								ON (wpcoms.comment_post_ID = wpposts.ID' . "
									AND wpposts.post_status <> 'trash'"
								. $post_type_sql
								. $post_id_sql . ')
						WHERE comment_approved = 1 '
						 	. $comment_type_sql
							. $disable_own_tb_sql . '
							ORDER BY comment_date DESC
							LIMIT ' . $tb_limit;
				// do the query
				$bwp_qobj_tb = $wpdb->get_results($bwp_query, ARRAY_A);
				// sort the second result set
				/*$bwp_qobj_tb = $this->sort_comments($bwp_qobj_tb, $order);*/
				// merge the two query results
				$bwp_qobj = array_merge($bwp_qobj, $bwp_qobj_tb);
			}

			if (!is_array($bwp_qobj))
				continue;
			
			$comments = $bwp_qobj;			

			foreach ($comments as $key => $commentdata)
			{
				// format the comment
				$comment = $this->format_comment($commentdata);
				$saved_output[] = $comment;
				// dynamically manipulate saved output without affecting the cache
				if ($separate == true && !$this->is_comment_showable($comment_type, $commentdata))
					continue;
				// Paginated comment list - @since 1.1.0
				if (isset($paged))
				{
					/*$start_page = ($paged - 1) * $paged_limit;
					if ($key < $start_page)
						continue;*/
					$bwp_rc_left++;
					if ($paged_limit < $bwp_rc_left)
						break;
					$output .= $this->generate_a_comment($comment, 'paged');
				}
				else
					$output .= $this->generate_a_comment($comment);
				unset($comment);
			}						
	
			update_option($instance_name, $saved_output);
			
			// Show no comment found message, make sure dynamic comment_type works
			if (sizeof($saved_output) == 0 || empty($output))
				$output = sprintf($this->options['input_no_found'], $comment_alias[$comment_type]);		
			
			$return_output = '';
			if ($instance_name == $instance)
				$return_output = $output;
		}
		}

		if ($echo)
			echo $return_output . $credit;
		else 
			return $return_output. $credit;
	}
	
	/**
	 * Update the comment list if the new comment is approved
	 */
	function comment_before_add($comment_ID, $approved)
	{
		if ($approved)
		{
			/*$commentdata = get_comment($comment_ID, ARRAY_A);
			$this->add_new_comment($commentdata);*/
			$this->clear_recent_comment_cache();
		}
	}

	function clear_recent_comment_cache()
	{
		$this->get_recent_comments(false, true);
	}
}
?>