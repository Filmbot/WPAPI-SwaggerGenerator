<?php
/**
 * Metadata base class.
 */
class WP_REST_Swagger_Controller extends WP_REST_Controller {
	

	/**
	 * Construct the API handler object.
	 */
	public function __construct() {
		$this->namespace = 'wp/v2';
	}

	/**
	 * Register the meta-related routes.
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/swagger', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_swagger' ),
				'permission_callback' => array( $this, 'get_swagger_permissions_check' ),
				'args'                => $this->get_swagger_params(),
			),
			

			'schema' => array( $this, 'get_swagger_schema' ),
		) );
	}
	
	public function get_swagger_params() {
		$new_params = array();
		return $new_params;
	}
	
	public function get_swagger_permissions_check( $request ) {
		return true;
	}

	/**
	 * Retrieve custom swagger object.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Request|WP_Error Meta object data on success, WP_Error otherwise
	 */
	public function get_swagger( $request ) {
		$basePath = '/';
		global $wp_rewrite;
		
		if($wp_rewrite->root!='/'){
			$basePath = '/'.$wp_rewrite->root;//'/'.$matches[1].'/';
		}
		
		$swagger = array(
				'swagger'=>'2.0'
				,'info'=>array(
					'version'=>'1.0'
					,'title'=>wp_title('',0)
				)
				,'host'=>preg_replace('/(^https?:\/\/|\/$)/','',site_url('/'))
				,'basePath'=>$basePath
				,'schemes'=>array(preg_match('/^https:/i',site_url()) ? 'https' : 'http')
				,'consumes'=>array('multipart/form-data')
				,'produces'=>array('application/json')
				,'paths'=>array()
				,'definitions'=>array()
				,'securityDefinitions'=>array(
					"cookieAuth"=>array(
						"type"=> "apiKey",
						"name"=> "X-WP-Nonce",
						"in"=> "header",
						"description"=>"Please see http://v2.wp-api.org/guide/authentication/"
					)
				 
				)
			);
		
		$security = array(
			array('cookieAuth'=>array())
		);
        
		
		if(function_exists('rest_oauth1_init')){
			$swagger['securityDefinitions']['oauth']=array(
				'type'=>'oauth2'
				,'x-oauth1'=> true
				,'flow'=> 'accessCode'
				,'authorizationUrl'=> site_url().$basePath.'oauth1/authorize'
				,'tokenUrl'=> site_url().$basePath.'oauth1/request'
				,'x-accessUrl'=> site_url().$basePath.'oauth1/access'
				,'scopes'=>array(
				  'basic'=> 'OAuth authentication uses the OAuth 1.0a specification (published as
					RFC5849)'
					)
			);
			$security[] = 	array('oauth'=>array('basic'));
		}
		
		
		$restServer = rest_get_server();
		
		foreach($restServer->get_routes() as $endpointName => $endpoint){
			
			// don't include self - that's a bit meta
			if($endpointName=='/wp/v2/swagger') continue; 
			
			$routeopt = $restServer->get_route_options( $endpointName );
			if(!empty($routeopt['schema'][1])){
				$schema = call_user_func(array(
					$routeopt['schema'][0]
					,$routeopt['schema'][1])
				);
				$swagger['definitions'][$schema['title']]=$this->schemaIntoDefinition($schema);
				$outputSchema = array('$ref'=>'#/definitions/'.$schema['title']);
			}else{
				//if there is no schema then it's a safe bet that this API call 
				//will not work - move to the next one.
				continue;
			}
			
			
			
			$defaultidParams = array();
			//Replace endpoints var and add to the parameters required
			$endpointName = preg_replace_callback(
				'#\(\?P<(\w+?)>.*?\)#',
				function ($matches) use (&$defaultidParams){
					$defaultidParams[]=array(
							'name'=>$matches[1]
							,'type'=>'string'
							,'in'=>'path'
						);
					return '{'.$matches[1].'}';
				},
				$endpointName
			);
			
			if(empty($swagger['paths'][$endpointName])){
				$swagger['paths'][$endpointName] = array();
			}
			
			
			foreach($endpoint as $endpointPart){
				
				foreach($endpointPart['methods'] as $methodName=>$method){
					$parameters = $defaultidParams;
					
					//Clean up parameters
					foreach ($endpointPart['args'] as $pname=>$pdetails){
						if($pname=='force'){
							$d=0;
						}
						$parameter=array(
							'name'=>$pname
							,'type'=>'string'
							,'in'=>'formData'
						);
						if(!empty($pdetails['description']))$parameter['description']=$pdetails['description'];
						if(!empty($pdetails['format']))$parameter['format']=$pdetails['format'];
						if(!empty($pdetails['default']))$parameter['default']=$pdetails['default'];
						if(!empty($pdetails['enum']))$parameter['enum']=$pdetails['enum'];
						if(!empty($pdetails['required']))$parameter['required']=$pdetails['required'];
						if(!empty($pdetails['minimum'])){
							$parameter['minimum']=$pdetails['minimum'];
							$parameter['format']='number';
						}
						if(!empty($pdetails['maximum'])){
							$parameter['maximum']=$pdetails['maximum'];
							$parameter['format']='number';
						}
						if(!empty($pdetails['type'])){
							if($pdetails['type']=='array'){
								$parameter['type']=$pdetails['type'];
								$parameter['items']=array('type'=>'string');
							}else{
								$parameter['type']=$pdetails['type'];
							}
						}
						
						$parameters[]=$parameter;
					}
					
					//If the endpoint is not grabbing a specific object then 
					//assume it's returning a list
					$outputSchemaForMethod = $outputSchema;
					if($methodName=='GET' && !preg_match('/}$/',$endpointName)){
						$outputSchemaForMethod = array(
							'type'=>'array'
							,'items'=>$outputSchemaForMethod
						);
					}
					
					
					$swagger['paths'][$endpointName][$methodName] = array(
						'parameters'=>$parameters
						,'security'=>$security
						,'responses'=>array(
							200=>array(
								'schema'=>$outputSchemaForMethod
							)
						)
					);
					
				}
			}
		}

		$response = rest_ensure_response( $swagger );
		
		return apply_filters( 'rest_prepare_meta_value', $response, $request );
	}
	
	/**
	 * Turns the schema set up by the endpoint into a swagger definition.
	 *
	 * @param array $schema
	 * @return array Definition
	 */
	private function schemaIntoDefinition($schema){
		if(!empty($schema['$schema']))unset($schema['$schema']);
		if(!empty($schema['title']))unset($schema['title']);
		foreach($schema['properties'] as &$prop){
			
			if(!empty($prop['properties'])){
				$prop = $this->schemaIntoDefinition($prop);
			}
			
			if(!empty($prop['context'])){
				$prop['enum']=$prop['context'];
				unset($prop['context']);
			}
			if(!empty($prop['readonly']))unset($prop['readonly']);
			
			
		}
		
		return $schema;
	}
	
	/**
	 * Get the meta schema, conforming to JSON Schema
	 *
	 * @return array
	 */
	public function get_swagger_schema() {
		$schema = json_decode(file_get_contents(dirname(__FILE__).'/schema.json'),1);
		return $schema;
	}
	
}