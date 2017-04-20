<?php

/**
 * Testing CRUD on Options
 */
class WP_Test_Jetpack_Sync_Themes extends WP_Test_Jetpack_Sync_Base {
	protected $theme;

	public function setUp() {
		parent::setUp();
		$themes      = array( 'twentyten', 'twentyeleven', 'twentytwelve', 'twentythirteen', 'twentyfourteen' );
		$this->theme = $themes[ rand( 0, 4 ) ];

		switch_theme( $this->theme );

		$this->sender->do_sync();
	}

	public function test_changed_theme_is_synced() {
		$theme_features = array(
			'post-thumbnails',
			'post-formats',
			'custom-header',
			'custom-background',
			'custom-logo',
			'menus',
			'automatic-feed-links',
			'editor-style',
			'widgets',
			'html5',
			'title-tag',
			'jetpack-social-menu',
			'jetpack-responsive-videos',
			'infinite-scroll',
			'site-logo'
		);

		// this forces theme mods to be saved as an option so that this test is valid
		set_theme_mod( 'foo', 'bar' );
		$this->sender->do_sync();

		$current_theme = wp_get_theme();
		$switch_data = $this->server_event_storage->get_most_recent_event( 'jetpack_sync_current_theme_support' );
		$this->assertEquals( $current_theme->name, $switch_data->args[0]['name']);
		$this->assertEquals( $current_theme->version, $switch_data->args[0]['version']);

		foreach ( $theme_features as $theme_feature ) {
			$synced_theme_support_value = $this->server_replica_storage->current_theme_supports( $theme_feature );
			$this->assertEquals( current_theme_supports( $theme_feature ), $synced_theme_support_value, 'Feature(s) not synced' . $theme_feature );
		}

		// TODO: content_width - this has traditionally been synced as if it was a theme-specific
		// value, but in fact it's a per-page/post value defined via Jetpack's Custom CSS module

		// LEFT OUT: featured_images_enabled - a quick look inside Jetpack shows that this is equivalent
		// to 'post-thumbnails', so not worth syncing

		// theme name and options should be whitelisted as a synced option
		$this->assertEquals( $this->theme, $this->server_replica_storage->get_option( 'stylesheet' ) );

		$local_value = get_option( 'theme_mods_' . $this->theme );
		$remote_value = $this->server_replica_storage->get_option( 'theme_mods_' . $this->theme );
		
		if ( isset( $local_value[0] ) ) {
			// this is a spurious value that sometimes gets set during tests, and is
			// actively removed before sending to WPCOM
			// it appears to be due to a bug which sets array( false ) as the default value for theme_mods
			unset( $local_value[0] );
		}

		$this->assertEquals( $local_value, $this->server_replica_storage->get_option( 'theme_mods_' . $this->theme ) );
	}

	public function test_widgets_changes_get_synced() {

		$sidebar_widgets = array(
			'wp_inactive_widgets' => array( 'author-1' ),
			'sidebar-1' => array( 'recent-posts-2' ),
			'array_version' => 3
		);
		wp_set_sidebars_widgets( $sidebar_widgets );

		$this->sender->do_sync();

		$local_value = get_option( 'sidebars_widgets' );
		$remote_value = $this->server_replica_storage->get_option( 'sidebars_widgets' );
		$this->assertEquals( $local_value, $remote_value, 'We are not syncing sidebar_widgets' );

		// Add widget
		$sidebar_widgets = array(
			'wp_inactive_widgets' => array( 'author-1' ),
			'sidebar-1' => array( 'recent-posts-2', 'calendar-2'),
			'array_version' => 3
		);
		wp_set_sidebars_widgets( $sidebar_widgets );
		$this->sender->do_sync();


		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_widget_added' );
		$this->assertEquals( $event->args[0], 'sidebar-1', 'Added to sidebar not found' );
		$this->assertEquals( $event->args[1], 'calendar-2', 'Added widget not found' );

		// Reorder widget
		$sidebar_widgets  = array(
			'wp_inactive_widgets' => array( 'author-1' ),
			'sidebar-1' => array( 'calendar-2', 'recent-posts-2' ),
			'array_version' => 3
		);
		wp_set_sidebars_widgets( $sidebar_widgets );
		$this->sender->do_sync();

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_widget_reordered' );
		$this->assertEquals( $event->args[0], 'sidebar-1', 'Reordered sidebar not found' );

		// Deleted widget
		$sidebar_widgets  = array(
			'wp_inactive_widgets' => array( 'author-1' ),
			'sidebar-1' => array( 'calendar-2' ),
			'array_version' => 3
		);
		wp_set_sidebars_widgets( $sidebar_widgets );
		$this->sender->do_sync();

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_widget_removed' );
		$this->assertEquals( $event->args[0], 'sidebar-1', 'Sidebar not found' );
		$this->assertEquals( $event->args[1], 'recent-posts-2', 'Recent removed widget not found' );

		// Moved to inactive
		$sidebar_widgets  = array(
			'wp_inactive_widgets' => array( 'author-1', 'calendar-2' ),
			'sidebar-1' => array(),
			'array_version' => 3
		);
		wp_set_sidebars_widgets( $sidebar_widgets );
		$this->sender->do_sync();

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_widget_moved_to_inactive' );
		$this->assertEquals( $event->args[0], array( 'calendar-2' ), 'Moved to inactive not present' );

		// Cleared inavite
		$sidebar_widgets  = array(
			'wp_inactive_widgets' => array(),
			'sidebar-1' => array(),
			'array_version' => 3
		);
		wp_set_sidebars_widgets( $sidebar_widgets );
		$this->sender->do_sync();

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_cleared_inactive_widgets' );
		$this->assertTrue( (bool) $event, 'Not fired cleared inacative widgets' );
	}

	function test_sync_creating_a_menu() {
		$menu_id = wp_create_nav_menu( 'FUN' );
		$this->sender->do_sync();

		$event = $this->server_event_storage->get_most_recent_event( 'wp_create_nav_menu' );
		$this->assertTrue( (bool) $event );
		$this->assertEquals( $event->args[0], $menu_id );
		$this->assertEquals( $event->args[1]['menu-name'], 'FUN' );

	}

	function test_sync_updating_a_menu() {
		$menu_id = wp_create_nav_menu( 'FUN' );
		wp_update_nav_menu_object( $menu_id, array( 'menu-name' => 'UPDATE' ) );
		$this->sender->do_sync();

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_sync_updated_nav_menu' );

		$this->assertEquals( $event->args[0], $menu_id );
		$this->assertEquals( $event->args[1]['menu-name'], 'UPDATE' );
	}

	function test_sync_updating_a_menu_add_an_item() {
		$menu_id = wp_create_nav_menu( 'FUN' );

		// Add item to the menu
		$link_item = wp_update_nav_menu_item( $menu_id, null, array(
			'menu-item-title' => 'LINK TO LINKS',
			'menu-item-url' => 'http://example.com',
		) );

		$this->server_event_storage->reset();
		$this->sender->do_sync();

		// Update item in the menu
		$link_item = wp_update_nav_menu_item( $menu_id, $link_item, array(
			'menu-item-title' => 'make it https MORE LINKS',
			'menu-item-url' => 'https://example.com',
		) );

		// Make sure that this event is not there...
		$update_event = $this->server_event_storage->get_most_recent_event( 'jetpack_sync_updated_nav_menu_update_item' );
		$this->assertFalse( $update_event );

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_sync_updated_nav_menu_add_item' );

		$this->assertEquals( $event->args[0], $menu_id );
		$this->assertEquals( $event->args[1]->name, 'FUN' );
		$this->assertEquals( $event->args[2], $link_item );
		$this->assertEquals( $event->args[3]['menu-item-title'], 'LINK TO LINKS' );
		$this->assertEquals( $event->args[3]['menu-item-url'], 'http://example.com' );

	}

	function test_sync_updating_a_menu_update_an_item() {
		$menu_id = wp_create_nav_menu( 'FUN' );

		// Add item to the menu
		$link_item = wp_update_nav_menu_item( $menu_id, null, array(
			'menu-item-title' => 'LINK TO LINKS',
			'menu-item-url' => 'http://example.com',
		) );

		$this->server_event_storage->reset();
		$this->sender->do_sync();

		// Update item in the menu
		$link_item = wp_update_nav_menu_item( $menu_id, $link_item, array(
			'menu-item-title' => 'make it https MORE LINKS',
			'menu-item-url' => 'https://example.com',
		) );

		$this->server_event_storage->reset();
		$this->sender->do_sync();

		$add_event = $this->server_event_storage->get_most_recent_event( 'jetpack_sync_updated_nav_menu_add_item' );
		$this->assertFalse( $add_event );

		$event = $this->server_event_storage->get_most_recent_event( 'jetpack_sync_updated_nav_menu_update_item' );
		$this->assertEquals( $event->args[0], $menu_id );
		$this->assertEquals( $event->args[1]->name, 'FUN' );
		$this->assertEquals( $event->args[2], $link_item );
		$this->assertEquals( $event->args[3]['menu-item-title'], 'make it https MORE LINKS' );
		$this->assertEquals( $event->args[3]['menu-item-url'], 'https://example.com' );

	}

	function test_sync_deleteing_a_menu() {
		$menu_id = wp_create_nav_menu( 'DELETEME' );
		wp_delete_nav_menu( $menu_id );
		$this->sender->do_sync();
		$event = $this->server_event_storage->get_most_recent_event( 'delete_nav_menu' );

		$this->assertEquals( $event->args[0], $menu_id );
		$this->assertEquals( $event->args[2]->name, 'DELETEME' );
	}

}
