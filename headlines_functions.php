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
			if(preg_match("/.*".$v.".*/i", $ref)) return $k;
		}
		
		return "other";
	}

	function iterative_remove_www($host) {
		if(strtolower(substr($host, 0, 4)) == "www.") {
			return strtolower(substr($host, 4));
		} 
		return strtolower($host);
	}


	function iterative_supplement_url( $key, $value, $url) {
		// TODO: there might be an "add query arg" in wordpress that does this actually.

		$info = parse_url( $url );
		if(!isset($info['query']))
			$query = array();
		else
			parse_str( $info['query'], $query );

		$new_query = $query ? array_merge( $query, array($key => $value ) ) : array( $key => $value );
		$query_str = http_build_query( $new_query );
		
		$return = $info['scheme'] . '://' . $info['host'];
		if($info['port'] != 80) 
			$return .= ":" . $info['port'];
		$return .= $info['path'];
		if(!empty($query_str)) 
			$return .= '?' . $query_str;
		if(!empty($info['fragment']))
			$return .= "#" . $info['fragment'];

		return $return;
	}
