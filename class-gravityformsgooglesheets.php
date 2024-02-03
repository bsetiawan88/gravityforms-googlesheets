<?php
/**
 * GravityFormsGooglesheets
 *
 * @package GravityFormsGooglesheets
 */

/**
 * Plugin Name: Gravity Forms Googlesheets Integration
 * Plugin URI: https://github.com/bsetiawan88
 * Description: Gravity Forms Googlesheets Integration
 * Author: Bagus Pribadi Setiawan
 * Author URI: https://github.com/bsetiawan88
 * Version: 1.0.0
 * Copyright: (c) 2021
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 4.4
 * Text Domain: gravityforms-googlesheets
 * Domain Path: language
 */

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

if ( class_exists( 'GFForms' ) ) {

	GFForms::include_addon_framework();

	/**
	 * GravityFormsGooglesheets
	 */
	class GravityFormsGooglesheets extends GFAddOn {
		// @codingStandardsIgnoreStart
		protected $_version                  = '1.0';
		protected $_min_gravityforms_version = '1.7.9999';
		protected $_slug                     = 'googlesheets';
		protected $_path                     = 'class-gravityformsgooglesheets.php';
		protected $_full_path                = __FILE__;
		protected $_url                      = 'https://github.com/bsetiawan88';
		protected $_title                    = 'Gravity Forms Google Sheets Integration';
		protected $_short_title              = 'Google Sheets';
		// @codingStandardsIgnoreEnd

		/**
		 * Contains an instance of this class, if available.
		 *
		 * @var null|GravityFormsGooglesheets $_instance If available, contains an instance of this class.
		 */
		private static $_instance = null;// @codingStandardsIgnoreLine

		/**
		 * Returns the current instance of this class.
		 *
		 * @return null|GravityFormsGooglesheets
		 */
		public static function get_instance() {
			if ( null === self::$_instance ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		/**
		 * Action for plugins_loaded.
		 *
		 * @return void
		 */
		public function init() {
			parent::init();

			require_once __DIR__ . '/vendor/autoload.php';

			add_action( 'gform_after_submission', array( $this, 'after_submission' ), 10, 2 );

			if ( isset( $_GET['page'] ) && isset( $_GET['subview'] ) && 'gf_settings' === $_GET['page'] && 'googlesheets' === $_GET['subview'] && ( isset( $_GET['authorize'] ) || isset( $_GET['code'] ) ) ) {// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// begin Google OAuth2 and save access token.
				$this->get_client();
			};
		}

		/**
		 * Return the plugin's icon for the plugin/form settings menu.
		 *
		 * @return string
		 */
		public function get_menu_icon() {
			return 'gform-icon--entries';
		}

		/**
		 * Plugin Settings page.
		 */
		public function plugin_settings_page() {

			parent::plugin_settings_page();

			$settings = get_option( 'gravityformsaddon_googlesheets_settings' );

			if ( ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) {
				echo sprintf(
					'<br><a href="%s" class="primary button large">%s</a>',
					admin_url( 'admin.php?page=gf_settings&subview=googlesheets&authorize=true' ),// @codingStandardsIgnoreLine
					__( 'Authorize', 'gravityforms-googlesheets' )// @codingStandardsIgnoreLine
				);
			}
		}

		/**
		 * Plugin Settings title.
		 */
		public function plugin_settings_title() {
			return esc_html__( 'Gravity Forms Google Sheets Settings', 'gravityforms-googlesheets' );
		}

		/**
		 * Plugin Settings field.
		 */
		public function plugin_settings_fields() {
			$access_token = get_option( 'gravityformsaddon_googlesheets_access_token' );

			if ( $access_token ) {
				$connected = '<strong>' . __( 'Status: Connected to Google Service', 'gravityforms-googlesheets' ) . '</strong>';
			} else {
				$connected = '<strong>' . __( 'Status: Not connected to Google Service', 'gravityforms-googlesheets' ) . '</strong>';

			}

			$descriptions =
			'<p>' . __( 'Manage integrations with Google Sheets.', 'gravityforms-googlesheets' ) . '</p>' .
			'<ol>' .
			'<li>' . __( 'Create new Google Sheets API service in ', 'gravityforms-googlesheets' ) . '<a href="https://console.developers.google.com">https://console.developers.google.com</a></li>' .
			'<li>' . __( 'Create OAuth credentials', 'gravityforms-googlesheets' ) . '</li>' .
			'<li>' . __( 'Put following URL as <b>Authorized redirect URIs</b>: ', 'gravityforms-googlesheets' ) . '<b style="color:red">' . admin_url( 'admin.php?page=gf_settings&subview=gravityforms-googlesheets' ) . '</b></li>' .
			'<li>' . __( 'Put Client ID and Client Secret into boxes below', 'gravityforms-googlesheets' ) . '</li>' .
			'<li>' . __( 'Click <b>Update Settings</b>', 'gravityforms-googlesheets' ) . '</li>' .
			'<li>' . __( 'Click <b>Authorize</b> button', 'gravityforms-googlesheets' ) . '</li>' .
			'</ol>';

			return array(
				array(
					'description' => $descriptions . $connected,
					'fields'      => array(
						array(
							'type'  => 'text',
							'label' => esc_html__( 'Client ID', 'gravityforms-googlesheets' ),
							'name'  => 'client_id',
						),
						array(
							'type'  => 'text',
							'label' => esc_html__( 'Client Secret', 'gravityforms-googlesheets' ),
							'name'  => 'client_secret',
						),
					),
				),
			);
		}

		/**
		 * Action after form submission.
		 *
		 * @param array $entry Entries of the form.
		 * @param array $form  The form data.
		 */
		public function after_submission( $entry, $form ) {
			try {
				$client = $this->get_client( false );
				if ( $client ) {
					$service = new Google_Service_Sheets( $client );
					$values  = array();

					$spreadsheet_id = get_post_meta( $form['id'], 'gravityforms_googlesheets_spreadsheet_id', true );

					if ( ! $spreadsheet_id ) {
						// create spreadsheet.
						$spreadsheet = new Google_Service_Sheets_Spreadsheet(
							array(
								'properties' => array(
									'title' => $form['title'],
								),
							)
						);

						$spreadsheet = $service->spreadsheets->create(
							$spreadsheet,
							array(
								'fields' => 'spreadsheetId',
							)
						);

						$spreadsheet_id = $spreadsheet->spreadsheetId;// @codingStandardsIgnoreLine
						update_post_meta( $form['id'], 'gravityforms_googlesheets_spreadsheet_id', $spreadsheet_id );

						// insert spreadsheet header.
						$row = array();
						foreach ( $form['fields'] as $field ) {
							$row[] = $field->label;
						}
						$values[] = $row;
					}

					$row = array();
					foreach ( $form['fields'] as $field ) {
						$row[] = $entry[ $field->id ];
					}
					$values[] = $row;

					$body   = new Google_Service_Sheets_ValueRange(
						array(
							'values' => $values,
						)
					);
					$params = array(
						'valueInputOption' => 'RAW',
					);
					$result = $service->spreadsheets_values->append( $spreadsheet_id, 'Sheet1!A1:' . $this->number_to_letter( count( $form['fields'] ) - 1 ) . '1', $body, $params );
				}
			} catch ( Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( print_r( $e->getMessage(), true ) );//@codingStandardsIgnoreLine
				}
			}
		}

		/**
		 * Function to get the Google client class
		 *
		 * @param boolean $redirect Whether to redirect or not.
		 *
		 * @return null|object
		 */
		private function get_client( $redirect = true ) {
			$settings = get_option( 'gravityformsaddon_googlesheets_settings' );

			if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
				return;
			}

			$client = new Google\Client();
			$client->setAuthConfig(
				array(
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
				)
			);
			$client->addScope( Google_Service_Sheets::SPREADSHEETS );
			$client->setAccessType( 'offline' );
			$redirect_url = admin_url( 'admin.php?page=gf_settings&subview=googlesheets' );

			$access_token = get_option( 'gravityformsaddon_googlesheets_access_token' );

			if ( $access_token ) {
				$client->setAccessToken( (array) json_decode( $access_token ) );
			}

			if ( $client->isAccessTokenExpired() ) {
				if ( $client->getRefreshToken() && $redirect ) {
					$client->fetchAccessTokenWithRefreshToken( $client->getRefreshToken() );
				} elseif ( $redirect ) {
					$client->setAccessType( 'offline' );
					$client->setRedirectUri( $redirect_url );
					header( 'Location: ' . filter_var( $client->createAuthUrl(), FILTER_SANITIZE_URL ) );
				} else {
					return false;
				}
			}

			if ( isset( $_GET['code'] ) ) {// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$client->authenticate( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				update_option( 'gravityformsaddon_googlesheets_access_token', wp_json_encode( $client->getAccessToken() ) );
				header( 'Location: ' . filter_var( $redirect_url, FILTER_SANITIZE_URL ) );
			}

			return $client;
		}

		/**
		 * Function to convert number to letter
		 *
		 * @param integer $n The number to covert to letter.
		 * @return string
		 */
		private function number_to_letter( $n ) {
			$r = '';
			for ( $i = 1; $n >= 0 && $i < 10; $i++ ) {
				$r  = chr( 0x41 + ( $n % pow( 26, $i ) / pow( 26, $i - 1 ) ) ) . $r;
				$n -= pow( 26, $i );
			}
			return $r;
		}

	}

	GravityFormsGooglesheets::get_instance();
}
