<?php
/**
 * ServiceWorker Registrar
 *
 * This class registers the service worker script and handles its registration in the browser.
 *
 * @package ServiceWorker
 * @since 0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class JTZL_SW_Registrar
 *
 * Registers the service worker script and handles its registration in the browser.
 *
 * @since 0.1.0
 */
class JTZL_SW_Registrar {

	/**
	 * Constructor.
	 *
	 * Initializes the class by adding the necessary action to register the service worker script.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_sw' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_sw' ) );
	}

	/**
	 * Registers the service worker script in the browser.
	 *
	 * This method checks if the service worker is enabled in the plugin settings and registers
	 * the service worker script with the browser if it is. It also localizes dynamic WordPress
	 * URLs (AJAX and REST API endpoints) for use in the service worker.
	 *
	 * @since 0.1.0
	 * @since 1.0.0 Added dynamic AJAX URL and WP JSON URL localization.
	 */
	public function register_sw() {
		$options = get_option( 'jtzl_sw_options' );

		if ( ! isset( $options['enabled'] ) || 1 !== (int) $options['enabled'] ) {
			return;
		}

		// Check if we should disable for logged-in users.
		$disable_for_logged_users = isset( $options['disable_for_logged_users'] ) ? (bool) $options['disable_for_logged_users'] : true;
		$is_user_logged_in        = is_user_logged_in();

		// Always enqueue the script to handle unregistration for logged-in users.
		wp_register_script( 'jtzl-service-worker-registrar', '', array(), JTZL_SW_VERSION, true );
		wp_enqueue_script( 'jtzl-service-worker-registrar' );

		// Pass login status to JavaScript.
		wp_localize_script(
			'jtzl-service-worker-registrar',
			'jtzlSwData',
			array(
				'isUserLoggedIn'        => $is_user_logged_in,
				'disableForLoggedUsers' => $disable_for_logged_users,
				'swUrl'                 => esc_url( add_query_arg( 'ver', JTZL_SW_VERSION, '/service-worker.js' ) ),
				'ajaxUrl'               => esc_url( admin_url( 'admin-ajax.php' ) ),
				'wpJsonUrl'             => esc_url( rest_url( 'wp/v2/' ) ),
			)
		);

		// If user is logged in and the option is enabled, add unregistration script instead of registration.
		if ( $disable_for_logged_users && $is_user_logged_in ) {
			$this->add_unregistration_script();
			return;
		}

		// The URL is now clean, as the File_Handler will serve the dynamic content.
		$sw_url = esc_url( add_query_arg( 'ver', JTZL_SW_VERSION, '/service-worker.js' ) );

		$script = sprintf(
			"
		if ('serviceWorker' in navigator && !window.jtzlSwDisabled) {
			window.addEventListener('load', function() {
				// Double-check user is not logged in (in case of race conditions)
				if (jtzlSwData && jtzlSwData.disableForLoggedUsers && jtzlSwData.isUserLoggedIn) {
					return;
				}
				
				navigator.serviceWorker.register('%s', { scope: '/' }).then(function(registration) {
					// Add global helper function to check cached files (verbose by user intent only)
					window.jtzlSwInspect = async function() {
						console.log('=== IN-BROWSER CACHE DEBUG INFO ===');
						console.log('Controller:', navigator.serviceWorker.controller ? 'present' : 'none');
						console.log('Registration state:', registration.active ? registration.active.state : 'no active worker');
						
						if (!navigator.serviceWorker.controller) {
							console.log('No controlling service worker found. Try refreshing the page.');
							console.log('You can also check Application > Service Workers in DevTools');
							return;
						}
						
						const messageChannel = new MessageChannel();
						return new Promise((resolve) => {
							messageChannel.port1.onmessage = function(event) {
								const cachedFiles = event.data;
								console.log('=== CACHED FILES REPORT ===');
								Object.keys(cachedFiles).forEach(cacheName => {
									console.log('Cache: ' + cacheName);
									cachedFiles[cacheName].forEach(file => {
										console.log('  - ' + file.url);
									});
									console.log('  Total files: ' + cachedFiles[cacheName].length);
									console.log('');
								});
								resolve(cachedFiles);
							};
							
							navigator.serviceWorker.controller.postMessage(
								{ action: 'LIST_CACHE' },
								[messageChannel.port2]
							);
						});
					};
					
					// If there's a waiting service worker, skip waiting to activate immediately (no log)
					if (registration.waiting) {
						registration.waiting.postMessage({ action: 'SKIP_WAITING' });
					}
					
					// Listen for service worker messages
					navigator.serviceWorker.addEventListener('message', function(event) {
						if (event.data && event.data.action === 'SW_ACTIVATED') {
							setTimeout(function() {
								if (!navigator.serviceWorker.controller) {
									window.location.reload();
								}
							}, 100);
						} else if (event.data && event.data.action === 'SW_AUTO_DEREGISTER') {
							// Minimal high-signal notification
							try {
								var reason = event.data && event.data.reason ? event.data.reason : 'unknown';
								console.warn('[In-Browser Cache] Auto-deregistered (' + reason + ')');
							} catch (e) {}
							
							// Prevent further SW operations and ensure clean state
							window.jtzlSwDisabled = true;
							setTimeout(function() {
								window.location.reload();
							}, 2000);
						}
					});
					
					// Metrics sync triggers (no console noise)
					document.addEventListener('visibilitychange', function() {
						if (!document.hidden && navigator.serviceWorker.controller) {
							navigator.serviceWorker.controller.postMessage({ action: 'PAGE_VISIBLE' });
						}
					});
					
					window.addEventListener('beforeunload', function() {
						if (navigator.serviceWorker.controller) {
							navigator.serviceWorker.controller.postMessage({ action: 'SYNC_METRICS' });
						}
					});
					
					setInterval(function() {
						if (!document.hidden && navigator.serviceWorker.controller) {
							navigator.serviceWorker.controller.postMessage({ action: 'SYNC_METRICS' });
						}
					}, 2 * 60 * 1000);
					
				}, function(err) {
					console.error('Service Worker registration failed:', err);
				});
			});
		}
		",
			$sw_url
		);

		wp_add_inline_script( 'jtzl-service-worker-registrar', $script );
	}

	/**
	 * Adds JavaScript to unregister the service worker for logged-in users.
	 *
	 * This method ensures that the service worker is unregistered and all caches
	 * are cleared when a user is logged in, for GDPR compliance.
	 *
	 * @since 0.2.1
	 */
	private function add_unregistration_script() {
		$script = "
		if ('serviceWorker' in navigator) {
			window.addEventListener('load', function() {
				// Get all service worker registrations
				navigator.serviceWorker.getRegistrations().then(function(registrations) {
					if (registrations.length === 0) {
						return;
					}
					
					const unregisterPromises = [];
					
					registrations.forEach(function(registration) {
						unregisterPromises.push(registration.unregister());
					});
					
					// Wait for all unregistrations to complete
					Promise.all(unregisterPromises).then(function() {
						// Clear all caches
						if ('caches' in window) {
							caches.keys().then(function(cacheNames) {
								const deletePromises = cacheNames.map(function(cacheName) {
									return caches.delete(cacheName);
								});
								
								return Promise.all(deletePromises);
							}).then(function() {
							// Add a flag to prevent re-registration attempts
							window.jtzlSwDisabled = true;
						}).catch(function(error) {
							console.error('[In-Browser Cache] Error clearing caches:', error);
						});
					}
					}).catch(function(error) {
						console.error('[In-Browser Cache] Error during unregistration:', error);
					});
				}).catch(function(error) {
					console.error('[In-Browser Cache] Error getting registrations:', error);
				});
			});
		}
		";

		wp_add_inline_script( 'jtzl-service-worker-registrar', $script );
	}
}
