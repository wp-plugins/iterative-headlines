<?php
	function iterative_get_referring_host() {
		return strtolower(iterative_remove_www(parse_url(wp_get_referer(), PHP_URL_HOST)));
	}

	function iterative_get_referring_type() {
		$host = iterative_remove_www(parse_url($_SERVER["HTTP_HOST"], PHP_URL_HOST));
		$ref = iterative_get_referring_host();
		if(empty($ref)) return "empty";

		$other = array("facebook" => "facebook.com", "twitter" => "twitter.com", "google" => "google\..*", "yahoo" => "yahoo.com", "bing" => "bing.com");
		if($ref == $host) return "onsite";
		foreach($other as $k=>$v) {
			if(preg_match("/".$v."/i", $ref)) return $k;
		}
		
		return "other";
	}

	function iterative_remove_www($host) {
		if(strtolower(substr($host, 0, 4)) == "www.") {
			return strtolower(substr($host, 4));
		} 
		return strtolower($host);
	}
