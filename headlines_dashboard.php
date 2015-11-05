<?php
/**
 * Add a widget to the dashboard.
 *
 * This function is hooked into the 'wp_dashboard_setup' action below.
 */
function iterative_headlines_dashboard_widgets() {

	add_meta_box( 'iterative_headlines_dashboard_widget',  ITERATIVE_HEADLINES_BRANDING . " News", 'iterative_headlines_dashboard_widget_load', 'dashboard', 'side', 'high' );

}
add_action( 'wp_dashboard_setup', 'iterative_headlines_dashboard_widgets' );


/**
 * Create the function to output the contents of our Dashboard Widget.
 */
function iterative_headlines_dashboard_widget_load() {

	// Display whatever it is you want to show.
	echo "<iframe style='width:100%; height:100%;' src='http://2.api.viralheadlines.net/newsframe?type=free'></iframe>";
}

function iterative_headlines_check_oos() {
	// use API to check OOS.
	if(($transient = get_transient("iterative_oos_value")) === false)
	{
		$result = IterativeAPI::checkOOS();
		set_transient("iterative_oos_value", intval($result), HOUR_IN_SECONDS);
		return $result;
	} else { return (bool) $transient; }
}

function iterative_headlines_oos_note() {
	$gcs = get_current_screen();
	
	if($gcs->id == "dashboard" || $gcs->parent_base == "options-general") { } else { return; }
	if(iterative_headlines_check_oos()) {
?>
<br />
<div class="update-nag">
	<!--<a class="dismissal-oos" style='float:right; text-decoration:none;'><span style='color:#333;'>&times; </span><span style='text-decoration:underline;'>Dismiss</span></a>-->
	Unfortunately (or fortunately!), <strong>your site is too popular</strong> for the free version of <a href="http://www.viralheadlines.com/?source=nag">Viral Headlines</a>&trade;. 
	Don't panic. You have two basic options: <ol>
		<li><strong>Ignore this</strong>, nothing bad will happen, but you will <strong>stop seeing improvements in your headlines</strong> until next calendar month. If your site is making money, this is probably a bad idea: we'll make you more money if you upgrade!</li>
		<li>Upgrade to <a href="http://www.viralheadlines.com/?source=nag">Viral Headlines Pro</a>. Pro is faster, more effective (as much as 500% better results!), more powerful and always being updated and there are no limits in how you can use it.</li>
	</ol>
</div>

<?php 
	}
}
add_action("admin_notices", "iterative_headlines_oos_note");
