<?php
/*
Plugin Name: Iterative Headline Testing Tool
Plugin URI: http://toolkit.iterative.ca/headlines/
Description: Test your post titles and headlines with state-of-the-art artificial intelligence. This plugin uses an externally hosted API that collects information about how your users interact with your posts and the content of your headlines. All data is used solely in aggregate and for the purpose of optimizing your site.
Author: Iterative Research Inc.
Version: 1.0
Author URI: mailto:joe@iterative.ca
License: GPLv2+
*/

global $iterative_disable_title_filter;
$iterative_disable_title_filter = false;

require_once dirname(__FILE__) . "/headlines_api.php";
require_once dirname(__FILE__) . "/headlines_pointer.php";
require_once dirname(__FILE__) . "/headlines_options.php";
require_once dirname(__FILE__) . "/headlines_calculator.php";

define("ITERATIVE_GOAL_CLICKS", 1);
define("ITERATIVE_GOAL_COMMENTS", 4);

/* ====================================================
 * HOOKS AND FILTERS
 * ==================================================== */

add_action( 'admin_menu', 'iterative_add_admin_menu' );
add_action( 'admin_init', 'iterative_settings_init' );
add_filter( 'enter_title_here', function() { return __("Enter primary title here"); });
add_action( 'edit_form_before_permalink', "iterative_add_headline_variants");
add_action( 'save_post', 'iterative_save_headline_variants', 10, 3 );
add_filter( 'the_title', 'iterative_change_title', 10, 2 );
add_action( 'wp_footer', 'iterative_add_javascript', 25 );	// must be higher than 20

add_action('init', 'iterative_start_session', 1);
add_action('wp_logout', 'iterative_end_session');
add_action('wp_login', 'iterative_end_session');


/* ====================================================
 * SESSION PREPARATION
 * ==================================================== */

function iterative_start_session() {
	ob_start();
    if(!session_id()) {
        session_start();
    }
}

function iterative_end_session() {
    session_destroy ();
}

/* ====================================================
 * FILTERING AND TRACKING CODE
 * ==================================================== */
function iterative_change_title( $title, $id = null ) {
	// look up the iterative_post_title_variants and pick the right one.
	if(is_admin()) return $title;
	$settings = get_option("iterative_settings");
	if(!isset($settings['headlines']['testing']) || $settings['headlines']['testing'] == 1) {}
	else { return $title; }

	global $iterative_disable_title_filter;
	if($iterative_disable_title_filter)
		return $title;

	$variants = iterative_get_variants($id);
	if($variants === null)
		return $title;

	if(count($variants) <= 1)
		return $title;

	$selected = IterativeAPI::selectVariant($id, array_keys($variants));
	if($selected === null)
		return $title;

    return $variants[$selected];
}	

function iterative_get_variants($post_id) {
	global $iterative_disable_title_filter;
	$iterative_disable_title_filter = true;
	
	$ptv = get_post_meta( $post_id, 'iterative_post_title_variants', true);
	$title = get_the_title($post_id);

	$iterative_disable_title_filter = false;

	$ptv[] = $title;
	$return = [];
	foreach($ptv as $entry) {
		if($entry == "") continue;
		$return[md5($entry)] = $entry;
	}

	return $return;
}

function iterative_add_javascript() {
	if(!is_admin()) {
		echo "<script type='text/javascript' src='" . IterativeAPI::getTrackerURL() . "'></script>";

		// record a click conversion
		
		if(is_single() && parse_url(wp_get_referer(), PHP_URL_HOST)	== parse_url($_SERVER["HTTP_HOST"], PHP_URL_HOST)) {
			global $post;
			$id = $post->ID;
			$variants = iterative_get_variants($id);

			$variant = IterativeAPI::selectVariant($id, array_keys($variants));
			if(count($variants) <= 1) {
				echo "<script type='text/javascript' src='" . IterativeAPI::getSuccessURL(ITERATIVE_GOAL_CLICKS, $variant, $id) . "'></script>";
			}
		}

		if(isset($_SESSION['iterative_comments_posted'])) {
			// if we just posted a comment... tell someone which variant we succeeded
			foreach(array_unique($_SESSION['iterative_comments_posted']) as $post_id) {
				$variants = iterative_get_variants($post_id);
				if(count($variants) <= 1) 
					continue; // we're not testing on this post.
				
				$variant = IterativeAPI::selectVariant($post_id, array_keys($variants));
				echo "<script type='text/javascript' src='" . IterativeAPI::getSuccessURL(ITERATIVE_GOAL_COMMENTS, $variant, $post_id) . "'></script>";
			}
			unset($_SESSION['iterative_comments_posted']);
		}
	}
}

add_filter('preprocess_comment', function($comment) {
	if(isset($_SESSION['iterative_comments_posted'])) {
		$_SESSION['iterative_comments_posted'][] = $comment['comment_post_ID'];
	} else {
		$_SESSION['iterative_comments_posted'] = array($comment['comment_post_ID']);
	}
	return $comment;
});


/* ====================================================
 * EDIT POST PAGE CHANGES
 * Relevant hooks from wp-admin/edit-form-advanced.php
 * 	$title_placeholder = apply_filters( 'enter_title_here', __( 'Enter title here' ), $post );
 * 	do_action( 'edit_form_before_permalink', $post );
 * ==================================================== */
function iterative_save_headline_variants( $post_id, $post, $update ) {
    if ( isset( $_REQUEST['iterative_post_title_variants'] ) ) {
    	$result = array();
    	foreach($_REQUEST['iterative_post_title_variants'] as $iptv) {
    		$iptv = trim($iptv);
    		if($iptv == '')
    			continue;
    		$result[] = $iptv;
    	}
    	
    	if(!empty($result))
        	update_post_meta( $post_id, 'iterative_post_title_variants', $result); // ( $_REQUEST['iterative_post_title_variants'] ) );
    }

    IterativeAPI::updateExperiment($post_id, iterative_get_variants($post_id), (array)$post);
}

function iterative_add_headline_variants($post) {
	echo "<style type='text/css'>
	.iterative-headline-variant { 
		padding: 3px 8px;
		padding-left: 34px;
	    font-size: 1.7em;
	    line-height: 100%;
	    height: 1.7em;
	    width: 100%;
	    outline: 0;
	    margin: 0 0 3px;
	    background-color: #fff;
	    background-image:url(" . plugins_url("atom_24.png", __FILE__) . ");
	    background-position: 6px 6px; 
	    background-repeat: no-repeat;
	}</style><script type='text/javascript'>
	jQuery(function() {
		jQuery('.iterative-headline-variant').parent().on('keyup', '.iterative-headline-variant', (function() {
			var empties = false;
			jQuery('.iterative-headline-variant').each(function() {
				if(jQuery(this).val() == '') {
					if(!empties) {
						empties = true;
						return;
					} else {
						jQuery(this).remove();
					}
				}

			});

			if(!empties) {
				if(jQuery('.iterative-headline-variant').length < 9) 
					jQuery('#iterative_first_variant').clone().val('').attr('id', '').insertAfter(jQuery('.iterative-headline-variant:last'));
			}
		}));
	});
	</script>";
	//placeholder="Enter experimental title variant."
	// reload these.
	$ptv = get_post_meta( $post->ID, 'iterative_post_title_variants');
	$ptv = $ptv[0];
	if(is_array($ptv)) {
		foreach($ptv as $p) {
			$p = trim($p);
			if($p == '') continue;
			echo '<input type="text" name="iterative_post_title_variants[]" size="30" value="' . $p . '" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';	
		}
	}
	echo '<input type="text" id="iterative_first_variant"  name="iterative_post_title_variants[]" size="30" value="" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';
}
