<?php

class PluginUpdater {

	private $plugin_path;
	private $plugin_file;
	private $plugin_data;
	private $plugin_slug;

	private $username;
	private $repository;
	private $access_token;

	private $github_info;
	private $plugin_activated;

	/**
	 * Class constructor.
	 *
	 * @param  string $pluginFile
	 * @param  string $gitHubUsername
	 * @param  string $gitHubProjectName
	 * @param  string $accessToken
	 * @return null
	 */
	function __construct( $repository, $plugin_file, $access_token = '' ) {

		$this->plugin_path	= $plugin_file;
		$this->plugin_file 	= plugin_basename( $this->plugin_path );
		$this->plugin_data 	= get_plugin_data( $this->plugin_path, false, false );
		$this->plugin_slug 	= basename( $this->plugin_file, '.php' );

		$this->access_token = $access_token;
		$path = @parse_url( $repository, PHP_URL_PATH );
		if ( preg_match( '@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches ) ) {
			$this->username 	= $matches['username'];
			$this->repository 	= $matches['repository'];
		} else {
			throw new InvalidArgumentException( 'Invalid GitHub repository URL: "' . $repository . '"' );
		}

		add_filter( "pre_set_site_transient_update_plugins", array( $this, "set_transitent" ) );
		add_filter( "plugins_api", array( $this, "plugin_info" ), 10, 3 );
		add_filter( "upgrader_pre_install", array( $this, "pre_install" ), 10, 3 );
		add_filter( "upgrader_post_install", array( $this, "post_install" ), 10, 3 );
	}

	/**
	 * Get information regarding our plugin from GitHub
	 *
	 * @return null
	 */
	private function release_info( $version = '' ) {
		// Query the GitHub API
		$url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/";
		if ( empty( $version ) ) {
			$url .= 'latest';
		} else {
			$url .= 'tags/' . $version;
		}

		if ( ! empty( $this->access_token ) ) {
			$url = add_query_arg( array( "access_token" => $this->access_token ), $url );
		}

		// Get the results
		$release_info = wp_remote_retrieve_body( wp_remote_get( $url ) );

		if ( ! empty( $release_info ) ) {
			$release_info = @json_decode( $release_info );
		}

		if ( is_object( $release_info ) && property_exists( $release_info, 'message' ) ) {
			if ( $release_info->message == 'Not Found' ) {
				return false;
			}
		}

		return $release_info;
	}

	/**
	 * Push in plugin version information to get the update notification
	 *
	 * @param  object $transient
	 * @return object
	 */
	public function set_transitent( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get GitHub release information
		$release = $this->release_info();
		if ( empty( $release ) ) {
			return $transient;
		}

		if ( version_compare( $release->tag_name, $transient->checked[ $this->plugin_file ] ) ) {
			$package = $release->zipball_url;

			if ( ! empty( $this->access_token ) ) {
				$package = add_query_arg( array( "access_token" => $this->access_token ), $package );
			}

			$transient->response[ $this->plugin_file ] = (object)array(
				'slug'			=> $this->plugin_slug,
				'plugin'		=> $this->plugin_file,
				'new_version'	=> $release->tag_name,
				'url'			=> $this->plugin_data["PluginURI"],
				'package'		=> $package
			);
		}

		return $transient;
	}

	/**
	 * Push in plugin version information to display in the details lightbox
	 *
	 * @param  boolean $false
	 * @param  string $action
	 * @param  object $response
	 * @return object
	 */
	public function plugin_info( $false, $action, $response ) {

		if ( empty( $response->slug ) || $response->slug != $this->plugin_slug ) {
			return $false;
		}

		// Get GitHub release information
		$release = $this->release_info();
		if ( empty( $release ) ) {
			return $response;
		}

		// Add our plugin information
		$response->last_updated 	= $release->created_at;
		$response->slug 			= $this->plugin_slug;
		$response->name  			= $this->plugin_data["Name"];
		$response->version 			= $release->tag_name;
		$response->author 			= $this->plugin_data["AuthorName"];
		$response->homepage 		= $this->plugin_data["PluginURI"];

		// This is our release download zip file
		$download_link = $release->zipball_url;

		if ( ! empty( $this->access_token ) ) {
			$download_link = add_query_arg(
				array( "access_token" => $this->access_token ),
				$download_link
			);
		}

		$response->download_link = $download_link;

		// Load Parsedown
		require_once __DIR__ . DIRECTORY_SEPARATOR . 'parsedown.php';

		$change_text = $release->body;
		$change_data = $this->parse( $change_text );
		if ( false !== ( $text = strstr( $change_text, '<!--', true ) ) ) {
			$change_text = $text;
		}

		$readme_text = file_get_contents( dirname( $this->plugin_path ) . '/README.md' );
		$readme_data = $this->parse( $readme_text );
		if ( false !== ( $text = strstr( $readme_text, '<!--', true ) ) ) {
			$readme_text = $text;
		}

		$change_log = file_get_contents( "https://raw.githubusercontent.com/{$this->username}/{$this->repository}/master/CHANGELOG.txt" );
		if ( false == $change_log ) {
			$change_log = file_get_contents( dirname( $this->plugin_path ) . '/CHANGELOG.txt' );
		}

		// Add Tabs
		$response->sections = array(
			'description' 	=> Parsedown::instance()->parse( $readme_text ),
			'changelog' 	=> Parsedown::instance()->parse( $change_log ),
			'release_info' 	=> Parsedown::instance()->parse( $change_text )
		);

		// set contributors
		if ( isset( $readme_data['contributors'] ) || isset( $change_data['contributors'] ) ) {
			$contributors = isset( $change_data['contributors'] ) ? $change_data['contributors'] : $readme_data['contributors'];
			$contributors = explode( ',', $contributors );

			$response->contributors = array();
			foreach ( $contributors as $i => $contributor ) {
				$username = sanitize_user( preg_replace( '/^.+\/(.+)\/?$/', '\1', $contributor ) );
				$response->contributors[ $username ] = 'https://github.com/' . $contributor;
			}
		}
		// set required version
		if ( isset( $readme_data['requires'] ) || isset( $change_data['requires'] ) ) {
			$response->requires = isset( $change_data['requires'] ) ? $change_data['requires'] : $readme_data['requires'];
		}
		// set tested version
		if ( isset( $readme_data['tested'] ) || isset( $change_data['tested'] ) ) {
			$response->tested = isset( $change_data['tested'] ) ? $change_data['tested'] : $readme_data['tested'];
		}
		// set banner image
		if ( isset( $readme_data['banner'] ) || isset( $change_data['banner'] ) ) {
			$banner = isset( $change_data['banner'] ) ? $change_data['banner'] : $readme_data['banner'];
			$response->banners = array(
				'high' 	=> $banner,
				'low' 	=> $banner
			);
		}

		return $response;
	}

	function parse( $data = null ) {

		if ( empty( $data ) ) {
			return false;
		}

		$data = str_replace( array( '<!--', '-->' ), '', strstr( $data, '<!--' ) );

		$res = [];
		foreach ( explode( "\n", $data ) as $h ) {
			$h = explode( ':', $h, 2 );
			$key = trim( $h[0] );
			$val = trim( $h[1] );

			if ( array_key_exists( $key, $res ) ) {
				$res[ $key ] .= ', ' . $val;
			} else if ( isset( $h[1] ) ) {
				$res[ $key ] = $val;
			}
		}

		return $res;
	}

	/**
	 * Perform check before installation starts.
	 *
	 * @param  boolean $true
	 * @param  array   $args
	 * @return null
	 */
	public function pre_install( $true, $args ) {
		// Check if the plugin was installed before...
		$this->plugin_activated = is_plugin_active( $this->plugin_file );
	}

	/**
	 * Perform additional actions to successfully install our plugin
	 *
	 * @param  boolean $true
	 * @param  string $hook_extra
	 * @param  object $result
	 * @return object
	 */
	public function post_install( $true, $hook_extra, $result ) {
		global $wp_filesystem;

		// Since we are hosted in GitHub, our plugin folder would have a dirname of
		// reponame-tagname change it to our original one:
		$plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->plugin_slug );
		$wp_filesystem->move( $result['destination'], $plugin_folder );
		$result['destination'] = $plugin_folder;

		// Re-activate plugin if needed
		if ( $this->plugin_activated ) {
			$activate = activate_plugin( $this->plugin_file );
		}

		return $result;
	}
}
