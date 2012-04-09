<?php

/**
 * Extends the Restler class for some BP-specific modifications
 */
class BP_Restler extends Restler {
	/**
	* Parses the requst url and get the api path
	*
	* Extended from the Restler base to account for the unique way in which WP/BP builds URLs
	*
	* @return string api path
	*/
	protected function getPath () {
		global $bp;
		
		$path = str_replace( bp_get_root_domain() . '/' . $bp->pages->api->slug . '/', '', wp_guess_url() );
		
		$path = preg_replace('/(\/*\?.*$)|(\/$)/', '', $path);
		$path = str_replace($this->format_map['extensions'], '', $path);
		return $path;
	}
	
	/**
	 * Generates cachable url to method mapping
	 * @param string $class_name
	 * @param string $base_path
	 */
	protected function generateMap ($class_name, $base_path = '') {
		$reflection = new ReflectionClass($class_name);
		$class_metadata = parse_doc($reflection->getDocComment());
		$methods = $reflection->getMethods(
		ReflectionMethod::IS_PUBLIC + ReflectionMethod::IS_PROTECTED);
		foreach ($methods as $method) {
			$doc = $method->getDocComment();
			$arguments = array();
			$defaults = array();
			$metadata = $class_metadata+parse_doc($doc);
			$params = $method->getParameters();
			$position=0;
			foreach ($params as $param){
				$arguments[$param->getName()] = $position;
				$defaults[$position] = $param->isDefaultValueAvailable() ?
					$param->getDefaultValue() : NULL;
				$position++;
			}
			
			$method_flag = $method->isProtected() ?
			(isRestlerCompatibilityModeEnabled() ? 2 :  3) :
			(isset($metadata['protected']) ? 1 : 0);

			//take note of the order
			$call = array(
			'class_name'=>$class_name,
			'method_name'=>$method->getName(),
			'arguments'=>$arguments,
			'defaults'=>$defaults,
			'metadata'=>$metadata,
			'method_flag'=>$method_flag
			);
			$method_url = strtolower($method->getName());
			
			if (preg_match_all(
			'/@url\s+(GET|POST|PUT|DELETE|HEAD|OPTIONS)[ \t]*\/?(\S*)/s', 
			$doc, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$http_method = $match[1];
					$url = rtrim($match[2],'/');
					$this->routes[$http_method][$url] = $call;
				}
			}elseif($method_url[0] != '_'){ //not prefixed with underscore
				// no configuration found so use convention
				if (preg_match_all('/^(GET|POST|PUT|DELETE|HEAD|OPTIONS)/i', 
				$method_url, $matches)) {
					$http_method = strtoupper($matches[0][0]);
					$method_url = substr($method_url, strlen($http_method));
				}else{
					$http_method = 'GET';
				}
				$url = $base_path. ($method_url=='index' || 
				$method_url=='default' ? '' : $method_url);
				$url = rtrim($url,'/');
				$this->routes[$http_method][$url] = $call;
				foreach ($params as $param){
					if($param->getName()=='request_data'){
						break;
					}
					$url .= $url=='' ? ':' : '/:';
					$url .= $param->getName();
					$this->routes[$http_method][$url] = $call;
				}
			}
		}
	}
	
	protected function mapUrlToMethod () {
		if(!isset($this->routes[$this->request_method])){
			return array();
		}
		$urls = $this->routes[$this->request_method];
		if(!$urls)return array();

		$found = FALSE;
		$this->request_data += $_GET;
		$params = array('request_data'=>$this->request_data);
		$params += $this->request_data;
		
		foreach ($urls as $url => $call) {
			//echo PHP_EOL.$url.' = '.$this->url.PHP_EOL;
			$call = (object)$call;
			if ( 0 === strpos( $this->url, $url ) && isset( $params['action'] ) && $params['action'] == $call->method_name ){
				$item_type = array_pop( explode( '/', $url ) );
				$url_a = explode( '/', $this->url );
				$item_type_key = array_search( $item_type, $url_a );
				
				if ( false !== $item_type_key ) {
					switch ( $item_type ) {
						case 'user' :
							if ( isset( $url_a[$item_type_key + 1] ) ) {
								$params['user_id'] = urldecode( $url_a[$item_type_key + 1] ); 
							}
							break;
						
					}
				}
				
				$found = TRUE;
				break;
			}
		}
		
		if ( $found ) {
			$p = $call->defaults;
			foreach ( $call->arguments as $key => $value ) {
				if ( isset( $params[$key] ) ) {
					$p[$value] = $this->_process_param( $value, $params[$key] );
				}
			}
			$call->arguments = $p;
//			print_r( $call->arguments );
			return $call;
		}
	}
	
		
	/**
	 * Fetch the data corresponding to a given param key.
	 *
	 * Most of this data gets validated somehow. We cast to the proper type, and we check to
	 * make sure the referenced object exists
	 *
	 * @param string $key The parameter key
	 * @return mixed The validated return content
	 */
	function _process_param( $key = false, $value = '' ) {
		
		switch( $key ) {
			case 'user_id' :
				// Check that the user exists. If not, $value is set to 0
				$user = new WP_User( $value );
				$value = $user->ID;
				
				break;
			
			case 'profile_field_id' :			
				if ( !bp_is_active( 'xprofile' ) ) {
					return 0;
				}
		
				// You can pass a field id or name
				if ( is_numeric( $field ) ) {
					$field_id = $field;
				} else {
					$field_id = xprofile_get_field_id_from_name( $field );
				}
				
				// Make sure the field exists
				$field_obj = xprofile_get_field( $field_id );
				
				$value = (int) $field_obj->id;
				
				break;
			
			case 'profile_field_data' :
				if ( !bp_is_active( 'xprofile' ) ) {
					return 0;
				}
				
				break;
			
			default :
				// @todo I think this is where a hook will go?
				break;
			
		}
		
		return $value;
	}
}

?>