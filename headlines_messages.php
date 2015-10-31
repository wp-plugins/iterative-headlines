<?php
	add_action( 'admin_notices', 'iterative_admin_warnings' );
	function iterative_admin_warnings() {
		if(is_plugin_active("w3-total-cache/w3-total-cache.php")) {
			//pgcache.enabled
		}

		if(is_plugin_active("wp-super-cache/wp-cache.php")) {
			//wp_cache_status

		}
		?>
		<!--
			<div class="error"><p><strong>
			</strong></p></div>
		-->
		<?php
	}

