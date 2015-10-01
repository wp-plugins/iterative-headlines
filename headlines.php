<?php
/*
Plugin Name: Viral Headlines&trade;
Plugin URI: http://www.viralheadlines.net/
Description: Test your post titles and headlines with state-of-the-art artificial intelligence. 
Author: Iterative Research Inc.
Version: 1.5.2
Author URI: mailto:joe@iterative.ca
License: GPLv2+
*/

global $iterative_disable_title_filter;
$iterative_disable_title_filter = false;

require_once dirname(__FILE__) . "/headlines_advice.php";
require_once dirname(__FILE__) . "/headlines_api.php";
require_once dirname(__FILE__) . "/headlines_pointer.php";
require_once dirname(__FILE__) . "/headlines_options.php";
require_once dirname(__FILE__) . "/headlines_messages.php";
require_once dirname(__FILE__) . "/headlines_functions.php";
require_once dirname(__FILE__) . "/headlines_calculator.php";

define("ITERATIVE_HEADLINES_BRANDING", "Viral Headlines&trade;");
define("ITERATIVE_GOAL_CLICKS", 1);
define("ITERATIVE_GOAL_TIMEONSITE", 2);
define("ITERATIVE_GOAL_COMMENTS", 4);
define("ITERATIVE_GOAL_ADCLICKS", 5);
define("ITERATIVE_GOAL_RETURN", 6);
define("ITERATIVE_GOAL_SCROLL", 7);
define("ITERATIVE_GOAL_VIRALITY", 8);
define("ITERATIVE_GET_PARAMETER", "ivh");
define("ITERATIVE_ENABLE_GET_PARAMETER", false);

/* ====================================================
 * HOOKS AND FILTERS
 * ==================================================== */

add_action( 'admin_menu', 'iterative_add_admin_menu' );
add_action( 'admin_init', 'iterative_settings_init' );
function iterative_title_override_text() { 
	return __("Enter primary headline here"); 
};
add_filter( 'enter_title_here', "iterative_title_override_text");
add_action( 'edit_form_before_permalink', "iterative_add_headline_variants");
add_action( 'save_post', 'iterative_save_headline_variants', 10, 3 );
add_filter( 'the_title', 'iterative_change_headline', 10, 2 );
add_filter( 'post_link', 'iterative_append_feed_string', 11, 3 );
add_action( 'wp_footer', 'iterative_add_javascript', 25 );	// must be higher than 20

add_action('init', 'iterative_start_session', 1);
add_action('wp_logout', 'iterative_end_session');
add_action('wp_login', 'iterative_end_session');


/* ====================================================
 * SESSION PREPARATION
 * ==================================================== */

global $iterative_prevariants;
$iterative_prevariants = null;
function iterative_start_session() {
	global $iterative_prevariants;
	$iterative_prevariants = IterativeAPI::getStoredVariants();

	ob_start();
	if(!session_id()) {
		@session_start();
	}
}

function iterative_end_session() {
	session_destroy ();
}

add_filter('query_vars', 'iterative_query_vars' );
function iterative_query_vars( $qvars ){
    //Add query variable to $qvars array
    $qvars[] = ITERATIVE_GET_PARAMETER; 
    return $qvars;
}


add_action( 'admin_notices', 'iterative_admin_notices' );
global $iterative_notices;
$iterative_notices = array();
function iterative_admin_notices() {
	return;	// for now, we can just output these immediately.

	global $iterative_notices;
	foreach($iterative_notices as $in) {
	?>
		<div class="updated">
		<p><?php _e( $in ); ?></p>
		</div>
	<?php
	}
}

/* ====================================================
 * FILTERING AND TRACKING CODE
 * ==================================================== */
global $iterative_selected;
$iterative_selected = array();
function iterative_change_headline($title, $id=null) {
	// why is ID null? it cannot be null.
	if($id === null) {
		global $post;
		$id = $post->ID;
	}

	return iterative_change_thing($title, $id, "headlines");
}

function iterative_change_thing( $original_thing, $id = null, $experiment_type="headlines", $model_type=null) {
	global $iterative_disable_title_filter;
	
	if(is_admin() || $iterative_disable_title_filter) return $original_thing;
	$settings = get_option("iterative_settings");
	if(!isset($settings['headlines']['testing']) || $settings['headlines']['testing'] == 1) {}
	else { return $original_thing; }
	
	$variants = iterative_get_variants($id, $experiment_type);
	if($variants === null || count($variants) <= 1)
		return $original_thing;

	$selected = null;
	$qp = get_query_var(ITERATIVE_GET_PARAMETER);
	if($experiment_type === "headlines" && !empty($qp)) {
		// TODO: in the future (and pro only, as the benefit requires the pro API), 
		// consider storing extra data in that get parameter like CFs.

		// exit from the URL if available
		if(isset($variants[$qp])) { 
			$selected = $qp; 
		} else {
			unset($_GET[ITERATIVE_GET_PARAMETER]);
		}
	}

	if($selected == null) {
		// look up the _iterative_headline_variants and pick the right one.
		$selected = IterativeAPI::selectVariant($id, array_keys($variants), $experiment_type, $model_type);
		if($selected === null || !isset($variants[$selected]))
			return $original_thing;
	}
	// store here for page load so we don't have to trust the API (in case of fallback mode or temporary model)
	global $iterative_selected;	
	$iterative_selected[$id] = $selected;

	return $variants[$selected];
}	

function iterative_get_variants($post_id, $experiment_type='headlines', $include_default = true) {
	
	if($experiment_type == 'headlines') {
		global $iterative_disable_title_filter;
		$iterative_disable_title_filter = true;
	
		// DEPRECATE: on the first version after August 1 remove this.
		$old = (array) get_post_meta($post_id, "iterative_post_title_variants", true);
		$new = (array) get_post_meta( $post_id, '_iterative_headline_variants', true);	
		$ptv = $new + $old;

		$title = get_the_title($post_id);
		if($include_default)
			$ptv[] = $title;
	
		$iterative_disable_title_filter = false;
	} else {
		$field = substr($experiment_type, 0, strlen($experiment_type)-1);
		$disable_variable = "iterative_disable_" . $field . "_filter";
		global $$disable_variable;
		$$disable_variable = true;
		$methods = array("iterative_get_the_$field", "get_the_$field", "get_$field");
		foreach($methods as $method) {
			if(function_exists($method)) {
				$field_value = $method($post_id);
				break;
			}
		}

		$ptv = get_post_meta($post_id, "_iterative_" . $field . "_variants", true);
		if($include_default)
			if(isset($field_value))
				$ptv[] = $field_value;
		
		$$disable_variable = false;
	}

	$return = array();
	foreach($ptv as $entry) {
		if($entry == "") continue;
		$return[md5($entry)] = $entry;
	}

	return $return;
}

// local means "local to this request", as in, the selected variant is in our list,  null means we're indifferent
function iterative_report_success($type, $id, $local=true) {
	$variants = iterative_get_variants($id); 
	global $iterative_selected;
	if(!isset($iterative_selected[$id]) && $local === true) {
		return; // we specificially want local.
	} else if (isset($iterative_selected[$id]) && $local === false) {
		return;	// we specifically want non-local
	}

	if($local === true) {
		$headline_variant = $iterative_selected[$id];
	} else {
		$headline_variant = IterativeAPI::getVariantForUserID($id);
	}

	if(count($variants) > 0) {
		IterativeAPI::success($type, $headline_variant, $id);
	}

}

function iterative_add_javascript() {
	if(!current_user_can("publish_posts") && !is_admin()) {
	//	echo "<script type='text/javascript' src='" . IterativeAPI::getTrackerURL() . "'></script>";

		// record a click conversion
		global $post;
		$id = $post->ID;
		

		if((iterative_get_referring_host() != iterative_remove_www(parse_url($_SERVER["HTTP_HOST"], PHP_URL_HOST)))) {
			// check if user has a cookie
			// if he does, go through all his posts and mark them as ITERATIVE_GOAL_RETURN for posts that aren't 
			global $iterative_prevariants;
			$stored = $iterative_prevariants;	// all variants that were true at the beginning of the request.
			if($stored !== null) {
				// in fact these are hash partials, but ::success handles that
				foreach($stored['headlines'] as $post_id=>$hash) {
					iterative_report_success(ITERATIVE_GOAL_RETURN, $post_id, null);
				}
			}
		}


		if(is_single()) {
			if((iterative_get_referring_host() == iterative_remove_www(parse_url($_SERVER["HTTP_HOST"], PHP_URL_HOST))))
				iterative_report_success(ITERATIVE_GOAL_CLICKS, $id);
			else if(get_query_var(ITERATIVE_GET_PARAMETER) != "")  // off site referrer but saw fixed title.
				iterative_report_success(ITERATIVE_GOAL_VIRALITY, $id);
		
			if(ITERATIVE_ENABLE_GET_PARAMETER) {
				// set state as a URL parameter.
				echo "<script type='text/javascript'>
				(function() {
					function updateURLParameter(url, param, paramVal){
					    var newAdditionalURL = '';
					    var tempHashArray = url.split('#');
					    var tempArray = tempHashArray[0].split('?');
					    var baseURL = tempArray[0];
					    var additionalURL = tempArray[1];
					    var temp = '';
					    if (additionalURL) {
					        tempArray = additionalURL.split('&');
					        for (i=0; i<tempArray.length; i++){
					            if(tempArray[i].split('=')[0] != param){
					                newAdditionalURL += temp + tempArray[i];
					                temp = '&';
					            }
					        }
					    }

					    var rows_txt = temp + '' + param + '=' + paramVal;
					    var newURL = baseURL + '?' + newAdditionalURL + rows_txt;
					    if(tempHashArray.length > 1) {
					    	newURL += '#' + tempHashArray[1];
					    }
					    return newURL;
					}
					var newURL = updateURLParameter(window.location.href, '" . ITERATIVE_GET_PARAMETER . "', '{$variant}');
					window.history.replaceState({}, '', newURL);
				})();</script>";
			}
		}

		if(isset($_SESSION['iterative_comments_posted'])) {
			// if we just posted a comment... tell someone which variant we succeeded
			foreach(array_unique($_SESSION['iterative_comments_posted']) as $post_id) {
				$variants = iterative_get_variants($post_id);
				if(count($variants) <= 1) 
					continue; // we're not testing on this post.
				
				$variant = IterativeAPI::selectVariant($post_id, array_keys($variants));
				iterative_report_success(ITERATIVE_GOAL_COMMENTS, $post_id);
			}
			unset($_SESSION['iterative_comments_posted']);
		}

		IterativeAPI::outputTrackerJS();
	}
}

add_filter('preprocess_comment', "iterative_preprocess_comment");
function iterative_preprocess_comment($comment) {
        if(!current_user_can("publish_posts") && !is_admin()) {
		if(isset($_SESSION['iterative_comments_posted'])) {
			$_SESSION['iterative_comments_posted'][] = $comment['comment_post_ID'];
		} else {
			$_SESSION['iterative_comments_posted'] = array($comment['comment_post_ID']);
		}
	}

	return $comment;
}


/* ====================================================
 * EDIT POST PAGE CHANGES
 * Relevant hooks from wp-admin/edit-form-advanced.php
 * 	$title_placeholder = apply_filters( 'enter_title_here', __( 'Enter title here' ), $post );
 * 	do_action( 'edit_form_before_permalink', $post );
 * ==================================================== */
function iterative_save_headline_variants($post_id, $post, $update) {
	return iterative_save_variants($post_id, $post, $update, "headlines");
}

function iterative_save_variants( $post_id, $post, $update, $experiment_type="headlines") {
    $field = substr($experiment_type, 0, strlen($experiment_type)-1);
    if ( isset( $_REQUEST['iterative_'. $field . '_variants'] ) ) {
	$result = array_filter($_REQUEST['iterative_' . $field . '_variants']);
	if(!empty($result)) 
		update_post_meta( $post_id, '_iterative_' . $field . '_variants', $result); // ( $_REQUEST['iterative_post_title_variants'] ) );
	else
		delete_post_meta($post_id, "_iterative_" . $field . "_variants");
    }

    if($experiment_type === "headlines") {
		$meta = array(
			'post_type'=>$post->post_type,
			'comment_count'=>$post->comment_count,
			'ID' => $post->ID,
			'post_author' => $post->post_author,
			'post_date' => $post->post_date,
			'post_status' => $post->post_status
		);
    } else {
		$meta = array();
    }

    IterativeAPI::updateExperiment($post_id, iterative_get_variants($post_id, $experiment_type), $meta);
}


function iterative_calculate_message($variants, $parameters, $baseline_title=null, $needs_md5=false) {
	$lc = 0.1;
	$uc = 0.9;	
	$debug = false;

	$return = array('variants' => array());

	if($baseline_title !== null) {	// calculate the pieces for the baseline. in the future, fluid baselines are better.
		$baseline_key = md5($baseline_title);
		if(isset($parameters[($baseline_key)])) {
			$baseline = $parameters[($baseline_key)];

			$blt = $baseline['a']+$baseline['b'];
			$baseline_ratio = $baseline['a']/$blt;
		}

		$return['baseline'] = $baseline;
		$return['baseline_ratio'] = $baseline_ratio;
	}

	if(count($variants) > 0) {
		$shown = false;	
		$best_ratio = 0;
		$best_key = null;
		$number_same = 0;
		$uresults = $lresults = array();
		if(is_array($variants)) {
			foreach($variants as $k=>$p) {
				$p = trim($p);
				if($p == '') { unset($p); continue; }
				if($needs_md5) $p = md5($p);
				if(isset($parameters[($p)])) {
					$score =($parameters[($p)]);
					$slt = ($score['a']+$score['b']);
					$score_ratio = $score['a']	/ $slt;
					if($score_ratio >= $best_ratio) {
						$best_ratio = $score_ratio;
						$best_key = $k;
					}

					if(isset($score['b']) && $score['b'] != 0) {
						$lresults[$k] = iterative_ib($lc, $score['a'], $score['b']);;
						$uresults[$k] = iterative_ib($uc, $score['a'], $score['b']);;
					}
				}
			}

			$return['best_ratio'] = $best_ratio;


			foreach($variants as $k=>$p) {
				$winner = "";
				$p = trim($p);
				if($p == '') continue;
				if($needs_md5) $key = md5($p);
				else $key = $p;
				if(isset($parameters[$key])) {
					$score =($parameters[$key]);;
					$slt = ($score['a']+$score['b']);
					$score_ratio = $score['a']	/$slt;
					$ratio_ratio = $score_ratio / $baseline_ratio;

					$msg_type = null;


					//if($msg_type != null) {

					if($slt <= 2 || ($ratio_ratio > 0.9 && $ratio_ratio < 1.02)) { 
						$msg_type = null;
					} else if($ratio_ratio>1) {
						$msg_type = "success";
						if($score_ratio == $best_ratio) 
							$winner = "winner";
					} else {
						$msg_type = "fail";
					}
					$return['variants'][$k] = array(
						"hash" => $key,
						"string" => $p,
						"score_ratio" => $score_ratio,
						"ratio_ratio" => $ratio_ratio,
						"message_type" => $msg_type,
						"score" => $score,
						"winner" => $winner
					);

					if($msg_type != null) {
						$improvement_string = "";
						if($debug)
							$improvement_string .= $score['a'] . ":" . $score['b'] . " ";
						$improvement_string .= round(abs($ratio_ratio - 1)*100) . "%";
						$improvement_string .= ($ratio_ratio > 1) ? " better" : " worse";
						$return['variants'][$k]['improvement_string'] = $improvement_string;

						$aside_title = $aside_string = "";
						
						if($debug) {
							$aside_string .=  " BK: " . round($lresults[$best_key], 2) . ", " . round($uresults[$best_key], 2);
							$aside_string .= " CK: "  . round($lresults[$k], 2) . ", " . round($uresults[$k], 2);
						}

						$mp = $lresults[$best_key] + (($lresults[$best_key] - $uresults[$best_key])/2);
						
					    	// this compares to the best key rather than the baseline, which makes this a bit confusing, so we don't show reductions from baseline which are also delivering a large fraction of traffic.	
						if($uresults[$k] < $lresults[$best_key]) 
						{	
							$aside_title .= 'We&rsquo;re confident this is not the best title.';
							$aside_string .= 'Very few users will see this.';
						}
						else if ($uresults[$k] > $lresults[$best_key] && $uresults[$k] < $mp) {
							$aside_string .= 'We&rsquo;re still learning about this.';
						}
						else if ($uresults[$k] > $mp && $uresults[$k] < $uresults[$best_key] && $ratio_ratio > 1) {
							$aside_title .='This is performing well.';
							$aside_string .= 'Many users will see this.';
						} 
						else if($ratio_ratio > 1) { // this means we're in the extreme tail of the distribution
							$aside_title .= 'This is the best title so far. We&rsquo;ll show this to most users.';
							$aside_string .= "Most users will see this.";
						}
						else { 
							$aside_string = "We&rsquo;re still learning about this.";
						}

						$return['variants'][$k]['aside_title'] = $aside_title;
						$return['variants'][$k]['aside_string'] = $aside_string;
						$shown = true;
					} 

					$winner = "";
				}
			}
		}
	}

	return $return;
}


function iterative_append_feed_string($url, $post, $leavename) {
	if(!is_feed()) 
		return $url;
	
	$id = $post->ID;
	$variants = iterative_get_variants($id, "headlines");
	if($variants === null || count($variants) <= 1)
		return $url;

	global $iterative_selected;
	if(isset($iterative_selected[$id]))
		return iterative_supplement_url(ITERATIVE_GET_PARAMETER, $iterative_selected[$id], $url);
	return $url;
}

function iterative_add_headline_variants($post) {
	echo "<link rel='stylesheet' href='" . plugins_url("css/admin.css", __FILE__) . "' />
    	<script type='text/javascript' src='" . plugins_url( 'js/duplicable.js', __FILE__ ) . "'></script>
	<script type='text/javascript'>
	jQuery(function() {
		duplicable('.iterative-headline-variant', 9);
	});
	</script>";

	$lc = 0.1;
	$uc = 0.9;	
	$debug = false;

	$title = trim(get_the_title($post->ID));

	// DEPRECATE: in 1.5 remove this
	$old = (array) get_post_meta($post->ID, "iterative_post_title_variants", true);
	$new = (array) get_post_meta($post->ID, '_iterative_headline_variants', true);
	$ptv = $new + $old;
	$ptv = array_unique(array_filter($ptv));
	
	if(count($ptv) > 0) {
		$type = IterativeAPI::getType();
 		$adviceTitles = $ptv;
		@array_unshift($adviceTitles, $title);;
	
		$advice = (IterativeAPI::getAdvice($post->ID, $adviceTitles));
		$pms = IterativeAPI::getParameters($post->ID, 'sts');
	}

	echo "
		<div class='iterative-message up'>
		    <span class='message baseline'>";
		    if($debug) { echo "{$baseline['a']}:{$baseline['b']} "; }
		    echo "Baseline</span>
		    <br />
		    <!--<span class='aside'>311 users have seen this</span>-->
		</div>
	";
	do_action("iterative_after_baseline");
	//placeholder="Enter experimental title variant."
	// reload these.

	$details = iterative_calculate_message($ptv, $pms, $title, true);
	foreach($details['variants'] as $key=>$value) {
		if($value['message_type'] != null) {
				// TODO: remove this title when it isn't relevant.
				echo "<div class='iterative-message {$value['winner']}' title='In the pro version, the best per-user title will be delivered: this means mobile users from Canada may see a different title than Mac OS users in California.'>";
				
				echo "<span class='message ";
					echo $value['message_type'];
				echo "'>"; 
				if($debug)
					echo $value['score']['a'] . ":" . $value['score']['b'] . " ";
				echo $value['improvement_string'];
				
				echo "</span><br />";
				
			    	// this compares to the best key rather than the baseline, which makes this a bit confusing, so we don't show reductions from baseline which are also delivering a large fraction of traffic.	
				echo "<span class='aside' title='" . $value['aside_title'] . "'>" . $value['aside_string'] . "</span></div>";;
		}
		echo '<input type="text" name="iterative_headline_variants[]" size="30" value="' . $value['string'] . '" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';	
	}
	echo '<input type="text" id="iterative_first_variant"  name="iterative_headline_variants[]" size="30" value="" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';
	

	do_action("iterative_after_headlines");

	echo '<div class="headline-tip" style="display:none; border:solid 1px #CCC; padding: 5px; color:white; background-color: #00a0d2; padding-left:10px; padding-right:10px;">
		<img style="margin-top:3px;float:left;width:12px;padding-right:4px;" src="' . plugins_url("light_24.png", __FILE__)  . '" />
		<div style="float:right; padding-left:5px; cursor:pointer;" class="dismiss">✓ ✗</div>
		<div class="text"><strong>Suggestion:</strong> Use the word \'This\' in your headline to create a concrete image in your readers\' heads.</div>
	</div>';
	
	echo iterative_advice_javascript($advice);
	do_action("iterative_after_advice");
}
