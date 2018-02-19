<?php
class Jetpack_Provision {
	static function partner_provision( $access_token, $named_args ) {
		$url_args = array(
			'home_url' => 'WP_HOME',
			'site_url' => 'WP_SITEURL',
		);

		foreach ( $url_args as $url_arg => $constant_name ) {
			// Anonymous functions were introduced in 5.3.0. So, if we're running on
			// >= 5.3.0, use an anonymous function to set the home/siteurl value%s.
			//
			// Otherwise, fallback to setting the home/siteurl value via the WP_HOME and
			// WP_SITEURL constants if the constant hasn't already been defined.
			if ( isset( $named_args[ $url_arg ] ) ) {
				if ( version_compare( phpversion(), '5.3.0', '>=') ) {
					add_filter( $url_arg, function( $url ) use ( $url_arg, $named_args ) {
						return $named_args[ $url_arg ];
					}, 11 );
				} else if ( ! defined( $constant_name ) ) {
					define( $constant_name, $named_args[ $url_arg ] );
				}
			}
		}

		$blog_id    = Jetpack_Options::get_option( 'id' );
		$blog_token = Jetpack_Options::get_option( 'blog_token' );

		if ( ! $blog_id || ! $blog_token || ( isset( $named_args['force_register'] ) && intval( $named_args['force_register'] ) ) ) {
			// this code mostly copied from Jetpack::admin_page_load
			Jetpack::maybe_set_version_option();
			$registered = Jetpack::try_registration();
			if ( is_wp_error( $registered ) ) {
				self::partner_provision_error( $registered );
			} elseif ( ! $registered ) {
				self::partner_provision_error( new WP_Error( 'registration_error', __( 'There was an unspecified error registering the site', 'jetpack' ) ) );
			}

			$blog_id    = Jetpack_Options::get_option( 'id' );
			$blog_token = Jetpack_Options::get_option( 'blog_token' );
		}

		// if the user isn't specified, but we have a current master user, then set that to current user
		if ( ! get_current_user_id() && $master_user_id = Jetpack_Options::get_option( 'master_user' ) ) {
			wp_set_current_user( $master_user_id );
		}

		$site_icon = ( function_exists( 'has_site_icon') && has_site_icon() )
			? get_site_icon_url()
			: false;

		$auto_enable_sso = ( ! Jetpack::is_active() || Jetpack::is_module_active( 'sso' ) );

		/** This filter is documented in class.jetpack-cli.php */
		if ( apply_filters( 'jetpack_start_enable_sso', $auto_enable_sso ) ) {
			$redirect_uri = add_query_arg(
				array( 'action' => 'jetpack-sso', 'redirect_to' => urlencode( admin_url() ) ),
				wp_login_url() // TODO: come back to Jetpack dashboard?
			);
		} else {
			$redirect_uri = admin_url();
		}

		$request_body = array(
			'jp_version'    => JETPACK__VERSION,
			'redirect_uri'  => $redirect_uri
		);

		if ( $site_icon ) {
			$request_body['site_icon'] = $site_icon;
		}

		if ( get_current_user_id() ) {
			$user = wp_get_current_user();

			// role
			$role = Jetpack::translate_current_user_to_role();
			$signed_role = Jetpack::sign_role( $role );

			$secrets = Jetpack::init()->generate_secrets( 'authorize' );

			// Jetpack auth stuff
			$request_body['scope']  = $signed_role;
			$request_body['secret'] = $secrets['secret_1'];

			// User stuff
			$request_body['user_id']    = $user->ID;
			$request_body['user_email'] = $user->user_email;
			$request_body['user_login'] = $user->user_login;
		}

		// optional additional params
		if ( isset( $named_args['wpcom_user_id'] ) && ! empty( $named_args['wpcom_user_id'] ) ) {
			$request_body['wpcom_user_id'] = $named_args['wpcom_user_id'];
		}

		// override email of selected user
		if ( isset( $named_args['wpcom_user_email'] ) && ! empty( $named_args['wpcom_user_email'] ) ) {
			$request_body['user_email'] = $named_args['wpcom_user_email'];
		}

		if ( isset( $named_args['plan'] ) && ! empty( $named_args['plan'] ) ) {
			$request_body['plan'] = $named_args['plan'];
		}

		if ( isset( $named_args['onboarding'] ) && ! empty( $named_args['onboarding'] ) ) {
			$request_body['onboarding'] = intval( $named_args['onboarding'] );
		}

		if ( isset( $named_args['force_connect'] ) && ! empty( $named_args['force_connect'] ) ) {
			$request_body['force_connect'] = intval( $named_args['force_connect'] );
		}

		if ( isset( $request_body['onboarding'] ) && (bool) $request_body['onboarding'] ) {
			Jetpack::create_onboarding_token();
		}

		$request = array(
			'headers' => array(
				'Authorization' => "Bearer " . $access_token,
				'Host'          => defined( 'JETPACK__WPCOM_JSON_API_HOST_HEADER' ) ? JETPACK__WPCOM_JSON_API_HOST_HEADER : 'public-api.wordpress.com',
			),
			'timeout' => 60,
			'method'  => 'POST',
			'body'    => json_encode( $request_body )
		);

		$url = sprintf( 'https://%s/rest/v1.3/jpphp/%d/partner-provision', self::get_api_host(), $blog_id );
		if ( ! empty( $named_args['partner-tracking-id'] ) ) {
			$url = esc_url_raw( add_query_arg( 'partner_tracking_id', $named_args['partner-tracking-id'], $url ) );
		}

		// add calypso env if set
		if ( getenv( 'CALYPSO_ENV' ) ) {
			$url = add_query_arg( array( 'calypso_env' => getenv( 'CALYPSO_ENV' ) ), $url );
		}

		$result = Jetpack_Client::_wp_remote_request( $url, $request );

		if ( is_wp_error( $result ) ) {
			self::partner_provision_error( $result );
		}

		$response_code = wp_remote_retrieve_response_code( $result );
		$body_json     = json_decode( wp_remote_retrieve_body( $result ) );

		if( 200 !== $response_code ) {
			if ( isset( $body_json->error ) ) {
				self::partner_provision_error( new WP_Error( $body_json->error, $body_json->message ) );
			} else {
				self::partner_provision_error( new WP_Error( 'server_error', sprintf( __( "Request failed with code %s" ), $response_code ) ) );
			}
		}

		if ( isset( $body_json->access_token ) ) {
			// authorize user and enable SSO
			Jetpack::update_user_token( $user->ID, sprintf( '%s.%d', $body_json->access_token, $user->ID ), true );

			/**
			 * Auto-enable SSO module for new Jetpack Start connections
			 *
			 * @since 5.0.0
			 *
			 * @param bool $enable_sso Whether to enable the SSO module. Default to true.
			 */
			$other_modules = apply_filters( 'jetpack_start_enable_sso', true )
				? array( 'sso' )
				: array();

			if ( $active_modules = Jetpack_Options::get_option( 'active_modules' ) ) {
				Jetpack::delete_active_modules();
				Jetpack::activate_default_modules( 999, 1, array_merge( $active_modules, $other_modules ), false );
			} else {
				Jetpack::activate_default_modules( false, false, $other_modules, false );
			}
		}
		return $body_json;
	}

	private static function partner_provision_error( $error ) {
		error_log( json_encode( array(
			'success'       => false,
			'error_code'    => $error->get_error_code(),
			'error_message' => $error->get_error_message()
		) ) );
		exit( 1 );
	}

	private static function get_api_host() {
		$env_api_host = getenv( 'JETPACK_START_API_HOST', true );
		return $env_api_host ? $env_api_host : JETPACK__WPCOM_JSON_API_HOST;
	}
}