<?php
/*
Plugin Name: Twitter Tools - Search 
Plugin URI: http://crowdfavorite.com/wordpress/ 
Description:  
Version: 2.1 
Author: Crowd Favorite
Author URI: http://crowdfavorite.com
*/

/*

@done

- support multiple search terms @done
	- fields: slug, search term @done
	- make slugs unique @done
	- sanitize_title on slugs @done
	- save to options table as serialized array @done
	- add form to admin UI @done
- create table to store search results @done
- check for search on twitter user check 'aktt_update_tweets' @done
- download search tweets @done
- add search tweets to db @done
- function to retrieve matching searches, order by date
- function to output list of tweets
- AJAX request handler @done
- JS to request AJAX @done
- create a widget @done

@test

@todo

- paginate displays
	- spinner image
- jump to search form and show success banner after saving

@future

- style admin fields better
- JS for repeater in admin UI


*/


// ini_set('display_errors', '1'); ini_set('error_reporting', E_ALL);

if (!defined('PLUGINDIR')) {
	define('PLUGINDIR','wp-content/plugins');
}

load_plugin_textdomain('twitter-tools-search');

if (is_file(trailingslashit(ABSPATH.PLUGINDIR).basename(__FILE__))) {
	define('AKTT_SEARCH_FILE', trailingslashit(ABSPATH.PLUGINDIR).basename(__FILE__));
}
else if (is_file(trailingslashit(ABSPATH.PLUGINDIR).basename(dirname(__FILE__)).'/'.basename(__FILE__))) {
	define('AKTT_SEARCH_FILE', trailingslashit(ABSPATH.PLUGINDIR).basename(dirname(__FILE__)).'/'.basename(__FILE__));
}

define('AKTT_SEARCH_API', 'http://search.twitter.com/search.json?q=%s');

register_activation_hook(AKTT_SEARCH_FILE, 'aktt_search_install');

function aktt_search_install() {
	global $wpdb;
	$wpdb->aktt_search = $wpdb->prefix.'ak_twitter_search';
	$charset_collate = '';
	if ( version_compare(mysql_get_server_info(), '4.1.0', '>=') ) {
		if (!empty($wpdb->charset)) {
			$charset_collate .= " DEFAULT CHARACTER SET $wpdb->charset";
		}
		if (!empty($wpdb->collate)) {
			$charset_collate .= " COLLATE $wpdb->collate";
		}
	}
	$result = $wpdb->query("
		CREATE TABLE `$wpdb->aktt_search` (
		`id` INT( 11 ) NOT NULL AUTO_INCREMENT PRIMARY KEY ,
		`slug` VARCHAR( 255 ) NOT NULL ,
		`tw_id` VARCHAR( 255 ) NOT NULL ,
		`tw_created_at` DATETIME NOT NULL ,
		`tw_text` VARCHAR( 255 ) NOT NULL ,
		`tw_from_user` VARCHAR( 255 ) NOT NULL ,
		`tw_profile_image_url` VARCHAR( 255 ) NOT NULL ,
		`tw_from_user_id` VARCHAR( 255 ) NOT NULL ,
		`tw_to_user_id` VARCHAR( 255 ) NOT NULL ,
		`tw_geo` VARCHAR( 255 ) NOT NULL ,
		`tw_iso_language_code` VARCHAR( 255 ) NOT NULL ,
		`tw_source` VARCHAR( 255 ) NOT NULL ,
		`modified` DATETIME NOT NULL ,
		INDEX ( `tw_id` ),
		INDEX ( `tw_id`, `tw_created_at` )
		) $charset_collate
	");
}

function aktt_search_init() {
	global $wpdb;
	$wpdb->aktt_search = $wpdb->prefix.'ak_twitter_search';
}
add_action('init', 'aktt_search_init');

function aktt_search_get_terms() {
	$terms = get_option('aktt_search_terms');
	if (!is_array($terms)) {
		$terms = array();
	}
	return $terms;
}

function aktt_search_update_tweets() {
	global $wpdb;
	$terms = aktt_search_get_terms();
	foreach ($terms as $slug => $term) {
		$tweets = aktt_search_api_request($term);
		if (isset($tweets->results) && count($tweets->results)) {
			$tweet_ids = array();
			foreach ($tweets->results as $tweet) {
				$tweet_ids[] = $wpdb->escape($tweet->id);
			}
			$existing_ids = $wpdb->get_col("
				SELECT tw_id
				FROM $wpdb->aktt_search
				WHERE tw_id
				IN ('".implode("', '", $tweet_ids)."')
				AND `slug` = '".$wpdb->escape($slug)."'
			");
			foreach ($tweets->results as $tweet) {
				if (!$existing_ids || !in_array($tweet->id, $existing_ids)) {
					aktt_search_insert_tweet($slug, $tweet);
				}
			}
		}
	}
}
add_action('aktt_update_tweets', 'aktt_search_update_tweets');

function aktt_search_api_request($term) {
	$snoop = get_snoopy();
	$snoop->fetch(sprintf(AKTT_SEARCH_API, urlencode($term)));
	if (strpos($snoop->response_code, '200') !== false) {
		if (!class_exists('Services_JSON')) {
			include_once(trailingslashit(ABSPATH).'wp-includes/class-json.php');
		}
		$json = new Services_JSON();
		$tweets = $json->decode($snoop->results);
	}
	else {
		$tweets = array();
	}
	return $tweets;
}

function aktt_search_insert_tweet($slug, $tweet) {
	global $wpdb;
	$tweet = apply_filters('aktt_search_insert_tweet', $tweet); // return false to not insert
	if ($tweet) {
		$wpdb->insert(
			$wpdb->aktt_search,
			array(
				'slug' => $slug,
				'tw_id' => $tweet->id,
				'tw_created_at' => date('Y-m-d H:i:s', strtotime($tweet->created_at)),
				'tw_text' => $tweet->text,
				'tw_from_user' => $tweet->from_user,
				'tw_profile_image_url' => $tweet->profile_image_url,
				'tw_from_user_id' => $tweet->from_user_id,
				'tw_to_user_id' => $tweet->to_user_id,
				'tw_geo' => $tweet->geo,
				'tw_iso_language_code' => $tweet->iso_language_code,
				'tw_source' => $tweet->source,
				'modified' => date('Y-m-d H:i:s')
			)
		);
	}
}

function aktt_search_get_tweets($slug = '', $limit = 10, $offset = 0) {
	global $wpdb;
	$limit = (int) $limit;
	$offset = (int) $offset * $limit;
	$tweets = $wpdb->get_results("
		SELECT *
		FROM $wpdb->aktt_search
		WHERE `slug` = '".$wpdb->escape($slug)."'
		ORDER BY `tw_created_at` DESC
		LIMIT $offset, $limit
	");
	return $tweets;
}

function aktt_search_tweet_list($slug = '', $limit = 10, $page = 0) {
	$tweets = aktt_search_get_tweets($slug, $limit, $page);
	$output = '';
	if (count($tweets)) {
		$output .= apply_filters('aktt_search_tweet_list_start', '<ul class="aktt_search_tweets">');
		foreach ($tweets as $tweet) {
			$output .= aktt_search_tweet_list_item($tweet);
		}
		$output .= apply_filters('aktt_search_tweet_list_end', '</ul>');
	}
	else {
		// If there are no tweets returned
		$output .= apply_filters('aktt_search_tweet_list_start', '<ul class="aktt_search_tweets">');
			$output .= aktt_search_tweet_list_item(null);
		$output .= apply_filters('aktt_search_tweet_list_end', '</ul>');
	}
	return apply_filters('aktt_search_tweet_list', $output, $tweets);
}

function aktt_search_tweet_list_item($tweet = null) {
	// Check if we have no tweet
	if (is_null($tweet)) {
		// No results, so output an <li> saying that
		$output = '
<li>
	<p class="aktt_search_tw_body aktt_search_no_tweets">'.__('No tweets', 'twitter-tools').'</p>
</li>
		';
	}
	else {
		$output = '
<li>
	<p class="aktt_search_tw_body">'.aktt_make_clickable(esc_html($tweet->tw_text)).'</p>
	<p class="aktt_search_tw_meta">
		<span class="aktt_search_tw_credit">by</span>
		<span class="aktt_search_tw_user">'.aktt_profile_link(esc_html($tweet->tw_from_user)).'</span>
		<span class="aktt_search_tw_date"><a href="'.aktt_status_url(esc_html($tweet->tw_from_user), $tweet->tw_id).'">'.aktt_relativeTime($tweet->tw_created_at).'</a></span>
	</p>
</li>
		';
	}
	
	return apply_filters('aktt_search_tweet_list_item', $output, $tweet);
}

function aktt_search_tweet_list_paginated($slug = '', $limit = 10) {
	$open = '<div class="aktt_search_tweets_paged">';
	$tweet_list = aktt_search_tweet_list($slug, $limit);
	$pag_buttons = '<p class="aktt_search_pagination"><a href="#" class="aktt_search_next">Next &rarr;</a><a href="#" class="aktt_search_prev">&larr; Prev</a></p>';
	$close = '</div>';
	return apply_filters('aktt_search_tweet_list_paginated_open', $open).
		$tweet_list.
		apply_filters('aktt_search_tweet_list_paginated_buttons', $pag_buttons).
		'<span class="page" title="0"></span>'.
		'<span class="limit" title="'.intval($limit).'"></span>'.
		'<span class="slug" title="'.esc_attr($slug).'"></span>'.
		apply_filters('aktt_search_tweet_list_paginated_close', $close);
}

function aktt_search_tweet_list_expandable($slug = '', $limit = 10) {
	$open = '<div class="aktt_search_tweets_expand">';
	$tweet_list = aktt_search_tweet_list($slug, $limit);
	$button = '<p class="aktt_search_expand"><a href="#">Expand &darr;</a></p>';
	$close = '</div>';
	return apply_filters('aktt_search_tweet_list_expand_open', $open).
		$tweet_list.
		apply_filters('aktt_search_tweet_list_expand_buttons', $button).
		'<span class="page" title="0"></span>'.
		'<span class="limit" title="'.intval($limit).'"></span>'.
		'<span class="slug" title="'.esc_attr($slug).'"></span>'.
		apply_filters('aktt_search_tweet_list_expand_close', $close);
}

function aktt_search_request_handler() {
	if (!empty($_GET['cf_action'])) {
		switch ($_GET['cf_action']) {
			case 'aktt_search_js':
				aktt_search_js();
				break;
			case 'aktt_search_tweets_page':
				if (!empty($_GET['slug']) && !empty($_GET['limit']) && isset($_GET['page'])) {
					echo aktt_search_tweet_list(sanitize_title(stripslashes($_GET['slug'])), intval($_GET['limit']), intval($_GET['page']));
					die();
				}
				break;
		}
	}
	if (!empty($_POST['cf_action'])) {
		switch ($_POST['cf_action']) {
			case 'aktt_search_update_settings':
				aktt_search_save_settings();
				wp_redirect(admin_url('options-general.php?page=twitter-tools.php&updated=true'));
				die();
				break;
		}
	}
}
add_action('init', 'aktt_search_request_handler');

function aktt_search_js() {
	header('Content-type: text/javascript');
?>
jQuery(function($) {
	$('.aktt_search_tweets_paged').each(function() {
		var form = $(this);
		
		// Grab the page number we arrived on
		var page = parseInt($(this).find('.page').attr('title'), 10);

		// See if we should hide the "Next" button
		if (form.find('.aktt_search_no_tweets').length == 1) {
			// No more tweets, so hide the next button
			form.find('.aktt_search_next').hide();
		}

		// hide prev link
		$(this).find('.aktt_search_prev').hide();
		
		// Set up our click events for the next and previous links
		$(this).find('.aktt_search_next, .aktt_search_prev').click(function() {
			
			// If we're on the "next" link, increment the page num, otherwise decrement.
			if ($(this).attr('class').indexOf('aktt_search_next') != -1) {
				page++;
			}
			else {
				page--;
			}
			
			// set page num, remove tweet list, add spinner
			form.find('.page').attr('title', page).end().find('ul.aktt_search_tweets').after('<p class="aktt_search_spinner">Loading...</p>').remove();
			
			// Make our request
			$.get(
				'<?php echo site_url('index.php'); ?>',
				{
					cf_action: "aktt_search_tweets_page",
					page: page,
					limit: form.find('.limit').attr('title'),
					slug: form.find('.slug').attr('title')
				},
				function(response) {

					// load new page data, remove spinner
					form.find('.aktt_search_spinner').after(response).remove();
					
					// show/hide previous link
					switch (form.find('.page').attr('title')) {
						case '0':
							form.find('.aktt_search_prev').hide();
							break;
						default:
							form.find('.aktt_search_prev').show();
							break;
					}
					
					// show/hide next link
					switch (form.find('.aktt_search_no_tweets').length) {
						case 1:
							// No more tweets, so hide the next button
							form.find('.aktt_search_next').hide();
							break;
						default:
							form.find('.aktt_search_next').show();
							break;
					} 
					
					
				},
				'html'
			);
			return false;
		});
	});
	$('.aktt_search_tweets_expand').each(function() {
		var form = $(this);
		
		// Grab the page number we arrived on
		var page = parseInt($(this).find('.page').attr('title'), 10);

		// See if we should hide the "Next" button
		if (form.find('.aktt_search_no_tweets').length == 1) {
			// No more tweets, so hide the next button
			form.find('.aktt_search_next').hide();
		}

		// hide prev link
		form.find('.aktt_search_prev').hide();
		
		// Set up our click events for the next and previous links
		form.find('.aktt_search_expand a').click(function() {
			
			$(this).hide();
			
			// increment
			page++;
			
			// set page num, remove tweet list, add spinner
			form.find('.page').attr('title', page).end().find('ul.aktt_search_tweets:last').after('<p class="aktt_search_spinner">Loading...</p>');
			
			// Make our request
			$.get(
				'<?php echo site_url('index.php'); ?>',
				{
					cf_action: "aktt_search_tweets_page",
					page: page,
					limit: form.find('.limit').attr('title'),
					slug: form.find('.slug').attr('title')
				},
				function(response) {

					// load new page data, remove spinner
					form.find('.aktt_search_spinner').after('<div style="display: none;" class="aktt_new_tweets">' + response + '</div>').remove();
					
					form.find('.aktt_new_tweets').slideDown(function() {
						$(this).removeClass('aktt_new_tweets');
					});
					
					// show expand link
					if (form.find('.aktt_search_no_tweets').length != 1) {
						form.find('.aktt_search_expand a').show();
					}
					
				},
				'html'
			);
			return false;
		});
	});
});
<?php
	die();
}
wp_enqueue_script('aktt_search_js', trailingslashit(get_bloginfo('url')).'?cf_action=aktt_search_js', array('jquery'));

function aktt_search_edit_fields($slug = '', $term = '', $i = 0) {
	$i = (int) $i;
	return '
		<p class="aktt_search_fields">
			<span class="aktt_search_fields_block">
				<label>'.__('Key', 'twitter-tools-search').'</label>
				<input type="text" name="aktt_search_terms['.$i.'][slug]" value="'.esc_attr($slug).'" />
			</span>
			<span class="aktt_search_fields_block">
				<label>'.__('Search Term', 'twitter-tools-search').'</label>
				<input type="text" name="aktt_search_terms['.$i.'][term]" value="'.esc_attr($term).'" />
			</span>
		</p>
	';
}

function aktt_search_settings_form() {
	print('
<div class="wrap">
	<h2>'.__('Searches', 'twitter-tools-search').'</h2>
	<form id="aktt_search_settings_form" name="aktt_search_settings_form" action="'.get_bloginfo('wpurl').'/wp-admin/options-general.php" method="post">
		<input type="hidden" name="cf_action" value="aktt_search_update_settings" />
		<fieldset class="options">
	');
	$terms = aktt_search_get_terms();
	$i = 0;
	if (count($terms)) {
		foreach ($terms as $slug => $term) {
			echo aktt_search_edit_fields($slug, $term, $i);
			$i++;
		}
	}
	echo aktt_search_edit_fields('', '', $i);
	print('
		</fieldset>
		<p class="submit">
			<input type="submit" name="submit" value="'.__('Save Settings', 'twitter-tools-search').'" class="button-primary" />
		</p>
	</form>
</div>
	');
}
add_action('aktt_options_form', 'aktt_search_settings_form');

function aktt_search_save_settings() {
	if (!current_user_can('manage_options')) {
		return;
	}
	$terms = array();
	if (isset($_POST['aktt_search_terms']) && is_array($_POST['aktt_search_terms']) && count($_POST['aktt_search_terms'])) {
		foreach ($_POST['aktt_search_terms'] as $search) {
			if (!empty($search['term'])) {
// sanitize and uniqify slugs
				$slug = sanitize_title($search['slug']);
				if (isset($terms[$slug])) {
					for ($i = 1; $i < 9999; $i++) {
						$uslug = $slug.'-'.$i; 
						if (!isset($terms[$uslug])) {
							$slug = $uslug;
							break;
						}
					}
				}
// reformat array
				$terms[$slug] = stripslashes($search['term']);
			}
		}
	}
	update_option('aktt_search_terms', $terms);
}

/* Register a widget for the twitter tools search feed*/
class Twitter_Tools_Search_Feed extends WP_Widget {

	function Twitter_Tools_Search_Feed() {
		$widget_ops = array('classname' => 'aktt_widget', 'description' => __('Twitter Tools Search Widget', 'twitter-tools'));
		$this->WP_Widget('widget-aktt-search-feed', __('Twitter Tools Search Feed', 'twitter-tools'), $widget_ops, $control_ops);
	}

	function widget( $args, $instance ) {
		extract($args);
		
		/* Relies on twitter tools */
		if (!function_exists('aktt_search_tweet_list')) { return; }
		
		/* Put tweet list into var */
		$count = (empty($instance['count'])) ? null : (int) $instance['count'];

		switch ($instance['paginate']) {
			case 'true':
				$tweet_list = aktt_search_tweet_list_paginated($instance['slug'], $count);
				break;
			case 'expand':
				$tweet_list = aktt_search_tweet_list_expandable($instance['slug'], $count);
				break;
			default:
				$tweet_list = aktt_search_tweet_list($instance['slug'], $count);
				break;
		}
		
		// Initialize our output string for the content of the widget 
		$output = '';
		
		/* Make sure we have tweets to output */
		if (!empty($tweet_list)) {
			
			/* Output widget now */
			echo $before_widget;
			
				/* See if a title's set, output if it is */
				if ($instance['title']) {
					echo $before_title . $instance['title'] . $after_title;
				}
		
			
				$output .= '<div class="aktt_search_tweets">';
					$output .= $tweet_list;
				$output .= '</div><!-- /aktt_search_tweets -->';
				
				echo apply_filters('aktt_search_tweet_list_output', $output, $tweet_list, $instance);
		
			/* Close out our widget */
			echo $after_widget;
		}
	}
	
	function form( $instance ) {
		/* Give it some defaults */
		$defaults = array(
			'title' => '',
			'count' => 4,
			'slug' => '-1',
			'paginate' => "true"
		);
		$instance = wp_parse_args( (array) $instance, $defaults );
		
		/* Get into nice vars */
		$title = strip_tags($instance['title']);
		$count = intval($instance['count']);
		$slug = sanitize_title($instance['slug']);
		$paginate = sanitize_title($instance['paginate']);

		/* Assemble our options for the select box */
		$default_option = array('-1' => '&mdash; ' . __('Please Select', 'twitter-tools') . ' &mdash;');
		$avail_terms = aktt_search_get_terms(); // We're sure to get an array
		$term_options = array_merge($default_option, $avail_terms);
?>
			<!-- Title -->
			<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo esc_attr($title); ?>" />
			</p>
			
			<!-- Search Term -->
			<p>
			<label for="<?php echo $this->get_field_id('slug'); ?>"><?php _e('Search Term:'); ?></label>
			<select name="<?php echo $this->get_field_name('slug'); ?>" id="<?php echo $this->get_field_id('slug'); ?>" style="width:225px;">
<?php
		foreach ($term_options as $key => $term) {
			echo '<option value="' . esc_attr($key) . '"';
			selected($key, $slug);
			echo '>' . esc_html($term) . '</option>';
		}
?>
				<?php echo $term_options_html; ?>
			</select>
			</p>
			
			<!-- # to Display -->
			<p>
			<label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('# to Display:'); ?></label>
			<input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo esc_attr($count); ?>" />
			</p>
			
			<!-- Should we paginate? -->
			<p>
			<label for="<?php echo $this->get_field_name('paginate'); ?>"><?php _e('Paginate Results?'); ?></label>
			<select name="<?php echo $this->get_field_name('paginate'); ?>" id="<?php echo $this->get_field_name('paginate'); ?>" >
				<option value="false" <?php selected($paginate, 'false'); ?>><?php _e('No', 'twitter-tools'); ?></option>
				<option value="true" <?php selected($paginate, 'true'); ?>><?php _e('Yes', 'twitter-tools'); ?></option>
				<option value="expand" <?php selected($paginate, 'expand'); ?>><?php _e('Yes (with expansion)', 'twitter-tools'); ?></option>
			</select>
			</p>
			
			<!-- Link to settings page -->
			<p>
			<?php _e('Find additional Twitter Tools options on the <a href="options-general.php?page=twitter-tools.php">Twitter Tools Options page</a>', 'twitter-tools'); ?>
			</p>
<?php
	}

}
add_action('widgets_init', create_function('', "register_widget('Twitter_Tools_Search_Feed');"));

if (!function_exists('get_snoopy')) {
	function get_snoopy() {
		include_once(ABSPATH.'/wp-includes/class-snoopy.php');
		return new Snoopy;
	}
}

//a:22:{s:11:"plugin_name";s:22:"Twitter Tools - Search";s:10:"plugin_uri";N;s:18:"plugin_description";N;s:14:"plugin_version";s:6:"2.1dev";s:6:"prefix";s:11:"aktt_search";s:12:"localization";s:20:"twitter-tools-search";s:14:"settings_title";s:6:"Search";s:13:"settings_link";N;s:4:"init";s:1:"1";s:7:"install";s:1:"1";s:9:"post_edit";b:0;s:12:"comment_edit";b:0;s:6:"jquery";b:0;s:6:"wp_css";b:0;s:5:"wp_js";s:1:"1";s:9:"admin_css";b:0;s:8:"admin_js";b:0;s:15:"request_handler";b:0;s:6:"snoopy";s:1:"1";s:11:"setting_cat";b:0;s:14:"setting_author";b:0;s:11:"custom_urls";b:0;}


?>