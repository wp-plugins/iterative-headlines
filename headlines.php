<?php
/*
Plugin Name: Viral Headlines&trade;
Plugin URI: http://www.viralheadlines.net/
Description: Test your post titles and headlines with state-of-the-art artificial intelligence. 
Author: Iterative Research Inc.
Version: 1.4.1
Author URI: mailto:joe@iterative.ca
License: GPLv2+
*/

global $iterative_disable_title_filter;
$iterative_disable_title_filter = false;

require_once dirname(__FILE__) . "/headlines_api.php";
require_once dirname(__FILE__) . "/headlines_pointer.php";
require_once dirname(__FILE__) . "/headlines_options.php";
require_once dirname(__FILE__) . "/headlines_functions.php";
require_once dirname(__FILE__) . "/headlines_calculator.php";

define("ITERATIVE_HEADLINES_BRANDING", "Viral Headlines&trade;");
define("ITERATIVE_GOAL_CLICKS", 1);
define("ITERATIVE_GOAL_COMMENTS", 4);
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
	if($experiment_type === "headlines" && isset($_GET[ITERATIVE_GET_PARAMETER])) {
		// TODO: in the future (and pro only, as the benefit requires the pro API), 
		// consider storing extra data in that get parameter like CFs.

		// exit from the URL if available
		if(isset($variants[$_GET[ITERATIVE_GET_PARAMETER]])) {
			$selected = $_GET[ITERATIVE_GET_PARAMETER];
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
	
		// DEPRECATE: in 1.5 remove this.
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

function iterative_add_javascript() {
	if(!is_admin()) {
		echo "<script type='text/javascript' src='" . IterativeAPI::getTrackerURL() . "'></script>";

		// record a click conversion
		global $post;
		$id = $post->ID;
		global $iterative_selected;
	
		if(is_single() && isset($iterative_selected[$id])) {
			$variants = iterative_get_variants($id); 
			$variant = $iterative_selected[$id];
			//$variant = IterativeAPI::selectVariant($id, array_keys($variants));
			if(count($variants) >= 1) {
				if((iterative_get_referring_host() == iterative_remove_www(parse_url($_SERVER["HTTP_HOST"], PHP_URL_HOST))))
					echo "<script type='text/javascript' src='" . IterativeAPI::getSuccessURL(ITERATIVE_GOAL_CLICKS, $variant, $id) . "'></script>";
				else if(isset($_GET[ITERATIVE_GET_PARAMETER])) { // off site referrer but saw fixed title.

					echo "<script type='text/javascript' src='" . IterativeAPI::getSuccessURL(ITERATIVE_GOAL_VIRALITY, $variant, $id) . "'></script>";
				}
			}

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
function iterative_save_headline_variants($post_id, $post, $update) {
	return iterative_save_variants($post_id, $post, $update, "headlines");
}

function iterative_save_variants( $post_id, $post, $update, $experiment_type="headlines") {
    $field = substr($experiment_type, 0, strlen($experiment_type)-1);
    if ( isset( $_REQUEST['iterative_'. $field . '_variants'] ) ) {
    	$result = array();

    	foreach($_REQUEST['iterative_' . $field . '_variants'] as $iptv) {
    		$iptv = trim($iptv);
    		if($iptv == '')
    			continue;
    		$result[] = $iptv;
    	}
        update_post_meta( $post_id, '_iterative_' . $field . '_variants', $result); // ( $_REQUEST['iterative_post_title_variants'] ) );
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



// TODO: refactor this, it also handles excerpts in pro... rename it, probably pull it out in to another file
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
	$ptv = array_filter($ptv);
	if(count($ptv) > 0) {
		$type = IterativeAPI::getType();
 		$adviceTitles = $ptv;
		@array_unshift($adviceTitles, $title);;
	
		$advice = (IterativeAPI::getAdvice($post->ID, $adviceTitles));
		$pms = IterativeAPI::getParameters($post->ID, 'sts');
		if(isset($pms[md5($title)])) {
			$baseline = $pms[md5($title)];
	
			$blt = $baseline['a']+$baseline['b'];
			$baseline_ratio = $baseline['a']/$blt;

		}
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
	//placeholder="Enter experimental title variant."
	// reload these.

	if(count($ptv) > 0) {
		$shown = false;	
		$best_ratio = 0;
		$best_key = null;
		$number_same = 0;
		$uresults = $lresults = array();
		if(is_array($ptv)) {
			foreach($ptv as $k=>$p) {
				$p = trim($p);
				if($p == '') { unset($p); continue; }
				if(isset($pms[md5($p)])) {
					$score =($pms[md5($p)]);
					$slt = ($score['a']+$score['b']);
					$score_ratio = $score['a']	/ $slt;
					if($score_ratio >= $best_ratio) {
						$best_ratio = $score_ratio;
						$best_key = $k;
					}

					$lresults[$k] = iterative_ib($lc, $score['a'], $score['b']);;
					$uresults[$k] = iterative_ib($uc, $score['a'], $score['b']);;
				}
			}

			foreach($ptv as $k=>$p) {
				$winner = "";
				$p = trim($p);
				if($p == '') continue;
				if(isset($pms[md5($p)])) {
					$score =($pms[md5($p)]);;
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
					if($msg_type != null) {
						echo "<div class='iterative-message {$winner}' title='In the pro version, the best per-user title will be delivered: this means mobile users from Canada may see a different title than Mac OS users in California.'>";
						if($winner == "winner") { 
							//echo "<img src='" . plugins_url("star_16.png", __FILE__)  . "'/>";
						}
						echo "<span class='message ";
							echo $msg_type;
						echo "'>"; 
						if($debug)
							echo $score['a'] . ":" . $score['b'] . " ";
						echo round(abs($ratio_ratio - 1)*100) . "%";
						echo ($ratio_ratio > 1) ? " better" : " worse";
						echo "</span><br />";
						if($debug) {
							echo "<span class='aside'>";
							echo " BK: " . round($lresults[$best_key], 2) . ", " . round($uresults[$best_key], 2);
							echo " CK: "  . round($lresults[$k], 2) . ", " . round($uresults[$k], 2);
							echo "</span>";
						}

						$mp = $lresults[$best_key] + (($lresults[$best_key] - $uresults[$best_key])/2);
						
					    		
						echo "<span class='aside'";
						if($uresults[$k] < $lresults[$best_key]) 
							echo " title='We&rsquo;re confident this is not the best title.'>Very few users will see this.";
						else if ($uresults[$k] > $lresults[$best_key] && $uresults[$k] < $mp) 
							echo " title=''>We&rsquo;re still learning about this.";
						else if ($uresults[$k] > $mp && $uresults[$k] < $uresults[$best_key] && $ratio_ratio > 1) 
							echo " title='This is performing well.'>Many users will see this.";
						else if($ratio_ratio > 1) // this means we're in the extreme tail of the distribution
							echo " title='This is the best title so far. We&rsquo;ll show this to most users.'>Most users will see this.";
						else echo "></span>";
						echo "</span>";
				  
						echo "</div>";
						$shown = true;
					} else {	
					}
					//}
					$winner = "";
				}
				echo '<input type="text" name="iterative_headline_variants[]" size="30" value="' . $p . '" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';	
			}
		}
	}
	echo '<input type="text" id="iterative_first_variant"  name="iterative_headline_variants[]" size="30" value="" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';
	if($shown == false) {
			//
		}
		echo '<div class="headline-tip" style="display:none; border:solid 1px #CCC; padding: 5px; color:white; background-color: #00a0d2; padding-left:10px; padding-right:10px;">
			<img style="margin-top:3px;float:left;width:12px;padding-right:4px;" src="' . plugins_url("light_24.png", __FILE__)  . '" />
			<div style="float:right; padding-left:5px; cursor:pointer;" class="dismiss">✓ ✗</div>
			<div class="text"><strong>Suggestion:</strong> Use the word \'This\' in your headline to create a concrete image in your readers\' heads.</div>
		</div>';
		if(is_array($advice))
			shuffle($advice);
	echo '<script type="text/javascript">
		var advices = ' . json_encode($advice) . ';
		var iterativeStartAdvices = function() {
			if(advices && advices.length) { 
				jQuery(".headline-tip .text").html(advices[0]).attr("x-id", 0);
				jQuery(".headline-tip").fadeIn();
			}
		}
		jQuery(function() {
			jQuery(".iterative-headline-variant").parent().on("change", ".iterative-headline-variant", (function() {
				var titles = [];
				jQuery(".iterative-headline-variant").each(function() {
					if(jQuery(this).val() != "")
						titles.push(jQuery(this).val());
				});
				// get new headlines
				// get the stuff.
				jQuery.ajax("' . IterativeAPI::getURL("advice") . '", {"data": {"variants": JSON.stringify(titles), "unique_id": "' . IterativeAPI::getGUID() . '"}}).done(function(success) {
					console.log(success);
					jQuery(".headline-tip").fadeOut(function() {
						jQuery(".headline-tip .text").attr("x-id", 0);
						advices = success["messages"]
						iterativeStartAdvices();
					});
				});
			}));
			jQuery(".headline-tip .dismiss").click(function() {
				jQuery(".headline-tip").fadeOut(function() {
					var id = jQuery(".headline-tip .text").attr("x-id");
					id++;
					if(advices[id] != undefined) {
						jQuery(".headline-tip .text").html(advices[id]);
						jQuery(".headline-tip").fadeIn();
						jQuery(".headline-tip .text").attr("x-id", id);
					}
				});
			});
			iterativeStartAdvices();
		});
	</script>';
}
