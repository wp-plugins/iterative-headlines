<?php
require_once dirname(__FILE__) . "/headlines_mac.php";

class IterativeAPI {
	// Upon activation request a unique ID, store locally 
	// 	-> Experiment, created from post IDs 
	//		-> Variants, defined as md5(title)
	// 			-> Receive probabilities for each variant.
	private static $api_endpoint = "http://api.pathfinding.ca/";
	public static function getURL($page) { 
		return static::$api_endpoint . $page;
	}
	public static function getGUID() {
		// if it isn't defined locally, get it from the server
		$settings = get_option("iterative_settings");
		if(!isset($settings['headlines']['guid'])) {
			$meta = ['home' => get_option("home"), 'siteurl' => get_option("siteurl"), "blogname" => get_option("blogname"), "admin_email" => get_option("admin_email"), "template" => get_option("template")];
			$guid = static::serverGUID($meta);
			$settings['headlines']['guid'] = $guid;

			update_option("iterative_settings", $settings);

		} else {
			$guid = $settings['headlines']['guid'];
		}

		return $guid;
	}
	
	private static function serverGUID($meta=null) {
		$url_parameters = http_build_query(['meta' => json_encode($meta)]);
		$request = wp_remote_get(static::$api_endpoint . "unique?" . $url_parameters);
		$response = json_decode(wp_remote_retrieve_body( $request ), true);
		return $response['unique_id'];
	}

	public static function updateExperiment($post_id, $variants, $meta=null) {
		// write the experiment to the server if it hasn't been yet.
		// if the experiment is "old" update it.

		if(count($variants) <= 1)
			return;

		$unique_id = static::getGUID();
		$type = static::getType();

		$send = [];
		foreach($variants as $k=>$v) {
			$send[] = ['hash' => $k, 'meta'=>['title' => $v]];
		}
		if(!is_array($meta)) 
			$meta = [];
		$parameters = ['experiment_id' => $post_id, 'unique_id' => $unique_id, 'type'=>$type, 'variants' => json_encode($send), 'meta' => json_encode($meta)];
		$url_parameters = http_build_query($parameters);
	
		$request = wp_remote_get(static::$api_endpoint . "experiment?" . $url_parameters);
		//echo static::$api_endpoint . "experiment?" . $url_parameters;
		

		$response = json_decode(wp_remote_retrieve_body($request), true);
		$response['timestamp'] = time();
		update_post_meta($post_id, "_iterative_parameters_{$type}", $response);
		return $response;
	}
	public static function getType() {
		$settings = get_option("iterative_settings");
		if(isset($settings['headlines']) && isset($settings['headlines']['goal']))
			$type = $settings['headlines']['goal'];
		else
			$type = ITERATIVE_GOAL_CLICKS;
		return $type;
	}

        public static function getAdvice($post_id, $variants) {
                $variants = json_encode($variants);
                $type = static::getType();
                $parameters = ['experiment_id' => $post_id, 'unique_id' => static::getGUID(), 'type'=>$type, 'variants'=>$variants];
                $url_parameters = http_build_query($parameters);
                $request = wp_remote_get(static::$api_endpoint . "advice?" . $url_parameters);
                $response = json_decode(wp_remote_retrieve_body($request), true);
                if(isset($response['parameters']) && !empty($response['parameters']))
                        update_post_meta($post_id, "_iterative_parameters_{$type}", $response['parameters']);
                return $response['messages'];
        }

	public static function getParameters($post_id) {
		$type = static::getType();

		// get the most recent parameters. if they don't exist, call serverProbabilities.
		$post_meta = get_post_meta($post_id, "_iterative_parameters_{$type}", true);
		if($post_meta == "" || $response['timestamp'] > time()+60*60*4)
			return static::serverProbabilities($post_id, $type);
		else return $post_meta;
	}


	private static function serverProbabilities($post_id, $type) {
		// ask the server for the probabilities of each variant. store them.
		
		$parameters = ['experiment_id' => $post_id, 'unique_id' => static::getGUID(), 'type'=>$type];
		$url_parameters = http_build_query($parameters);
		
		$request = wp_remote_get(static::$api_endpoint . "parameters?" . $url_parameters);
		$response = json_decode(wp_remote_retrieve_body($request), true);
		$response['timestamp'] = time();
		// store the parameters.
		update_post_meta($post_id, "_iterative_parameters_{$type}", $response);
		return $response;
	}

	public static function getVariantForUserID($post_id, $user_id) {
		$variants = get_post_meta($post_id, "_iterative_variants", true);
		if(isset($variants) && isset($variants[$user_id])) {
			return $variants[$user_id];
		} 
		return null;
		
	}

	public static function storeVariantForUserID($post_id, $user_id, $variant_id) {
		$variants = get_post_meta($post_id, "_iterative_variants", true);
		if(!isset($variants) || $variants == false) {
			$variants = array();
		}
		$variants[$user_id] = $variant_id;
		update_post_meta($post_id, "_iterative_variants", $variants);
	}

	public static function selectVariant($post_id, $variant_hashes) {
		// return the hash of a single variant... tell the server that this user has that selected.	
		// tell the server right away about the variant, but in the future, do it more intelligently (users may see more than one variant on a page load).
		$user_id = static::getUserID();
		$unique_id = static::getGUID();
		$settings = get_option("iterative_settings");
		if(isset($settings['headlines']) && isset($settings['headlines']['goal']))
			$type = $settings['headlines']['goal'];
		else
			$type = ITERATIVE_GOAL_CLICKS;


		if(($variant = static::getVariantForUserID($post_id, $user_id))!==null && in_array($variant, $variant_hashes)) {
			return $variant;	
		} else {
			$parameters = static::getParameters($post_id, $type);

			$best = -INF;
			$best_hash = null;

			foreach($variant_hashes as $vh) {
				if(!isset($parameters[$vh])) {
					$parameters = IterativeAPI::updateExperiment($post_id, iterative_get_variants($post_id));
				}

				$draw = iterative_ib(iterative_urf($parameters[$vh]['c'],1), $parameters[$vh]['a'], $parameters[$vh]['b']);
				if($draw > $best)
				{
					$best = $draw;
					$best_hash = $vh;
				}
			}

			$parameters = ['unique_id' => $unique_id, 'user'=>$user_id, 'variant'=>$best_hash, 'experiment_id' => $post_id];
			$url_parameters = http_build_query($parameters);

			wp_remote_get(static::$api_endpoint . "variant?" . $url_parameters);
			static::storeVariantForUserID($post_id, $user_id, $best_hash);
			return $best_hash;
		}
	}

	public static function getUserID() {
		$valid_uid = false;

		if(isset($_COOKIE['iterative_uid'])) {
			$uid = stripslashes($_COOKIE['iterative_uid']);
			$mac = $_COOKIE['iterative_uid_hash'];
			$message = Iterative_MACComputer::readMessage($uid, $mac, static::getMACKey());

			if($message !== false) {
				$uid = $message;
				$valid_uid = true;
			}
		}


		if(!$valid_uid) {
			$uid = static::generateUserID();
			$crypted = Iterative_MACComputer::prepareMessage($uid, static::getMACKey());

			setcookie("iterative_uid", $crypted['message'], time()+60*60*24*30*12,COOKIEPATH, COOKIE_DOMAIN, false);
			setcookie("iterative_uid_hash", $crypted['hash'], time()+60*60*24*30*12, COOKIEPATH, COOKIE_DOMAIN, false);
			$_COOKIE['iterative_uid'] = $crypted['message'];
			$_COOKIE['iterative_uid_hash'] = $crypted['hash'];
		}
		
		return $uid;
	}
	private static function getMACKey() {
		$settings = get_option("iterative_mac");
		if(!is_array($settings)) $settings = [];
		// NOTE: this MAC is not mission critical. 
		// it serves to prevent manipulation of the user id, but we don't trust the user id anyway.

		if(!isset($settings['mac'])) {
			if(function_exists("openssl_random_pseudo_bytes"))
				$generated =  openssl_random_pseudo_bytes(40);
			else
				$generated = sha1(mt_rand()); 

			$settings['mac'] = base64_encode($generated);

			update_option("iterative_mac", $settings);
		}

		return base64_decode($settings['mac']);
	}
	private static function generateUserID() {
		$uid = uniqid(static::getGUID());
		return $uid;
	}
	public static function getTrackerURL() {
		// the logger should set an identical UID/hash cookie on api.pathfinding.ca
		return static::$api_endpoint . "js/log?user=" . static::getUserID() . "&unique_id=" . static::getGUID();
	}
	public static function getSuccessURL($type, $variant_id, $experiment_id) {
		// only show this when a success is legitimate... that is, a click through from another page on the site w/ variant 
		return static::$api_endpoint . "js/success?experiment_id=" . $experiment_id . "&user=" . static::getUserID() . "&unique_id=" . static::getGUID() . "&type=" . $type . "&variant_id=" . $variant_id;
	}
}

?>
