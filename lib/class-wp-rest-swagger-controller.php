<?php

/**
 * Swagger base class.
 */
class WP_REST_Swagger_Controller extends WP_REST_Controller
{


	/**
	 * Construct the API handler object.
	 */
	public function __construct()
	{
		$this->namespace = 'apigenerate';
	}

	/**
	 * Register the meta-related routes.
	 */
	public function register_routes()
	{
		register_rest_route(
			$this->namespace, '/swagger', [
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_swagger' ],
					'permission_callback' => [ $this, 'get_swagger_permissions_check' ],
					'args'                => $this->get_swagger_params(),
				],

				'schema' => [ $this, 'get_swagger_schema' ],
			]
		);
	}

	public function get_swagger_params()
	{
		$new_params = [];

		return $new_params;
	}

	public function get_swagger_permissions_check( $request )
	{
		return true;
	}

	function getSiteRoot( $path = '' )
	{
		global $wp_rewrite;
		$rootURL = site_url();

		if ( $wp_rewrite->using_index_permalinks() )
		{
			$rootURL .= '/' . $wp_rewrite->index;
		}

		$rootURL .= '/' . $path;

		return $rootURL;
	}

	/**
	 * Retrieve custom swagger object.
	 *
	 * @param  WP_REST_Request $request
	 *
	 * @return WP_REST_Request|WP_Error Meta object data on success, WP_Error otherwise
	 */
	public function get_swagger( $request )
	{

		global $wp_rewrite;

		$title = wp_title( '', 0 );
		list( $host, $pathPrefix ) = explode( '/', preg_replace( '/(^https?:\/\/|\/$)/', '', site_url( '/' ) ), 2 );
		if ( empty( $title ) )
		{
			$title = $host;
		}

		$apiPath     = get_rest_url();
		$restServer  = rest_get_server();
		$queryParams = $request->get_query_params();
		$namespace   = $queryParams['namespace'] ?? '';
		$basePath    = preg_replace( '#https?://#', '', $apiPath );
		$basePath    = str_replace( $host . '/' . $pathPrefix, '', $basePath );
		$basePath    = preg_replace( '#/$#', '', $basePath ) . ( $namespace ? '/' . $namespace : '' );

		/* foreach ( $restServer->get_namespaces() as $namespace ) { */
		$namespaceData = $namespace ? $restServer->get_namespace_index(
			[
				'namespace' => $namespace,
				'context'   => 'help',
			]
		)->data : [
			'routes' => $restServer->get_data_for_routes( $restServer->get_routes(), 'help' ),
		];

		$swagger = [
			'swagger'             => '2.0',
			'info'                => [
				'version'     => '1.0',
				'title'       => $title,
				'description' => $namespaceData['description'] ?? '',
			],
			'host'                => $host,
			'basePath'            => $basePath,
			'schemes'             => [ ( is_ssl() | force_ssl_admin() ) ? 'https' : 'http' ],
			'consumes'            => [ 'multipart/form-data' ],
			'produces'            => [ 'application/json' ],
			'paths'               => [],
			'definitions'         => [
				'error' => [
					'properties' => [
						'code'    => [
							'type' => 'string',
						],
						'message' => [
							'type' => 'string',
						],
						'data'    => [
							'type'       => 'object',
							'properties' => [
								'status' => [
									'type' => 'integer',
								],
							],
						],
					],
				],
			],
			'securityDefinitions' => [
				'cookieAuth' => [
					'type'        => 'apiKey',
					'name'        => 'X-WP-Nonce',
					'in'          => 'header',
					'description' => 'Please see http://v2.wp-api.org/guide/authentication/',
				],

			],
		];

		$security = [
			[ 'cookieAuth' => [] ],
		];

		if ( function_exists( 'rest_oauth1_init' ) )
		{
			$swagger['securityDefinitions']['oauth'] = [
				'type'             => 'oauth2',
				'x-oauth1'         => true,
				'flow'             => 'accessCode',
				'authorizationUrl' => $this->getSiteRoot( 'oauth1/authorize' ),
				'tokenUrl'         => $this->getSiteRoot( 'oauth1/request' ),
				'x-accessUrl'      => $this->getSiteRoot( 'oauth1/access' ),
				'scopes'           => [
					'basic' => 'OAuth authentication uses the OAuth 1.0a specification (published as RFC5849)',
				],
			];
			$security[]                              = [ 'oauth' => [ 'basic' ] ];
		}

		if ( class_exists( 'WO_Server' ) )
		{
			$rootURL = site_url();

			if ( $wp_rewrite->using_index_permalinks() )
			{
				$rootURL .= '/' . $wp_rewrite->index;
			}

			$swagger['securityDefinitions']['oauth'] = [
				'type'             => 'oauth2',
				'flow'             => 'accessCode',
				'authorizationUrl' => $rootURL . '/oauth/authorize',
				'tokenUrl'         => $rootURL . '/oauth/token',
				'scopes'           => [
					'openid' => 'openid',
				],
			];
			$security[]                              = [ 'oauth' => [ 'openid' ] ];
		}

		if ( class_exists( 'Application_Passwords' ) || function_exists( 'json_basic_auth_handler' ) )
		{
			$swagger['securityDefinitions']['basicAuth'] = [
				'type' => 'basic',
			];
			$security[]                                  = [ 'basicAuth' => [ '' ] ];
		}

		foreach ( $namespaceData['routes'] as $endpointName => $endpoint )
		{

			// don't include self - that's a bit meta
			if ( $endpointName == '/' . $this->namespace . '/swagger' )
			{
				continue;
			}

			// filter routes by namespace
			if ( $namespace and strpos( $endpointName, '/' . $namespace ) !== 0 )
			{
				continue;
			}

			if ( empty( $endpoint['schema'] ) )
			{
				//if there is no schema then it's a safe bet that this API call
				//will not work - move to the next one.
				continue;
			}
			$schema                                     = $endpoint['schema'];
			$swagger['definitions'][ $schema['title'] ] = $this->schemaIntoDefinition( $schema );
			$outputSchema                               = [ '$ref' => '#/definitions/' . $schema['title'] ];

			$pathParams = [];
			// Replace endpoints var and add to the parameters required
			$endpointName = preg_replace_callback(
				'#\(\?P<(\w+?)>.*?\)#',
				function ( $matches ) use ( &$pathParams )
				{
					$pathParams[] = $matches[1];

					return '{' . $matches[1] . '}';
				},
				$endpointName
			);
			$endpointName = str_replace( site_url(), '', rest_url( $endpointName ) );
			$endpointName = str_replace( $basePath, '', $endpointName );

			$groupName = $endpoint['_groupname'] ?? (
				basename( $endpointName ) == '{id}' ? basename( dirname( $endpointName ) ) : basename( $endpointName )
				);

			if ( empty( $swagger['paths'][ $endpointName ] ) )
			{
				$swagger['paths'][ $endpointName ] = [
					'x-group-name'  => $groupName,
					'x-description' => $endpoint['_description'] ?? '',
				];
			}

			foreach ( $endpoint['endpoints'] as $endpointPart )
			{
				foreach ( $endpointPart['methods'] as $methodName )
				{
					if ( in_array( $methodName, [ 'PUT', 'PATCH' ] ) )
					{
						continue; //duplicated by post
					}

					$parameters = [];
					foreach ( $pathParams as $pname )
					{
						if ( $methodName == 'POST' || ! array_key_exists( $pname, $endpointPart['args'] ) )
						{
							$parameters[] = [
								'name'     => $pname,
								'type'     => 'string',
								'in'       => 'path',
								'required' => true,
							];
						}
					}

					//Clean up parameters
					foreach ( $endpointPart['args'] as $pname => $pdetails )
					{
						if ( in_array( $pname, [ 'context' ] ) )
						{
							continue;
						}
						$isPathParam = in_array( $pname, $pathParams );
						$parameter   = [
							'name'     => $pname,
							'type'     => 'string',
							'in'       => $methodName == 'POST' ? 'formData' : ( $isPathParam ? 'path' : 'query' ),
							'required' => $isPathParam,
						];
						if ( ! empty( $pdetails['description'] ) )
						{
							$parameter['description'] = $pdetails['description'];
						}
						if ( ! empty( $pdetails['format'] ) )
						{
							$parameter['format'] = $pdetails['format'];
						}
						if ( ! empty( $pdetails['default'] ) )
						{
							$parameter['default'] = $pdetails['default'];
						}
						if ( ! empty( $pdetails['enum'] ) )
						{
							$parameter['enum'] = $pdetails['enum'];
						}
						if ( ! empty( $pdetails['required'] ) )
						{
							$parameter['required'] = $pdetails['required'];
						}
						if ( ! empty( $pdetails['minimum'] ) )
						{
							$parameter['minimum'] = $pdetails['minimum'];
							$parameter['format']  = 'number';
						}
						if ( ! empty( $pdetails['maximum'] ) )
						{
							$parameter['maximum'] = $pdetails['maximum'];
							$parameter['format']  = 'number';
						}
						if ( ! empty( $pdetails['type'] ) )
						{
							if ( $pdetails['type'] == 'array' )
							{
								$parameter['type']  = $pdetails['type'];
								$arrType            = $pdetails['items']['type'] ?? 'string';
								$parameter['items'] = [ 'type' => $arrType ];
							}
							elseif ( in_array( $pdetails['type'], [ 'string', 'number', 'integer', 'boolean', 'file' ] ) )
							{
								$parameter['type'] = $pdetails['type'];
							}
							else
							{
								$parameter['type']   = 'string';
								$parameter['format'] = is_string( $pdetails['type'] ) ? $pdetails['type'] : 'string';
							}
						}

						$parameters[] = $parameter;
					}

					//If the endpoint is not grabbing a specific object then
					//assume it's returning a list
					$outputSchemaForMethod = $outputSchema;
					if ( $methodName == 'GET' && ! preg_match( '/}$/', $endpointName ) )
					{
						$outputSchemaForMethod = [
							'type'  => 'array',
							'items' => $outputSchemaForMethod,
						];
					}

					$responses = [
						'default' => [
							'description' => 'error',
							'schema'      => [ '$ref' => '#/definitions/error' ],
						],
					];

					if ( in_array( $methodName, [ 'POST', 'PATCH', 'PUT' ] ) && ! preg_match( '/}$/', $endpointName ) )
					{
						// This are actually 201's in the default API - but joy of joys this is unreliable
						$responses[201] = [
							'description' => 'successful operation',
							'schema'      => $outputSchemaForMethod,
						];
					}
					elseif ( in_array( $methodName, [ 'DELETE' ] ) )
					{
						$responses[200] = [
							'description' => 'successful operation',
						];
					}
					else
					{
						$responses[200] = [
							'description' => 'successful operation',
							'schema'      => $outputSchemaForMethod,
						];
					}

					$swagger['paths'][ $endpointName ][ strtolower( $methodName ) ] = [
						'parameters' => $parameters,
						'security'   => $security,
						'responses'  => $responses,
						'summary'    => "$methodName $groupName",
					];

				}
			}
		}

		$response = rest_ensure_response( $swagger );

		return apply_filters( 'rest_prepare_meta_value', $response, $request );
	}

	/**
	 * Turns the schema set up by the endpoint into a swagger definition.
	 *
	 * @param  array $schema
	 *
	 * @return array Definition
	 */
	private function schemaIntoDefinition( $schema )
	{
		if ( ! empty( $schema['$schema'] ) )
		{
			unset( $schema['$schema'] );
		}
		if ( ! empty( $schema['title'] ) )
		{
			unset( $schema['title'] );
		}
		if ( isset( $schema['context'] ) )
		{
			unset( $schema['context'] );
		}
		foreach ( $schema['properties'] as $name => &$prop )
		{

			if ( ! empty( $prop['properties'] ) )
			{
				/* $prop = $this->schemaIntoDefinition( $prop ); */
				unset( $prop['properties'] );
			}

			//-- Changes by Richi
			if ( ! empty( $prop['enum'] ) )
			{
				if ( $prop['enum'][0] == '' )
				{
					if ( count( $prop['enum'] ) > 1 )
					{
						array_shift( $prop['enum'] );
					}
					else
					{
						$prop['enum'][0] = 'NONE';
					}
				};
			}

			if ( $prop['type'] == 'array' )
			{
				$arrType       = $prop['items']['type'] ?? 'string';
				$prop['items'] = [ 'type' => $arrType ];
			}
			elseif ( ! in_array( $prop['type'], [ 'string', 'number', 'integer', 'boolean', 'file' ] ) )
			{
				$prop['type']   = 'string';
				$prop['format'] = is_string( $prop['type'] ) ? $prop['type'] : 'string';
			}

			if ( isset( $prop['name'] ) )
			{
				unset( $prop['name'] );
			}
			if ( isset( $prop['required'] ) )
			{
				unset( $prop['required'] );
			}
			if ( isset( $prop['readonly'] ) )
			{
				unset( $prop['readonly'] );
			}
			if ( isset( $prop['context'] ) )
			{
				unset( $prop['context'] );
			}
			if ( isset( $prop['arg_options'] ) )
			{
				unset( $prop['arg_options'] );
			}
		}

		return $schema;
	}

	/**
	 * Get the meta schema, conforming to JSON Schema
	 *
	 * @return array
	 */
	public function get_swagger_schema()
	{
		$schema = json_decode( file_get_contents( dirname( __FILE__ ) . '/schema.json' ), 1 );

		return $schema;
	}

}
