<?php
/*
Plugin Name: Viral Headlines&trade;
Plugin URI: http://toolkit.iterative.ca/headlines/
Description: Test your post titles and headlines with state-of-the-art artificial intelligence. 
Author: Iterative Research Inc.
Version: 1.3
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

add_action( 'admin_notices', 'iterative_admin_notices' );
global $iterative_notices;
$iterative_notices = array();
function iterative_admin_notices() {
	return;
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

		if(is_single() && (iterative_get_referring_host() == iterative_remove_www(parse_url($_SERVER["HTTP_HOST"], PHP_URL_HOST)))) {
			global $post;
			$id = $post->ID;
			$variants = iterative_get_variants($id);

			$variant = IterativeAPI::selectVariant($id, array_keys($variants));
			if(count($variants) >= 1) {
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
        update_post_meta( $post_id, 'iterative_post_title_variants', $result); // ( $_REQUEST['iterative_post_title_variants'] ) );
    }

    IterativeAPI::updateExperiment($post_id, iterative_get_variants($post_id), (array)$post);
}

function iterative_add_headline_variants($post) {
	echo "<style type='text/css'>
	.iterative-headline-variant { 
		padding: 3px 8px;
		padding-left: 37px;
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
	}
	.iterative-message { 
		position: relative;
	    text-align: right;
	    top: 7px;
	    right: 8px;
	    height: 0px;
	    font-size: 10px;
	    line-height: 14px; 
	    cursor:pointer;
	    opacity:0.4;
	}
	.iterative-message:hover { opacity:1; }

	.iterative-message.up { 
		top:-27px;
	}
	.iterative-message.winner { opacity: 1; }
	.iterative-message.winner:hover { opacity:0.5; }
	.iterative-message span.message { 
 		color: white;
	    font-weight: bolder;
	    padding: 2px;
	    border-radius: 5px;
	    padding-left: 5px;
	    padding-right: 5px;
	}
    .iterative-message span.message.success {
    	background-color: hsl(94, 61%, 44%);
    }

    .iterative-message span.message.fail {
		background-color: hsl(0, 61%, 44%);
    }
    .iterative-message span.message.baseline {
   	    background-color: #777;
    }
    .iterative-message span.aside {
    	color:#CCC;
    	background-color:white; 
    	border-radius:5px;
    	top:2px; position:relative;
    	padding:0px;padding-bottom:0; padding-left:5px; padding-right:5px;
    }
    </style><script type='text/javascript'>
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
				{
					var thing = jQuery('.iterative-headline-variant:first').clone().val('').attr('id', '');
					thing.insertAfter(jQuery('.iterative-headline-variant:last'));
			
				}	
			}
		}));
	});
	</script>";

	$lc = 0.1;
	$uc = 0.9;	
	$debug = false;

	$title = trim(get_the_title($post->ID));
	$ptv = get_post_meta( $post->ID, 'iterative_post_title_variants', true);   

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

	echo "
		<div class='iterative-message up' title='Some more conversion data?'>
		    <span class='message baseline'>";
		    if($debug) { echo "{$baseline['a']}:{$baseline['b']} "; }
		    echo "Baseline</span>
		    <br>
		    <!--<span class='aside'>311 users have seen this</span>-->
		</div>
	";
	//placeholder="Enter experimental title variant."
	// reload these.

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

				if($ratio_ratio > 0.9 && $ratio_ratio < 1.02) { 
					$msg_type = null;
				} else if($ratio_ratio>1) {
					$msg_type = "success";
					if($score_ratio == $best_ratio) 
						$winner = "winner";
				} else {
					$msg_type = "fail";
				}
				if($msg_type != null) {
					echo "<div class='iterative-message {$winner}' title='Some more conversion data?'>";
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
					    echo "</span>
					    <br>";
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
					    else if ($uresults[$k] > $mp && $uresults[$k] < $uresults[$best_key]) 
					    	echo " title='This is performing well.'>Many users will see this.";
					    else // this means we're in the extreme tail of the distribution
					    	echo " title='This is the best title so far. We&rsquo;ll show this to most users.'>Most users will see this.";
				    	echo "</span>";
				  
					    echo "
					</div>";
					$shown = true;
				} else {
					
				}
				//}
				$winner = "";
			}
			echo '<input type="text" name="iterative_post_title_variants[]" size="30" value="' . $p . '" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';	
		}
	}
	echo '<input type="text" id="iterative_first_variant"  name="iterative_post_title_variants[]" size="30" value="" class="iterative-headline-variant" spellcheck="true" autocomplete="off">';
	if($shown == false) {
		//
	}
	echo '<div class="headline-tip" style="display:none; border:solid 1px #CCC; 
    padding: 5px; color:white; background-color: #00a0d2; padding-left:10px; padding-right:10px;">
    <img style="margin-top:3px;float:left;width:12px;padding-right:4px;" src="' . plugins_url("light_24.png", __FILE__)  . '" />
    <div style="float:right; padding-left:5px; cursor:pointer;" class="dismiss">✓ ✗</div>
    <div class="text"><strong>Suggestion:</strong> Use the word \'This\' in your headline to create a concrete image in your readers\' heads.</div>
</div>';
	shuffle($advice);
	echo '<script type="text/javascript">
		var advices = ' . json_encode($advice) . ';
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
			function iterativeStartAdvices() {
				if(advices.length) { 
					jQuery(".headline-tip .text").html(advices[0]).attr("x-id", 0);
					jQuery(".headline-tip").fadeIn();
				}
			}
	</script>';
}
