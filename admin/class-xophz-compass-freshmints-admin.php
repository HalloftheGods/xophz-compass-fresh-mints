<?php

class Xophz_Compass_Freshmints_Admin {

	private $plugin_name;
	private $version;

	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function add_plugin_admin_menu() {
		add_options_page(
			'Fresh Mints Settings',
			'Fresh Mints',
			'manage_options',
			'xophz-compass-freshmints',
			array( $this, 'display_plugin_setup_page' )
		);
	}

	public function register_settings() {
		register_setting( 'xophz_compass_freshmints_options', 'xophz_compass_freshmints_load_mode' );
		register_setting( 'xophz_compass_freshmints_options', 'xophz_compass_freshmints_custom_slug' );
		register_setting( 'xophz_compass_freshmints_options', 'xophz_compass_freshmints_load_page_id' );
		register_setting( 'xophz_compass_freshmints_options', 'xophz_compass_freshmints_default_fetch_qty' );
		register_setting( 'xophz_compass_freshmints_options', 'xophz_compass_freshmints_auto_sync_crm' );
		register_setting( 'xophz_compass_freshmints_options', 'xophz_compass_freshmints_auto_sync_bomb_bag' );
	}

	public function display_plugin_setup_page() {
		$load_mode          = get_option( 'xophz_compass_freshmints_load_mode', 'routes_only' );
		$custom_slug        = get_option( 'xophz_compass_freshmints_custom_slug', 'fresh-mints' );
		$load_page_id       = (int) get_option( 'xophz_compass_freshmints_load_page_id', 0 );
		$fetch_qty          = (int) get_option( 'xophz_compass_freshmints_default_fetch_qty', 25 );
		$auto_sync_crm      = get_option( 'xophz_compass_freshmints_auto_sync_crm', '0' );
		$auto_sync_bomb_bag = get_option( 'xophz_compass_freshmints_auto_sync_bomb_bag', '0' );
		$pages              = get_pages();
		?>
		<div class="wrap">
			<h2>Xophz Compass Fresh Mints Settings</h2>
			<p>Configure public deployment, clean name preview routing, and Questbook CRM / Bomb Bag email marketing integration.</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'xophz_compass_freshmints_options' );
				do_settings_sections( 'xophz_compass_freshmints_options' );
				?>
				<table class="form-table">
					<tr valign="top">
						<th scope="row">Deployment & Routing Mode</th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="xophz_compass_freshmints_load_mode" value="routes_only" <?php checked( $load_mode, 'routes_only' ); ?> />
									<strong>Default Route:</strong> Load at <code>/fresh-mints</code> (with clean previews at <code>/fresh-mints/#/preview/name-slug</code> and <code>/preview/name-slug</code>)
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_freshmints_load_mode" value="custom_slug" <?php checked( $load_mode, 'custom_slug' ); ?> />
									<strong>Custom Slug:</strong> Load at a custom URL slug
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_freshmints_load_mode" value="homepage" <?php checked( $load_mode, 'homepage' ); ?> />
									<strong>Site Homepage:</strong> Override the front page with Fresh Mints
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_freshmints_load_mode" value="specific_page" <?php checked( $load_mode, 'specific_page' ); ?> />
									<strong>Specific Page:</strong> Target an existing WordPress page
								</label><br />
								<label>
									<input type="radio" name="xophz_compass_freshmints_load_mode" value="subdomain" <?php checked( $load_mode, 'subdomain' ); ?> />
									<strong>Subdomain Routing:</strong> Route dynamic practitioner and tenant subdomains (e.g. <code>*.mycompass</code> or <code>*.worldwidewebwork.com</code>)
								</label>
							</fieldset>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Custom Deployment Slug</th>
						<td>
							<input type="text" name="xophz_compass_freshmints_custom_slug" value="<?php echo esc_attr( $custom_slug ); ?>" class="regular-text" />
							<p class="description">URL path where Fresh Mints is accessible (e.g. <code>fresh-mints</code> for <code>/fresh-mints</code> or <code>leads</code> for <code>/leads</code>).</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Target WordPress Page</th>
						<td>
							<select name="xophz_compass_freshmints_load_page_id">
								<option value="0">None Selected</option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo $page->ID; ?>" <?php selected( $load_page_id, $page->ID ); ?>>
										<?php echo esc_html( $page->post_title ); ?> (ID: <?php echo $page->ID; ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Active when Deployment Mode is set to "Specific Page".</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Default Lead Batch Size</th>
						<td>
							<input type="number" name="xophz_compass_freshmints_default_fetch_qty" value="<?php echo esc_attr( $fetch_qty ); ?>" min="5" max="100" class="small-text" />
							<p class="description">Default number of live newly licensed practitioner records to retrieve per search query.</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Questbook CRM Auto-Sync</th>
						<td>
							<label>
								<input type="checkbox" name="xophz_compass_freshmints_auto_sync_crm" value="1" <?php checked( '1', $auto_sync_crm ); ?> />
								Automatically create a Questbook CRM Contact when a lead is skip-traced or qualified.
							</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">Bomb Bag Marketing Auto-Sync</th>
						<td>
							<label>
								<input type="checkbox" name="xophz_compass_freshmints_auto_sync_bomb_bag" value="1" <?php checked( '1', $auto_sync_bomb_bag ); ?> />
								Automatically create a Bomb Bag Subscriber and enroll in Fresh Mints marketing journeys.
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function flush_rewrites_on_save( $old_value, $new_value ) {
		if ( $old_value !== $new_value ) {
			$public = new Xophz_Compass_Freshmints_Public( $this->plugin_name, $this->version );
			$public->register_endpoints();
			flush_rewrite_rules();
		}
	}
}
