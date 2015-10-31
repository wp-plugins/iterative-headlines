<?php
require_once dirname(__FILE__) . "/headlines_mac.php";

class IterativeVariantInformer {
        public $stories = array();
        public $unique_id = null;
        public $user = null;
        public function queueVariant($unique_id, $experiment_id, $experiment_type, $user, $variant) {
                $this->unique_id = $unique_id;
                $this->user = $user;
                if(!is_array($this->stories[$experiment_type]))
                        $this->stories[$experiment_type] = array();
                $this->stories[$experiment_type][] = array($experiment_id, $variant);
        }
        public function __destruct() {
                if(count($this->stories) > 0 && $this->unique_id !== null)
                        IterativeAPI::makeRequest("variants", array("user" => $this->user, "unique_id" => $this->unique_id, "variants" => json_encode($this->stories)));
        }
}

global $iterative_informer;
$iterative_informer = new IterativeVariantInformer();
class IterativeAPI {
	private static $api_endpoint = "http://2.api.viralheadlines.net/";
	private static $api_version = 1.5;
	private static $reject_age = 86400;
	private static $request_age = 6400;
	private static $variant_length = 8;
	public static $isOOS = false;
	public static function getURL($page) { 
		return self::$api_endpoint . $page;
	}

	public static function checkOOS() {
		if(self::$isOOS)
			return true;
		$result = self::makeRequest("oos", array("unique_id" => self::getGUID()));

		return (self::$isOOS);
	}
	public static function getEndpoint() { return self::$api_endpoint; }

	public static function makeRequest($endpoint, $blob=array()) {
		$url_parameters = http_build_query($blob);
		$url = self::getEndpoint() . "{$endpoint}?" . $url_parameters . "&v=" . self::$api_version . "&cangz=" . (function_exists("gzdecode") ? "yes" : "no");
		if(self::$isOOS) 
			return;

		$request = wp_remote_get($url, array("timeout" => 20));
		$response = json_decode(wp_remote_retrieve_body( $request ), true);
		if(isset($response['error'])) {
			global $iterative_notices;
			$iterative_notices[] = $response['error'];
			if(is_admin()) {
				?>
					<div class="updated">
					<p><strong>Error:</strong> <?php _e( $response['error']); ?></p>
					</div>
				<?php
			}
		}
		if(isset($response['special'])) {
			if($response['special'] == "DISABLE") {
				deactivate_plugins( plugin_basename( __FILE__ ) );
			} else if($response['special'] == "DELIVER" && is_admin()) {
				echo "<p>" . $response['error'] . "</p>";
			} else if($response['special'] == "OOS") {
				self::$isOOS = true;
			}
		}
		return $response;
	}

	public static function getGUID() {
		// if it isn't defined locally, get it from the server
		$settings = get_option("iterative_settings");
		if(!isset($settings['headlines']['guid'])) {
			$meta = array('home' => get_option("home"), 'siteurl' => get_option("siteurl"), "blogname" => get_option("blogname"), "admin_email" => get_option("admin_email"), "template" => get_option("template"));
			$guid = self::serverGUID($meta);
			$settings['headlines']['guid'] = $guid;
			update_option("iterative_settings", $settings);
		} else {
			$guid = $settings['headlines']['guid'];
		}

		return $guid;
	}
	
	private static function serverGUID($meta=null) {
		$response = self::makeRequest("unique", array('meta' => json_encode($meta)));
		return $response['unique_id'];
	}

	public static function updateExperiment($post_id, $variants, $meta=null, $experiment_type='headlines') {
		// write the experiment to the server if it hasn't been yet.
		// if the experiment is "old" update it.

		if(count($variants) <= 1)
			return;

		$unique_id = self::getGUID();
		$type = self::getType($post_id, $experiment_type);

		$send = array();
		foreach($variants as $k=>$v) {
			$send[] = array('hash' => $k, 'meta'=>array('title' => $v));
		}
		if(!is_array($meta)) 
			$meta = array();

		$parameters = array(
			'experiment_id' => $post_id, 
			'experiment_type' => $experiment_type,
			'unique_id' => $unique_id, 
			'type' => $type, 
			'variants' => json_encode($send), 
			'meta' => json_encode($meta)
		);
		$response = self::makeRequest("experiment", $parameters);

		if(isset($response['other'])) {
			$other = $response['other'];
			unset($response['other']);	
		}		
		self::storeProbabilities($post_id, $type, $response, $experiment_type);

		if(is_array($other)) {
			foreach($other as $type=>$parameters) {
				// NOTE: other is only appropriate when the experiment ID is the same as the post ID, 
				// as in the case of excerpts and presumably custom fields.

				// NOTE 2: response can describe multiple  algorithm choices.
				self::storeProbabilities($post_id, $type, $parameters, $experiment_type);
			}
		}

		/*$model = array();
   		if(!isset($response['model_type'])) {
			$response['model_type'] = 'sts';
			$response['model_version'] = 0;
			$response['model_priority'] = 10;
		}
		if(isset($response['next_timestamp']))
			$response['next_timestamp'] = time() + $response['next_timestamp'];

		$model_variables = array();
		foreach($response as $key=>$value) {
			if(substr($key, 0, 6) == "model_") $model_variables[substr($key, 6)] = $value;
		}

		$models = get_post_meta($post_id, "_iterative_models_{$type}_{$experiment_type}", true);
		if(!is_array($models))
			$models = array();
		$models[$response['model_type']] = $model_variables;
		// store the parameters.


		update_post_meta($post_id, "_iterative_models_{$type}_{$experiment_type}", $models);

		$response['timestamp'] = time();
		update_post_meta($post_id, "_iterative_parameters_{$response['model_type']}_{$type}_{$experiment_type}", $response);*/
		return $response;
	}

	public static function getType($experiment_id=null, $experiment_type=null) {
		$settings = get_option("iterative_settings");
		if(isset($settings['headlines']) && isset($settings['headlines']['goal']))
			$type = $settings['headlines']['goal'];
		else
			$type = ITERATIVE_GOAL_CLICKS;
		return $type;
	}

	public static function getAdvice($post_id, $variants) {
		$variants = json_encode($variants);
		$type = self::getType($post_id, 'headlines');
		$parameters = array(
				'experiment_type' => 'headlines', 
				'experiment_id' => $post_id, 
				'unique_id' => self::getGUID(), 
				'type'=>$type, 
				'variants'=>$variants
		);
		$response = self::makeRequest("advice", $parameters);

		if(is_array($response['messages']))
			shuffle($response['messages']);

		$lift = self::makeRequest("lift", $parameters);
		if(is_array($lift['messages'])) {
			$messages = array_merge($lift['messages'], $response['messages']);
		} else { $messages = $response['messages']; }

		if(isset($response['parameters']) && !empty($response['parameters']))
			update_post_meta($post_id, "_iterative_parameters_sts_{$type}_headlines", $response['parameters']);

		return $messages; // $response['messages'];
	}

	public static function deleteParameters($post_id, $model_type='sts', $experiment_type='headlines') {
		// TODO: this should actually delete all model types, goal types.
		$type = self::getType($post_id, $experiment_type);
		update_post_meta($post_id, "_iterative_parameters_{$model_type}_{$type}_{$experiment_type}", "");
	}

	public static function getParameters($post_id, $model_type='sts', $experiment_type='headlines') {
		$type = self::getType($post_id, $experiment_type);

		// get the most recent parameters. if they don't exist, call serverProbabilities.
		$post_meta = get_post_meta($post_id, "_iterative_parameters_{$model_type}_{$type}_{$experiment_type}", true);
		if($post_meta == "" || 
				$post_meta['timestamp'] > time()+self::$request_age || 
				(isset($post_meta['next_timestamp']) && $post_meta['next_timestamp'] < time())
		  )
			return self::serverProbabilities($post_id, $type, $model_type, $experiment_type);
		else return $post_meta;
	}


	private static function storeProbabilities($post_id, $type, $response, $experiment_type='headlines') {
		$response['timestamp'] = time();
		if(!isset($response['model_type'])) {
			$response['model_type'] = 'sts';
			$response['model_version'] = 0;
			$response['model_priority'] = 10;
		}

		if(isset($response['next_timestamp'])) 
			$response['next_timestamp'] = time() + $response['next_timestamp'];

		$model_variables = array();
		foreach($response as $key=>$value) {
			if(substr($key, 0, 6) == "model_") $model_variables[substr($key, 6)] = $value;
		}
		if(count($model_variables) == count($response)) 
			return; // nothing to store.

		$models = get_post_meta($post_id, "_iterative_models_{$type}_{$experiment_type}", true);
		if(!is_array($models)) 
			$models = array();

		$models[$response['model_type']] = $model_variables;

		// store the parameters.
		update_post_meta($post_id, "_iterative_parameters_{$response['model_type']}_{$type}_{$experiment_type}", $response);
		update_post_meta($post_id, "_iterative_models_{$type}_{$experiment_type}", $models);
	}

	private static function serverProbabilities($post_id, $type, $model=null, $experiment_type='headlines') {
		// ask the server for the probabilities/model of each variant. store them.
		$parameters = array(
			'experiment_id' => $post_id, 
			'experiment_type' => $experiment_type,
			'unique_id' => self::getGUID(), 
			'type'=>$type, 
			'model'=>$model
		);

		$response = self::makeRequest("parameters", $parameters);
		self::storeProbabilities($post_id, $type, $response, $experiment_type);

		return $response;
	}


	public static function getStoredVariants() {
		$valid_variants = false;
		if(isset($_COOKIE['iterative_variants'])) {
			$variants = stripslashes($_COOKIE['iterative_variants']);
			$mac = $_COOKIE['iterative_variants_hash'];
			$message = Iterative_MACComputer::readMessage($variants, $mac, self::getMACKey());

			if($message !== false) {
				$variants = $message;
				$valid_variants = true;
			}
		}

		if($valid_variants) return $variants;
		else return null;
	}
	
	public static function getVariantForUserID($post_id, $user_id=null, $experiment_type='headlines') {
		// TODO: why is user_id here? its unused.
		$variants = self::getStoredVariants();

		if($variants === null) {
			return null;
		} else {
			return $variants[$experiment_type][$post_id];
		}
	}

	public static function storeVariantForUserID($post_id, $user_id, $variant_id, $experiment_type='headlines') {
		// get the message and update it. 
		// when adding a variant id, might need to truncate.
		$variants = self::getStoredVariants();
		if($variants === null) $variants = array();
		if(!is_array($variants[$experiment_type])) 
			$variants[$experiment_type] = array();
		
		$variants[$experiment_type][$post_id] = substr($variant_id, 0, self::$variant_length);	// store a shortened version
		$crypted = Iterative_MACComputer::prepareMessage($variants, self::getMACKey());

		// retest every 3 days at most.
		setcookie("iterative_variants", $crypted['message'], time()+60*60*24*3,COOKIEPATH, COOKIE_DOMAIN, false);
		setcookie("iterative_variants_hash", $crypted['hash'], time()+60*60*24*3, COOKIEPATH, COOKIE_DOMAIN, false);
		$_COOKIE['iterative_variants'] = $crypted['message'];
		$_COOKIE['iterative_variants_hash'] = $crypted['hash'];
	}

	public static function forceVariant($post_id, $variant_hash, $experiment_type='headlines') {
		$user_id = self::getUserID();
		$unique_id = self::getGUID();
		self::tellServerVariantForUserID($unique_id, $user_id, $variant_hash, $post_id, $experiment_type);
		self::storeVariantForUserID($post_id, $user_id, $variant_hash, $experiment_type);
	}
	public static function findBestHashset($hash, $hashes) {
		if($hash === null)
			return false;

		foreach($hashes as $test) {
			if(substr($test, 0, strlen($hash)) == $hash) 
				return $test;
		}

		return false;
	}
	public static function selectVariant($post_id, $variant_hashes, $experiment_type='headlines', $model_type=null) {
		// support models.
		if(count($variant_hashes) == 1) 
			return current($variant_hashes);

		if(!defined("DONOTCACHEPAGE"))
			define('DONOTCACHEPAGE', true);

		$user_id = self::getUserID();
		$unique_id = self::getGUID();
		
		if(($variant = self::findBestHashset(self::getVariantForUserID($post_id, $user_id, $experiment_type), $variant_hashes)) !== false && in_array($variant, $variant_hashes)) {
			return $variant;	
		} else {
			// select the right model.
			$type = self::getType($post_id, $experiment_type);
			if($model_type === null) {
				$models = get_post_meta($post_id, "_iterative_models_{$type}_{$experiment_type}", true);
				if(empty($models)) {
					$parameters = IterativeAPI::updateExperiment($post_id, iterative_get_variants($post_id), $experiment_type);
					return $variant_hashes[array_rand($variant_hashes)];
				}

				$best_model = current($models);
				$second_model = current($models);
				foreach($models as $model) {
					if($model === $best_model) continue;

					if($model['priority'] > $best_model['priority']) {
						$second_model = $best_model;
						$best_model = $model;
					} else if($model['priority'] == $best_model['priority']) {
						$second_model = $model;
						// TODO: decide whether to overwrite based on whether it is available or not.
					}
				}
				if($best_model['timestamp'] < time()-self::$reject_age)
					$best_model = $second_model;
				if(!isset($best_model['version'])) 
					$best_model['version'] = "0";
				
				$method = "model_" . $best_model['type'];
			} else {
				$method = "model_" . $model_type;
			}

			try {
				$hash = self::$method($post_id, $variant_hashes, $experiment_type);
				if($hash === false) {
					$hash = self::model_srs($post_id, $variant_hashes, $experiment_type);
				}
			} catch(Exception $e) {
				try {
					self::deleteParameters($post_id, $best_model['type'], $experiment_type);
					$hash = self::model_srs($post_id, $variant_hashes, $experiment_type);
				} catch(Exception $e) {
					// if anything goes wrong in SRSing, lets just meta-SRS and not store the hash.
					return $variant_hashes[array_rand($variant_hashes)];
				}
			}

			self::tellServerVariantForUserID($unique_id, $user_id, $hash, $post_id, $experiment_type);
			self::storeVariantForUserID($post_id, $user_id, $hash, $experiment_type);

			return $hash;
		}
	}

	public static function tellServerVariantForUserID($unique_id, $user_id, $hash, $post_id, $experiment_type='headlines') {
		$variants = get_post_meta($post_id, "_iterative_variants_{$experiment_type}", true);
                if(isset($variants[$user_id]) && $variants[$user_id] == $hash) 
			return;

		global $iterative_informer;
		$iterative_informer->queueVariant($unique_id, $post_id, $experiment_type, $user_id, $hash);

		// old technique.
		// $parameters = ['unique_id' => $unique_id, 'user'=>$user_id, 'variant'=>$hash, 'experiment_id' => $post_id];
		// self::makeRequest("variant", $parameters);
	}

	public static function getUserID() {
		$valid_uid = false;

		if(isset($_COOKIE['iterative_uid'])) {
			$uid = stripslashes($_COOKIE['iterative_uid']);
			$mac = $_COOKIE['iterative_uid_hash'];
			$message = Iterative_MACComputer::readMessage($uid, $mac, self::getMACKey());

			if($message !== false) {
				$uid = $message;
				$valid_uid = true;
			}
		}


		if(!$valid_uid) {
			$uid = self::generateUserID();
			$crypted = Iterative_MACComputer::prepareMessage($uid, self::getMACKey());

			setcookie("iterative_uid", $crypted['message'], time()+60*60*24*30*12,COOKIEPATH, COOKIE_DOMAIN, false);
			setcookie("iterative_uid_hash", $crypted['hash'], time()+60*60*24*30*12, COOKIEPATH, COOKIE_DOMAIN, false);
			$_COOKIE['iterative_uid'] = $crypted['message'];
			$_COOKIE['iterative_uid_hash'] = $crypted['hash'];
		}
		
		return $uid;
	}

	private static function getMACKey() {
		$settings = get_option("iterative_mac");
		if(!is_array($settings)) $settings = array();
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
		$uid = uniqid(self::getGUID());
		return $uid;
	}

	private static $messages = array();
	public static function success($type, $variant_id, $experiment_id, $experiment_type='headlines') {
		// variant ID might be a partial, find best match.
		$variant_id = self::findBestHashset($variant_id, array_keys(iterative_get_variants($experiment_id, $experiment_type)));
		self::$messages[] = array($experiment_id, $variant_id, $experiment_type, $type);
	}
	public static function outputTrackerJS() {
                if(!defined("DONOTCACHEPAGE"))
                        define('DONOTCACHEPAGE', true);

		$url = self::$api_endpoint . "js/mass?user=" . self::getUserID() . "&unique_id=" . self::getGUID() . "&refclass=" . iterative_get_referring_type();
		if(count(self::$messages) > 0) {
			$url .= "&messages=" . urlencode(json_encode(self::$messages));
		}

                echo "<script type='text/javascript' src='" . $url . "'></script>";
	}
	

	// TODO: in version 1.6 remove these. they're unused now.
	public static function getTrackerURL() {
                if(!defined("DONOTCACHEPAGE"))
                        define('DONOTCACHEPAGE', true);

		// the logger should set an identical UID/hash cookie on 
		return self::$api_endpoint . "js/log?user=" . self::getUserID() . "&unique_id=" . self::getGUID() . "&refclass=" . iterative_get_referring_type();
	}

	public static function getSuccessURL($type, $variant_id, $experiment_id, $experiment_type='headlines') {
                if(!defined("DONOTCACHEPAGE"))
                        define('DONOTCACHEPAGE', true);

		// only show this when a success is legitimate... that is, a click through from another page on the site w/ variant 
		return self::$api_endpoint . "js/success?experiment_id=" . $experiment_id . "&user=" . self::getUserID() . "&unique_id=" . self::getGUID() . "&type=" . $type . "&variant_id=" . $variant_id . "&experiment_type=" . $experiment_Type;
	}


	// models.
	public static function model_srs($post_id, $variant_hashes, $et=null) {
		return $variant_hashes[array_rand($variant_hashes)];
	}

	public static function model_sts($post_id, $variant_hashes, $experiment_type='headlines') {
		// return the hash of a single variant... tell the server that this user has that selected.	
		// tell the server right away about the variant, but in the future, do it more intelligently (users may see more than one variant on a page load).
		$parameters = self::getParameters($post_id, "sts", $experiment_type);

		$best = -INF;
		$best_hash = null;

		foreach($variant_hashes as $vh) {
			if(!isset($parameters[$vh]) || $parameters[$vh]['b'] <= 0) {
				// our experiment is somehow out of date.
				$parameters = IterativeAPI::updateExperiment($post_id, iterative_get_variants($post_id), $experiment_type);
			}

			if($parameters[$vh]['a'] <= 0 || $parameters[$vh]['b'] <= 0)
				return false;
			
			$draw = iterative_ib(iterative_urf($parameters[$vh]['c'],1), $parameters[$vh]['a'], $parameters[$vh]['b']);
			if($draw > $best)
			{
				$best = $draw;
				$best_hash = $vh;
			}
		}

		return $best_hash;
	}
}

?>
