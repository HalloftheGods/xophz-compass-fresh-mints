<?php
/**
 * Fresh Mints core REST routes (namespace xophz-freshmints/v1).
 *
 * Each route group is its own controller under includes/api/ (#6033). This class keeps the name the
 * plugin bootstrap and the registry producers use: it registers every controller, and it inherits
 * generate_preview_slug() and get_estimated_deal_value() from Freshmints_Api_Controller.
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once __DIR__ . '/api/class-freshmints-api-controller.php';
require_once __DIR__ . '/api/class-freshmints-api-auth.php';
require_once __DIR__ . '/api/class-freshmints-api-gemini.php';
require_once __DIR__ . '/api/class-freshmints-api-leads.php';
require_once __DIR__ . '/api/class-freshmints-api-places.php';
require_once __DIR__ . '/api/class-freshmints-api-registry.php';
require_once __DIR__ . '/api/class-freshmints-api-crm.php';
require_once __DIR__ . '/api/class-freshmints-api-bomb-bag.php';
require_once __DIR__ . '/api/class-freshmints-api-stats.php';

class Freshmints_API extends Freshmints_Api_Controller {

	/**
	 * Route controllers, in the order their routes were registered before the split (#6033).
	 */
	const CONTROLLERS = array(
		'Freshmints_Api_Auth',
		'Freshmints_Api_Gemini',
		'Freshmints_Api_Leads',
		'Freshmints_Api_Places',
		'Freshmints_Api_Registry',
		'Freshmints_Api_Crm',
		'Freshmints_Api_Bomb_Bag',
		'Freshmints_Api_Stats',
	);

	public function register_routes() {
		foreach ( self::CONTROLLERS as $controller ) {
			( new $controller() )->register_routes();
		}
	}
}
