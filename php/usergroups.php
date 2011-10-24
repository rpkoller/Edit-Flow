<?php
/**
 * class EF_Usergroups
 * 
 * @todo all of them PHPdocs
 * @todo Resolve whether the notifications component of this class should be moved to "subscriptions"
 * @todo Decide whether it's functional to store user_ids in the term description array
 * - Argument against: it's going to be expensive to look up usergroups for a user
 *
 */

if ( !class_exists( 'EF_Usergroups' ) ) {

class EF_Usergroups {
	
	var $module;
	
	/**
	 * Keys for storing data
	 * - taxonomy_key - used for custom taxonomy
	 * - term_prefix - Used for custom taxonomy terms
	 */
	const taxonomy_key = 'ef_usergroup';
	const term_prefix = 'ef-usergroup-';

	/**
	 * Register the module with Edit Flow but don't do anything else
	 *
	 * @since 0.7
	 */
	function __construct( ) {
		global $edit_flow;
		
		// Register the Usergroup module with Edit Flow
		$args = array(
			'title' => __( 'Usergroups', 'edit-flow' ),
			'short_description' => __( 'Usergroups allow you to manage the people in your workflow. tk', 'edit-flow' ),
			'extended_description' => __( 'This is a longer description that shows up on some views. We might want to include a link to documentation. tk', 'edit-flow' ),
			'img_url' => false,
			'slug' => 'usergroups',
			'default_options' => array(
				'enabled' => 'on',
				'post_types' => array(
					'post' => 'on',
					'page' => 'off',
				),
			),
			'messages' => array(
				'usergroup-added' => __( "Usergroup created. Feel free to add users to the usergroup.", 'edit-flow' ),
				'usergroup-updated' => __( "Usergroup updated.", 'edit-flow' ),
				'usergroup-missing' => __( "Usergroup doesn't exist.", 'edit-flow' ),
				'usergroup-deleted' => __( "Usergroup deleted.", 'edit-flow' ),				
			),
			'configure_page_cb' => 'print_configure_view',
			'configure_link_text' => __( 'Manage Usergroups', 'edit-flow' ),
			'autoload' => false,
		);
		$this->module = $edit_flow->register_module( 'usergroups', $args );
		
	}
	
	/**
	 * Module startup
	 */
	
	/**
	 * Initialize the rest of the stuff in the class if the module is active
	 *
	 * @since 0.7
	 */	
	function init() {
		
		// Register the objects where we'll be storing data and relationships
		$this->register_usergroup_objects();
		
		// Register our settings
		add_action( 'admin_init', array( &$this, 'register_settings' ) );		
		
		// Handle any adding, editing or saving
		add_action( 'admin_init', array( &$this, 'handle_add_usergroup' ) );
		add_action( 'admin_init', array( &$this, 'handle_edit_usergroup' ) );
		add_action( 'admin_init', array( &$this, 'handle_delete_usergroup' ) );
		add_action( 'wp_ajax_inline_save_usergroup', array( &$this, 'handle_ajax_inline_save_usergroup' ) );
		
		// Set up metabox and related actions
		add_action( 'admin_init', array( &$this, 'add_post_meta_box' ) );
	
		// Usergroups can be managed from the User profile view
		add_action( 'show_user_profile', array( &$this, 'user_profile_page' ) );
		add_action( 'edit_user_profile', array( &$this, 'user_profile_page' ) );
		add_action( 'user_profile_update_errors', array( &$this, 'user_profile_update' ), 10, 3 );

		// Javascript and CSS if we need it
		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_admin_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_admin_styles' ) );	
		
	}
	
	/**
	 * Individual Usergroups are stored using a custom taxonomy
	 * Posts are associated with usergroups based on taxonomy relationship
	 * User associations are stored serialized in the term's description field
	 *
	 * @since 0.7
	 *
	 * @uses register_taxonomy()
	 */
	function register_usergroup_objects() {
		global $edit_flow;
		
		// Load the currently supported post types so we only register against those
		$supported_post_types = $edit_flow->helpers->get_post_types_for_module( $this->module );
		
		// Use a taxonomy to manage relationships between posts and usergroups
		$args = array(
			'public' => false,
		);
		register_taxonomy( self::taxonomy_key, $supported_post_types, $args );
	}
	
	/**
	 * Enqueue necessary admin scripts
	 *
	 * @since 0.7
	 *
	 * @uses wp_enqueue_script()
	 */
	function enqueue_admin_scripts() {
		global $edit_flow;
		
		if ( $edit_flow->helpers->is_whitelisted_functional_view() || $edit_flow->helpers->is_whitelisted_settings_view( $this->module->name ) ) {
			wp_enqueue_script( 'jquery-listfilterizer' );
			wp_enqueue_script( 'jquery-quicksearch' );
			wp_enqueue_script( 'edit-flow-usergroups-js', EDIT_FLOW_URL . 'js/usergroups.js', array( 'jquery', 'jquery-listfilterizer', 'jquery-quicksearch' ), EDIT_FLOW_VERSION, true );
		}
			
		if ( $edit_flow->helpers->is_whitelisted_settings_view( $this->module->name ) )	
			wp_enqueue_script( 'edit-flow-usergroups-configure-js', EDIT_FLOW_URL . 'js/usergroups_configure.js', array( 'jquery' ), EDIT_FLOW_VERSION, true );
	}
	
	/**
	 * Enqueue necessary admin styles, but only on the proper pages
	 *
	 * @since 0.7
	 *
	 * @uses wp_enqueue_style()	
	 */
	function enqueue_admin_styles() {
		global $edit_flow;
		
		if ( $edit_flow->helpers->is_whitelisted_functional_view() || $edit_flow->helpers->is_whitelisted_settings_view() ) {
			wp_enqueue_style( 'jquery-listfilterizer' );
			wp_enqueue_style( 'edit-flow-usergroups-css', EDIT_FLOW_URL . 'css/usergroups.css', false, EDIT_FLOW_VERSION );
		}
	}
	
	/**
	 * Module ???
	 */
	
	/**
	 * Add the usergroup subscriptions meta box to relevant post types
	 * @todo Resolve whether this should live in a "subscriptions" class
	 */
	function add_post_meta_box() {
		global $edit_flow;
		
		$post_subscriptions_cap = apply_filters( 'ef_edit_post_subscriptions_cap', 'edit_post_subscriptions' );
		if ( !current_user_can( $post_subscriptions_cap ) ) 
			return;		
		
		$usergroup_post_types = $edit_flow->helpers->get_post_types_for_module( $edit_flow->custom_status->module );
		foreach ( $usergroup_post_types as $post_type ) {
			add_meta_box( 'edit-flow-usergroup-subscriptions', __( 'Usergroup Subscriptions', 'edit-flow'), array( &$this, 'usergroups_meta_box'), $post_type, 'advanced', 'high');
		}
	}
	
	/**
	 * Outputs box used to subscribe users and usergroups to Posts
	 *
	 * @todo Resolve whether this should live in a "subscriptions" class
	 * @todo add_cap to set subscribers for posts; default to Admin and editors
	 * @todo Remove dependency on post being saved already
	 */	
	function usergroups_meta_box() {
		global $post, $post_ID, $edit_flow;

		// Only show on posts that have been saved
		if( in_array( $post->post_status, array( 'new', 'auto-draft' ) ) ) {
			?>
			<p><?php _e( 'Subscribers can be added to a post after the post has been saved for the first time.', 'edit-flow' ); ?></p>
			<?php
			return;
		}
		
		// @todo
		//$followers = ef_get_following_users( $post->ID, 'id' );

		// @todo
		//$following_usergroups = ef_get_following_usergroups($post->ID, 'slugs');
		
		$user_form_args = array();
		
		$usergroups_form_args = array();
		?>
		<div id="ef-post_following_box">
			<a name="subscriptions"></a>

			<p><?php _e( 'Select the users and usergroups that should receive notifications when the status of this post is updated or when an editorial comment is added.', 'edit-flow' ); ?></p>
			<div id="ef-post_following_users_box">
				<h4><?php _e( 'Users', 'edit-flow' ); ?></h4>
				<?php $this->users_select_form( array() ); ?>
			</div>
			
			<div id="ef-post_following_usergroups_box">
				<h4><?php _e('User Groups', 'edit-flow') ?></h4>
				<?php $this->usergroups_select_form( array() ); ?>
			</div>
			<div class="clear"></div>
			<input type="hidden" name="ef-save_followers" value="1" /> <?php // Extra protection against autosaves ?>
		</div>
		
		<script>
			jQuery(document).ready(function(){
				jQuery('#ef-post_following_box ul').listFilterizer();
			});
		</script>
		
		<?php
	}
	
	/**
	 * Handles a POST request to add a new Usergroup. Redirects to edit view after
	 * for admin to add users to usergroup
	 * Hooked into 'admin_init' and kicks out right away if no action	
	 *
	 * @since 0.7
	 */
	function handle_add_usergroup() {

		if ( !isset( $_POST['submit'], $_POST['form-action'], $_GET['page'] ) 
			|| $_GET['page'] != $this->module->settings_slug || $_POST['form-action'] != 'add-usergroup' )
				return;
				
		if ( !wp_verify_nonce( $_POST['_wpnonce'], 'add-usergroup' ) )
			wp_die( $this->module->messages['nonce-failed'] );
			
		if ( !current_user_can( 'manage_options' ) )
			wp_die( $this->module->messages['invalid-permissions'] );			
		
		// Sanitize all of the user-entered values
		$name = strip_tags( trim( $_POST['name'] ) );
		$description = strip_tags( trim( $_POST['description'] ) );
		
		$_REQUEST['form-errors'] = array();
		
		/**
		 * Form validation for adding new Usergroup
		 *
		 * Details
		 * - 'name' is a required field, but can't match an existing name or slug. Needs to be 40 characters or less
		 * - "description" can accept a limited amount of HTML, and is optional
		 */
		// Field is required
		if ( empty( $name ) )
			$_REQUEST['form-errors']['name'] = __( 'Please enter a name for the usergroup.', 'edit-flow' );
		// Check to ensure a term with the same name doesn't exist
		if ( $this->get_usergroup_by( 'name', $name ) )
			$_REQUEST['form-errors']['name'] = __( 'Name already in use. Please choose another.', 'edit-flow' );
		// Check to ensure a term with the same slug doesn't exist
		if ( $this->get_usergroup_by( 'slug', sanitize_title( $name ) ) )
			$_REQUEST['form-errors']['name'] = __( 'Name conflicts with slug for another term. Please choose again.', 'edit-flow' );
		if ( strlen( $name ) > 40 )
			$_REQUEST['form-errors']['name'] = __( 'Usergroup name cannot exceed 40 characters. Please try a shorter name.', 'edit-flow' );			
		// Kick out if there are any errors
		if ( count( $_REQUEST['form-errors'] ) ) {
			$_REQUEST['error'] = 'form-error';
			return;
		}

		// Try to add the Usergroup
		$args = array(
			'name' => $name,
			'description' => $description,
		);
		$usergroup = $this->add_usergroup( $args );
		if ( is_wp_error( $usergroup ) )
			wp_die( __( 'Error adding usergroup.', 'edit-flow' ) );

		$args = array(
			'action' => 'edit-usergroup',
			'usergroup-id' => $usergroup->term_id,
			'message' => 'usergroup-added'
		);
		$redirect_url = $this->get_link( $args );
		wp_redirect( $redirect_url );
		exit;
	}
	
	/**
	 * Handles a POST request to edit a Usergroup
	 * Hooked into 'admin_init' and kicks out right away if no action
	 *
	 * @since 0.7	
	 */ 
	function handle_edit_usergroup() {
		if ( !isset( $_POST['submit'], $_POST['form-action'], $_GET['page'] ) 
			|| $_GET['page'] != $this->module->settings_slug || $_POST['form-action'] != 'edit-usergroup' )
				return;
				
		if ( !wp_verify_nonce( $_POST['_wpnonce'], 'edit-usergroup' ) )
			wp_die( $this->module->messages['nonce-failed'] );
			
		if ( !current_user_can( 'manage_options' ) )
			wp_die( $this->module->messages['invalid-permissions'] );
			
		if ( !$existing_usergroup = $this->get_usergroup_by( 'id', (int)$_POST['usergroup_id'] ) )
			wp_die( $this->module->messsage['usergroup-error'] );			
		
		// Sanitize all of the user-entered values
		$name = strip_tags( trim( $_POST['name'] ) );
		$description = strip_tags( trim( $_POST['description'] ) );
		
		$_REQUEST['form-errors'] = array();
		
		/**
		 * Form validation for adding new Usergroup
		 *
		 * Details
		 * - 'name' is a required field, but can't match an existing name or slug. Needs to be 40 characters or less
		 * - "description" can accept a limited amount of HTML, and is optional
		 */
		// Field is required
		if ( empty( $name ) )
			$_REQUEST['form-errors']['name'] = __( 'Please enter a name for the usergroup.', 'edit-flow' );
		// Check to ensure a term with the same name doesn't exist
		$search_term = $this->get_usergroup_by( 'name', $name );
		if ( is_object( $search_term ) && $search_term->term_id != $existing_usergroup->term_id )
			$_REQUEST['form-errors']['name'] = __( 'Name already in use. Please choose another.', 'edit-flow' );
		// Check to ensure a term with the same slug doesn't exist
		$search_term = $this->get_usergroup_by( 'slug', sanitize_title( $name ) );
		if ( is_object( $search_term ) && $search_term->term_id != $existing_usergroup->term_id )
			$_REQUEST['form-errors']['name'] = __( 'Name conflicts with slug for another term. Please choose again.', 'edit-flow' );
		if ( strlen( $name ) > 40 )
			$_REQUEST['form-errors']['name'] = __( 'Usergroup name cannot exceed 40 characters. Please try a shorter name.', 'edit-flow' );			
		// Kick out if there are any errors
		if ( count( $_REQUEST['form-errors'] ) ) {
			$_REQUEST['error'] = 'form-error';
			return;
		}

		// Try to add the Usergroup
		$args = array(
			'name' => $name,
			'description' => $description,
		);
		$users = (array)$_POST['usergroup_users'];
		array_map( 'intval', $users );
		$usergroup = $this->update_usergroup( $existing_usergroup->term_id, $args, $users );
		if ( is_wp_error( $usergroup ) )
			wp_die( __( 'Error updating usergroup.', 'edit-flow' ) );

		$args = array(
			'message' => 'usergroup-updated',
		);
		$redirect_url = $this->get_link( $args );
		wp_redirect( $redirect_url );
		exit;
	}
	
	/**
	 * Handles a request to delete a Usergroup.
	 * Hooked into 'admin_init' and kicks out right away if no action
	 *
	 * @since 0.7
	 */
	function handle_delete_usergroup() {
		if ( !isset( $_GET['page'], $_GET['action'], $_GET['usergroup-id'] ) 
			|| $_GET['page'] != $this->module->settings_slug || $_GET['action'] != 'delete-usergroup' )
				return;
				
		if ( !wp_verify_nonce( $_GET['nonce'], 'delete-usergroup' ) )
			wp_die( $this->module->messages['nonce-failed'] );
			
		if ( !current_user_can( 'manage_options' ) )
			wp_die( $this->module->messages['invalid-permissions'] );			
			
		$result = $this->delete_usergroup( (int)$_GET['usergroup-id'] );
		if ( !$result || is_wp_error( $result ) )
			wp_die( __( 'Error deleting usergroup.', 'edit-flow' ) );
			
		$redirect_url = $this->get_link( array( 'message' => 'usergroup-deleted' ) );
		wp_redirect( $redirect_url );
		exit;		
	}
	
	/**
	 * Handle the request to update a given Usergroup via inline edit
	 *
	 * @since 0.7
	 */
	function handle_ajax_inline_save_usergroup() {
		
		if ( !wp_verify_nonce( $_POST['inline_edit'], 'usergroups-inline-edit-nonce' ) )
			die( $this->module->messages['nonce-failed'] );
			
		if ( !current_user_can( 'manage_options') )
			die( $this->module->messages['invalid-permissions'] );
		
		$usergroup_id = (int) $_POST['usergroup_id'];
		if ( !$existing_term = $this->get_usergroup_by( 'id', $usergroup_id ) )
			die( $this->module->messsage['usergroup-error'] );
		
		$name = strip_tags( trim( $_POST['name'] ) );
		$description = strip_tags( trim( $_POST['description'] ) );
		
		/**
		 * Form validation for editing Usergroup
		 */	
		// Check if name field was filled in
		if ( empty( $name ) ) {
			$change_error = new WP_Error( 'invalid', __( 'Please enter a name for the usergroup.', 'edit-flow' ) );
			die( $change_error->get_error_message() );
		}
		// Check that the name doesn't exceed 40 chars
		if ( strlen( $name ) > 40 ) {
			$change_error = new WP_Error( 'invalid', __( 'Usergroup name cannot exceed 40 characters. Please try a shorter name.' ) );
			die( $change_error->get_error_message() );
		}
		// Check to ensure a term with the same name doesn't exist
		$search_term = $this->get_usergroup_by( 'name', $name );
		if ( is_object( $search_term ) && $search_term->term_id != $existing_term->term_id ) {
			$change_error = new WP_Error( 'invalid', __( 'Name already in use. Please choose another.', 'edit-flow' ) );
			die( $change_error->get_error_message() );
		}
		// Check to ensure a term with the same slug doesn't exist
		$search_term = $this->get_usergroup_by( 'slug', sanitize_title( $name ) );
		if ( is_object( $search_term ) && $search_term->term_id != $existing_term->term_id ) {
			$change_error = new WP_Error( 'invalid', __( 'Name conflicts with slug for another term. Please choose again.', 'edit-flow' ) );
			die( $change_error->get_error_message() );
		}
		
		// Prepare the term name and description for saving
		$args = array(
			'name' => $name,
			'description' => $description,
		);
		$return = $this->update_usergroup( $existing_term->term_id, $args );
		if( !is_wp_error( $return ) ) {	
			set_current_screen( 'edit-usergroup' );					
			$wp_list_table = new EF_Usergroups_List_Table();
			$wp_list_table->prepare_items();
			echo $wp_list_table->single_row( $return );
			die();
		} else {
			$change_error = new WP_Error( 'invalid', sprintf( __( 'Could not update the usergroup: <strong>%s</strong>', 'edit-flow' ), $usergroup_name ) );
			die( $change_error->get_error_message() );
		}
		
	}
	
	/**
	 * Register settings for notifications so we can partially use the Settings API
	 * (We use the Settings API for form generation, but not saving)
	 * 
	 * @since 0.7
	 * @uses add_settings_section(), add_settings_field()
	 */
	function register_settings() {
			add_settings_section( $this->module->options_group_name . '_general', false, '__return_false', $this->module->options_group_name );
			add_settings_field( 'post_types', 'Add to these post types:', array( &$this, 'settings_post_types_option' ), $this->module->options_group_name, $this->module->options_group_name . '_general' );
	}
	
	/**
	 * Choose the post types for Usergroups
	 *
	 * @since 0.7
	 */
	function settings_post_types_option() {
		global $edit_flow;
		$edit_flow->settings->helper_option_custom_post_type( $this->module );
	}

	/**
	 * Validate data entered by the user
	 *
	 * @since 0.7
	 *
	 * @param array $new_options New values that have been entered by the user
	 * @return array $new_options Form values after they've been sanitized
	 */
	function settings_validate( $new_options ) {
		global $edit_flow;
		
		// Whitelist validation for the post type options
		if ( !isset( $new_options['post_types'] ) )
			$new_options['post_types'] = array();
		$new_options['post_types'] = $edit_flow->helpers->clean_post_type_options( $new_options['post_types'], $this->module->post_type_support );		
		
		return $new_options;
	}	
	
	/**
	 * Build a configuration view so we can manage our usergroups
	 *
	 * @since 0.7
	 */
	function print_configure_view() {
		global $edit_flow;
		?>
		
		<?php if ( isset( $_GET['action'], $_GET['usergroup-id'] ) && $_GET['action'] == 'edit-usergroup' ): ?>
		<?php /** Full page width view for editing a given usergroup **/
			// Check whether the usergroup exists
			$usergroup_id = (int)$_GET['usergroup-id'];
			$usergroup = $this->get_usergroup_by( 'id', $usergroup_id );
			if ( !$usergroup ) {
				echo '<div class="error"><p>' . $this->module->messages['usergroup-missing'] . '</p></div>';
				return; 
			}
			$name = ( isset( $_POST['name'] ) ) ? stripslashes( $_POST['name'] ) : $usergroup->name;
			$description = ( isset( $_POST['description'] ) ) ? stripslashes( $_POST['description'] ) : $usergroup->description;
		?>
		<form method="post" action="<?php echo esc_url( $this->get_link( array( 'action' => 'edit-usergroup', 'usergroup-id' => $usergroup_id ) ) ); ?>" >
		<div id="col-right"><div class="col-wrap"><div id="ef-usergroup-users" class="form-wrap">
			<h4><?php _e( 'Users', 'edit-flow' ); ?></h4>
			<?php 
			$select_form_args = array(
				'input_id' => 'usergroup_users'
				);
			?>
			<?php $this->users_select_form( $usergroup->user_ids , $select_form_args ); ?>
		</div></div></div>
		<div id="col-left"><div class="col-wrap"><div class="form-wrap">		
			<input type="hidden" name="form-action" value="edit-usergroup" />
			<input type="hidden" name="usergroup_id" value="<?php echo esc_attr( $usergroup_id ); ?>" />
			<?php
				wp_original_referer_field();
				wp_nonce_field( 'edit-usergroup' );
			?>
			<div class="form-field form-required">
				<label for="name"><?php _e( 'Name', 'edit-flow' ); ?></label></th>
				<input name="name" id="name" type="text" value="<?php echo esc_attr( $name ); ?>" size="40" maxlength="40" aria-required="true" />
				<?php $edit_flow->settings->helper_print_error_or_description( 'name', __( 'The name is used to identify the usergroup.', 'edit-flow' ) ); ?>
			</div>
			<div class="form-field">
				<label for="description"><?php _e( 'Description', 'edit-flow' ); ?></label>
				<textarea name="description" id="description" rows="5" cols="40"><?php echo esc_html( $description ); ?></textarea>
				<?php $edit_flow->settings->helper_print_error_or_description( 'description', __( 'The description is primarily for administrative use, to give you some context on what the usergroup is to be used for.', 'edit-flow' ) ); ?>
			</div>
			<p class="submit">
			<?php submit_button( __( 'Update Usergroup', 'edit-flow' ), 'primary', 'submit', false ); ?>
			<a class="cancel-settings-link" href="<?php echo esc_url( $this->get_link() ); ?>"><?php _e( 'Cancel', 'edit-flow' ); ?></a>
			</p>
		</div></div></div>
		</form>
		
		<?php else: ?>
			<?php
				$wp_list_table = new EF_Usergroups_List_Table();
				$wp_list_table->prepare_items();
			?>
			<script type="text/javascript">
				var ef_confirm_delete_usergroup_string = "<?php _e( 'Are you sure you want to delete the Usergroup?', 'edit-flow' ); ?>";
			</script>
			<div id="col-right"><div class="col-wrap">
				<?php $wp_list_table->display(); ?>
			</div></div>
			<div id="col-left"><div class="col-wrap"><div class="form-wrap">
				<h3 class="nav-tab-wrapper">
					<a href="<?php echo esc_url( $this->get_link() ); ?>" class="nav-tab<?php if ( !isset( $_GET['action'] ) || $_GET['action'] != 'change-options' ) echo ' nav-tab-active'; ?>"><?php _e( 'Add New', 'edit-flow' ); ?></a>
					<a href="<?php echo esc_url( $this->get_link( array( 'action' => 'change-options' ) ) ); ?>" class="nav-tab<?php if ( isset( $_GET['action'] ) && $_GET['action'] == 'change-options' ) echo ' nav-tab-active'; ?>"><?php _e( 'Options', 'edit-flow' ); ?></a>
				</h3>
				<?php if ( isset( $_GET['action'] ) && $_GET['action'] == 'change-options' ): ?>
				<form class="basic-settings" action="<?php echo esc_url( $this->get_link( array( 'action' => 'change-options' ) ) ); ?>" method="post">
					<?php settings_fields( $this->module->options_group_name ); ?>
					<?php do_settings_sections( $this->module->options_group_name ); ?>	
					<?php echo '<input id="edit_flow_module_name" name="edit_flow_module_name" type="hidden" value="' . esc_attr( $this->module->name ) . '" />'; ?>
					<?php submit_button(); ?>
				</form>
				<?php else: ?>
				<?php /** Custom form for adding a new Usergroup **/ ?>
					<form class="add:the-list:" action="<?php echo esc_url( $this->get_link() ); ?>" method="post" id="addusergroup" name="addusergroup">
					<div class="form-field form-required">
						<label for="name"><?php _e( 'Name', 'edit-flow' ); ?></label>
						<input type="text" aria-required="true" id="name" name="name" maxlength="40" value="<?php if ( !empty( $_POST['name'] ) ) echo esc_attr( $_POST['name'] ); ?>" />
						<?php $edit_flow->settings->helper_print_error_or_description( 'name', __( 'The name is used to identify the usergroup.', 'edit-flow' ) ); ?>
					</div>
					<div class="form-field">
						<label for="description"><?php _e( 'Description', 'edit-flow' ); ?></label>
						<textarea cols="40" rows="5" id="description" name="description"><?php if ( !empty( $_POST['description'] ) ) echo esc_html( $_POST['description'] ) ?></textarea>
						<?php $edit_flow->settings->helper_print_error_or_description( 'description', __( 'The description is primarily for administrative use, to give you some context on what the usergroup is to be used for.', 'edit-flow' ) ); ?>
					</div>
					<?php wp_nonce_field( 'add-usergroup' ); ?>
					<?php echo '<input id="form-action" name="form-action" type="hidden" value="add-usergroup" />'; ?>
					<?php submit_button( __( 'Add New Usergroup', 'edit-flow' ) ); ?>
					</form>
				<?php endif; ?>
			</div></div></div>
			<?php $wp_list_table->inline_edit(); ?>
		<?php endif;
	}
	
	/**
	 * Adds a form to the user profile page to allow adding usergroup selecting options
	 */
	function user_profile_page() {
		global $user_id, $profileuser;
		
		if ( !$user_id || !current_user_can( 'edit_usergroups' ) )
			return;
		
		// Assemble all necessary data
		$usergroups = $this->get_usergroups();
		$selected_usergroups = $this->get_usergroups_for_user( $user_id );
		$usergroups_form_args = array( 'input_id' => 'ef_usergroups' );
		?>
		<table id="ef-user-usergroups" class="form-table"><tbody><tr>
			<th>
				<h3><?php _e( 'Usergroups', 'edit-flow' ) ?></h3> 
				<p><?php _e( 'Select the usergroups that this user should is a part of:', 'edit-flow' ) ?></p>
			</th>
			<td>
				<?php $this->usergroups_select_form( $selected_usergroups, $usergroups_form_args ); ?>
				<script type="text/javascript">
				jQuery(document).ready(function(){
					jQuery('#ef-user-usergroups ul').listFilterizer();
				});
				</script>
			</td>
		</tr></tbody></table>
	<?php 
	}
	
	/**
	 * Function called when a user's profile is updated
	 * Adds user to specified usergroups
	 *
	 * @since 0.7
	 *
	 * @param ???
	 * @param ???
	 * @param ???
	 * @return ???
	 */
	function user_profile_update( $errors, $update, $user ) {
		
		if ( !$update )
			return array( &$errors, $update, &$user );
			
		// @todo Noncify
		if ( !current_user_can('edit_user', $user->ID ) )
			wp_die( $this->module->messages['invalid-permissions'] );
			
		if ( current_user_can( 'edit_usergroups' ) ) {
			// Sanitize the data and save
			$usergroups = array_map( 'intval', (array)$_POST['ef_usergroups'] );
			$all_usergroups = $this->get_usergroups();
			foreach( $all_usergroups as $usergroup ) {
				if ( in_array( $usergroup->term_id, $usergroups ) )
					$this->add_user_to_usergroup( $user->ID, $usergroup->term_id );
				else
					$this->remove_user_from_usergroup( $user->ID, $usergroup->term_id );
			}
		}
			
		return array( &$errors, $update, &$user );
	}
	
	/**
	 * Generate a link to one of the usergroups actions
	 *
	 * @since 0.7
	 *
	 * @param string $action Action we want the user to take
	 * @param array $args Any query args to add to the URL
	 * @return string $link Direct link to delete a usergroup
	 */
	function get_link( $args = array() ) {
		if ( !isset( $args['action'] ) )
			$args['action'] = '';
		if ( !isset( $args['page'] ) )
			$args['page'] = $this->module->settings_slug;
		// Add other things we may need depending on the action
		switch( $args['action'] ) {
			case 'delete-usergroup':
				$args['nonce'] = wp_create_nonce( $args['action'] );
				break;
			default:
				break;
		}
		return add_query_arg( $args, get_admin_url( null, 'admin.php' ) );
	}
	
	/**
	 * Displays a list of users that can be selected!
	 *
	 * @since 0.7
	 *
	 * @param ???
	 * @param ???
	 */
	function users_select_form( $selected = null, $args = null ) {
		global $blog_id;

		// TODO: Add pagination support for blogs with billions of users

		// Set up arguments
		$defaults = array(
			'list_class' => 'ef-post_following_list', 
			'input_id' => 'following_users'
		);
		$parsed_args = wp_parse_args( $args, $defaults );
		extract($parsed_args, EXTR_SKIP);

		$args = array(
			'who' => 'authors',
			'fields' => array(
				'ID',
				'display_name',
				'user_email'
			),
		);
		$users = get_users( $args );

		if ( !is_array($selected) ) $selected = array();
		?>

		<?php if( !empty($users) ) : ?>
			<ul id="ef-post_following_users" class="<?php echo $list_class ?>">
				<?php foreach( $users as $user ) : ?>
					<?php $checked = ( in_array($user->ID, $selected) ) ? 'checked="checked"' : ''; ?>
					<li>
						<label for="<?php echo $input_id .'_'. $user->ID ?>">
							<input type="checkbox" id="<?php echo $input_id .'_'. $user->ID ?>" name="<?php echo $input_id ?>[]" value="<?php echo $user->ID ?>" <?php echo $checked ?> />
							<span class="ef-user_displayname"><?php echo esc_html( $user->display_name ); ?></span>
							<span class="ef-user_useremail"><?php echo $user->user_email ?></span>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}
	
	/**
	 * Displays a list of usergroups with checkboxes
	 *
	 * @since 0.7
	 *
	 * @param array $selected List of usergroup keys that should be checked
	 * @param array $args ???
	 */
	function usergroups_select_form( $selected = array(), $args = null ) {

		// TODO add $args for additional options
		// e.g. showing members assigned to group (John Smith, Jane Doe, and 9 others)
		// before <tag>, after <tag>, class, id names?
		$defaults = array(
			'list_class' => 'ef-post_following_list',
			'list_id' => 'ef-following_usergroups',
			'input_id' => 'following_usergroups'
		);

		$parsed_args = wp_parse_args( $args, $defaults );
		extract( $parsed_args, EXTR_SKIP );
		$usergroups = $this->get_usergroups();
		if ( empty($usergroups) ) {
			?>
			<p><?php _e('No usergroups were found.', 'edit-flow') ?> <a href="<?php echo esc_url( $this->get_link() ); ?>" title="<?php _e('Add a new Usergroup. Opens new window.', 'edit-flow' ) ?>" target="_blank"><?php _e( 'Add a Usergroup', 'edit-flow' ); ?></a></p>
			<?php
		} else {

			?>
			<ul id="<?php echo $list_id ?>" class="<?php echo $list_class ?>">
			<?php
			foreach( $usergroups as $usergroup ) {
				$checked = ( in_array( $usergroup->term_id, $selected ) ) ? ' checked="checked"' : '';
				?>
				<li>
					<label for="<?php echo $input_id . esc_attr( $usergroup->term_id ); ?>" title="<?php echo esc_attr($usergroup->description) ?>">
						<input type="checkbox" id="<?php echo $input_id . esc_attr( $usergroup->term_id ) ?>" name="<?php echo $input_id ?>[]" value="<?php echo esc_attr( $usergroup->term_id ) ?>"<?php echo $checked ?> />
						<span class="ef-usergroup_name"><?php echo esc_html( $usergroup->name ); ?></span>
						<span class="ef-usergroup_description" title="<?php echo esc_attr($usergroup->description) ?>">
							<?php echo (strlen($usergroup->description) >= 50) ? substr_replace(esc_html($usergroup->description), '...', 50) : esc_html($usergroup->description); ?>
						</span>
					</label>
				</li>
				<?php
			}
			?>
			</ul>
			<?php
		}
	}
	
	/**
	 * Core Usergroups Module Functionality
	 */
	
	/**
	 * Get all of the registered usergroups. Returns an array of objects
	 *
	 * @since 0.7
	 * 
	 * @param array $args Arguments to filter/sort by
	 * @return array|bool $usergroups Array of Usergroups with relevant data, false if none
	 */
	function get_usergroups( $args = array() ) {
		
		// We want empty terms by default
		if ( !isset( $args['hide_empty'] ) )
			$args['hide_empty'] = 0;
		
		$usergroup_terms = get_terms( self::taxonomy_key, $args );	
		if ( !$usergroup_terms )
			return false;
		
		// Run the usergroups through get_usergroup_by() so we load users too
		$usergroups = array();
		foreach( $usergroup_terms as $usergroup_term ) {
			$usergroups[] = $this->get_usergroup_by( 'id', $usergroup_term->term_id );
		}
		return $usergroups;
	}
	
	/**
	 * Get all of the data associated with a single usergroup
	 * Usergroup contains:
	 * - ID (key = term_id)
	 * - Slug (prefixed with our special key to avoid conflicts)
	 * - Name
	 * - Description
	 * - User IDs (array of IDs)
	 *
	 * @since 0.7
	 *
	 * @param string $field 'id', 'name', or 'slug'
	 * @param int|string $value Value for the search field
	 * @return object|array|WP_Error $usergroup Usergroup information as specified by $output
	 */
	function get_usergroup_by( $field, $value ) {
		global $edit_flow;
		
		$usergroup = get_term_by( $field, $value, self::taxonomy_key );
		
		if ( !$usergroup || is_wp_error( $usergroup ) )
			return $usergroup;
		
		// We're using an encoded description field to store extra values
		// Declare $user_ids ahead of time just in case it's empty
		$usergroup->user_ids = array();
		$unencoded_description = $edit_flow->helpers->get_unencoded_description( $usergroup->description );
		if ( is_array( $unencoded_description ) ) {
			foreach( $unencoded_description as $key => $value ) {
				$usergroup->$key = $value;
			}
		}
		return $usergroup;
	}
	
	/**
	 * Create a new usergroup containing:
	 * - Name
	 * - Slug (prefixed with our special key to avoid conflicts)
	 * - Description
	 * - Users
	 *
	 * @since 0.7
	 *
	 * @param array $args Name (optional), slug and description for the usergroup
	 * @param array $user_ids IDs for the users to be added to the Usergroup
	 * @return object|WP_Error $usergroup Object for the new Usergroup on success, WP_Error otherwise
	 */
	function add_usergroup( $args = array(), $user_ids = array() ) {
		global $edit_flow;

		if ( !isset( $args['name'] ) )
			return new WP_Error( 'invalid', __( 'New Usergroups must have a name', 'edit-flow' ) );
			
		$name = $args['name'];			
		$default = array(
			'name' => '',
			'slug' => self::term_prefix . sanitize_title( $name ),
			'description' => '',
		);
		$args = array_merge( $default, $args );
			
		// Encode our extra fields and then store them in the description field
		$args_to_encode = array(
			'description' => $args['description'],
			'user_ids' => array_unique( $user_ids, SORT_NUMERIC ),
		);
		$encoded_description = $edit_flow->helpers->get_encoded_description( $args_to_encode );
		$args['description'] = $encoded_description;
		$usergroup = wp_insert_term( $name, self::taxonomy_key, $args );
		if ( is_wp_error( $usergroup ) )
			return $usergroup;
		
		return $this->get_usergroup_by( 'id', $usergroup['term_id'] );
	}
	
	/**
	 * Update a usergroup with new data.
	 * Fields can include:
	 * - Name
	 * - Slug (prefixed with our special key, of course)
	 * - Description
	 * - Users
	 *
	 * @since 0.7
	 *
	 * @param int $id Unique ID for the usergroup
	 * @param array $args Usergroup meta to update (name, slug, description)
	 * @param array $users Users to be added to the Usergroup. If set, removes existing users first.
	 * @return object|WP_Error $usergroup Object for the updated Usergroup on success, WP_Error otherwise
	 */
	function update_usergroup( $id, $args = array(), $users = null ) {
		global $edit_flow;
		
		$existing_usergroup = $this->get_usergroup_by( 'id', $id );
		if ( is_wp_error( $existing_usergroup ) )
			return new WP_Error( 'invalid', __( "Usergroup doesn't exist.", 'edit-flow' ) );
		
		// Encode our extra fields and then store them in the description field
		$args_to_encode = array();
		$args_to_encode['description'] = ( isset( $args['description'] ) ) ? $args['description'] : $existing_usergroup->description;
		$args_to_encode['user_ids'] = ( is_array( $users ) ) ? $users : $existing_usergroup->user_ids;
		$args_to_encode['user_ids'] = array_unique( $args_to_encode['user_ids'], SORT_NUMERIC );
		$encoded_description = $edit_flow->helpers->get_encoded_description( $args_to_encode );
		$args['description'] = $encoded_description;
		
		$usergroup = wp_update_term( $id, self::taxonomy_key, $args );
		if ( is_wp_error( $usergroup ) )
			return $usergroup;
		
		return $this->get_usergroup_by( 'id', $usergroup['term_id'] );
	}
	
	/**
	 * Delete a usergroup based on its term ID
	 *
	 * @since 0.7
	 *
	 * @param int $id Unique ID for the Usergroup
	 * @param bool|WP_Error Returns true on success, WP_Error on failure
	 */
	function delete_usergroup( $id ) {
		
		$retval = wp_delete_term( $id, self::taxonomy_key );
		return $retval;
	}
	
	/**
	 * Add an array of user logins or IDs to a given usergroup
	 *
	 * @since 0.7
	 *
	 * @param array $user_ids_or_logins User IDs or logins to be added to the usergroup	
	 * @param int $id Usergroup to perform the action on
	 * @param bool $reset Delete all of the relationships before adding
	 * @return bool $success Whether or not we were successful
	 */
	function add_users_to_usergroup( $user_ids_or_logins, $id, $reset = true ) {
		
		if ( !is_array( $user_ids_or_logins ) )
			return new WP_Error( 'invalid', __( "Invalid users variable. Should be array.", 'edit-flow' ) );
		
		// To dump the existing users from a usergroup, we need to pass an empty array
		$usergroup = $this->get_usergroup_by( 'id', $id );		
		if ( $reset ) {
			$retval = $this->update_usergroup( $id, null, array() );
			if ( is_wp_error( $retval ) )
				return $retval;
		}
		
		// Add the new users one by one to an array we'll pass back to the usergroup
		$new_users = array();
		foreach ( (array)$user_ids_or_logins as $user_id_or_login ) {
			if ( !is_numeric( $user_id_or_login ) )
				$new_users[] = get_user_by( 'login', $user_id_or_login )->ID;
			else
				$new_users[] = (int)$user_id_or_login;
		}
		$retval = $this->update_usergroup( $id, null, $new_users );
		if ( is_wp_error( $retval ) )
			return $retval;
		return true;
	}
	
	/**
	 * Add a given user to a Usergroup. Can use User ID or user login
	 *
	 * @since 0.7
	 *
	 * @param int|string $user_id_or_login User ID or login to be added to the Usergroups
	 * @param int|array $ids ID for the Usergroup(s)
	 * @return bool|WP_Error $retval Return true on success, WP_Error on error
	 */
	function add_user_to_usergroup( $user_id_or_login, $ids ) {
		
		if ( !is_numeric( $user_id_or_login ) )
			$user_id = get_user_by( 'login', $user_id_or_login )->ID;
		else
			$user_id = (int)$user_id_or_login;
		
		foreach( (array)$ids as $usergroup_id ) {
			$usergroup = $this->get_usergroup_by( 'id', $usergroup_id );
			$usergroup->user_ids[] = $user_id;
			$retval = $this->update_usergroup( $usergroup_id, null, $usergroup->user_ids );
			if ( is_wp_error( $retval ) )
				return $retval;
		}
		return true;
	}
	
	/**
	 * Remove a given user from one or more usergroups
	 *
	 * @since 0.7
	 *
	 * @param int|string $user_id_or_login User ID or login to be removed from the Usergroups
	 * @param int|array $ids ID for the Usergroup(s)
	 * @return bool|WP_Error $retval Return true on success, WP_Error on error
	 */
	function remove_user_from_usergroup( $user_id_or_login, $ids ) {
		
		if ( !is_numeric( $user_id_or_login ) )
			$user_id = get_user_by( 'login', $user_id_or_login )->ID;
		else
			$user_id = (int)$user_id_or_login;
		
		// Remove the user from each usergroup specified
		foreach( (array)$ids as $usergroup_id ) {
			$usergroup = $this->get_usergroup_by( 'id', $usergroup_id );
			// @todo I bet there's a PHP function for this I couldn't look up at 35,000 over the Atlantic
			foreach( $usergroup->user_ids as $key => $usergroup_user_id ) {
				if ( $usergroup_user_id == $user_id )
					unset( $usergroup->user_ids[$key] );
			}
			$retval = $this->update_usergroup( $usergroup_id, null, $usergroup->user_ids );
			if ( is_wp_error( $retval ) )
				return $retval;
		}
		return true;
		
	}
	
	/**
	 * Get all of the Usergroup ids or objects for a given user
	 *
	 * @since 0.7
	 *
	 * @param int|string $user_id_or_login User ID or login to search against
	 * @param array $ids_or_objects Whether to retrieve an array of IDs or usergroup objects
	 * @param array|bool $usergroup_objects_or_ids Array of usergroup 'ids' or 'objects', false on none
	 */
	function get_usergroups_for_user( $user_id_or_login, $ids_or_objects = 'ids' ) {

		if ( !is_numeric( $user_id_or_login ) )
			$user_id = get_user_by( 'login', $user_id_or_login )->ID;
		else
			$user_id = (int)$user_id_or_login;
		
		// Unfortunately, the easiest way to do this is get all usergroups
		// and then loop through each one to see if the user ID is stored
		$all_usergroups = $this->get_usergroups();
		$usergroup_objects_or_ids = array();
		foreach( $all_usergroups as $usergroup ) {
			// Not in this usergroup, so keep going
			if ( !in_array( $user_id, $usergroup->user_ids ) )
				continue;
			if ( $ids_or_objects == 'ids' )
				$usergroup_objects_or_ids[] = $usergroup->term_id;
			else if ( $ids_or_objects == 'ids' )
				$usergroup_objects_or_ids[] = $usergroup;			
		}
		return $usergroup_objects_or_ids;
	}
	
}

}


if ( !class_exists( 'EF_Usergroups_List_Table' ) ) {
/**
 * Usergroups uses WordPress' List Table API for generating the Usergroup management table
 *
 * @since 0.7
 */
class EF_Usergroups_List_Table extends WP_List_Table
{
	
	var $callback_args;
	
	function __construct() {
		
		parent::__construct( array(
			'plural' => 'usergroups',
			'singular' => 'usergroup',
			'ajax' => true
		) );
		
	}
	
	/**
	 * @todo Paginate if we have a lot of usergroups
	 *
	 * @since 0.7
	 */
	function prepare_items() {
		global $edit_flow;		
		
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = array();
		
		$this->_column_headers = array( $columns, $hidden, $sortable );
		
		$this->items = $edit_flow->usergroups->get_usergroups();
		
		$this->set_pagination_args( array(
			'total_items' => count( $this->items ),
			'per_page' => count( $this->items ),
		) );
	}

	/**
	 * Message to be displayed when there are no usergroups
	 *
	 * @since 0.7
	 */
	function no_items() {
		_e( 'No usergroups found.', 'edit-flow' );
	}
	
	/**
	 * Columns in our Usergroups table
	 *
	 * @since 0.7
	 */
	function get_columns() {
		global $edit_flow;

		$columns = array(
			'name' => __( 'Name', 'edit-flow' ),
			'description' => __( 'Description', 'edit-flow' ),
			'users' => __( 'Users in Group', 'edit-flow' ),			
		);
		
		return $columns;
	}
	
	/**
	 * Process the Usergroup column value for all methods that aren't registered
	 *
	 * @since 0.7
	 */
	function column_default( $usergroup, $column_name ) {
		global $edit_flow;
		
	}
	
	/**
	 * Process the Usergroup name column value.
	 * Displays the name of the Usergroup, and action links
	 *
	 * @since 0.7
	 */
	function column_name( $usergroup ) {
		global $edit_flow;
		
		// @todo direct edit link
		$output = '<strong><a href="' . esc_url( $edit_flow->usergroups->get_link( array( 'action' => 'edit-usergroup', 'usergroup-id' => $usergroup->term_id ) ) ) . '">' . esc_html( $usergroup->name ) . '</a></strong>';
		
		$actions = array();
		$actions['edit edit-usergroup'] = sprintf( '<a href="%1$s">' . __( 'Edit', 'edit-flow' ) . '</a>', $edit_flow->usergroups->get_link( array( 'action' => 'edit-usergroup', 'usergroup-id' => $usergroup->term_id ) ) );
		$actions['inline hide-if-no-js'] = '<a href="#" class="editinline">' . __( 'Quick&nbsp;Edit' ) . '</a>';
		$actions['delete delete-usergroup'] = sprintf( '<a href="%1$s">' . __( 'Delete', 'edit-flow' ) . '</a>', $edit_flow->usergroups->get_link( array( 'action' => 'delete-usergroup', 'usergroup-id' => $usergroup->term_id ) ) );
		
		$output .= $this->row_actions( $actions, false );
		$output .= '<div class="hidden" id="inline_' . $usergroup->term_id . '">';
		$output .= '<div class="name">' . esc_html( $usergroup->name ) . '</div>';
		$output .= '<div class="description">' . esc_html( $usergroup->description ) . '</div>';	
		$output .= '</div>';
		
		return $output;
			
	}
	
	/**
	 * Handle the 'description' column for the table of Usergroups
	 * Don't need to unencode this because we already did when the usergroup was loaded
	 *
	 * @since 0.7
	 */
	function column_description( $usergroup ) {
		return esc_html( $usergroup->description );
	}
	
	/**
	 * Show the "Total Users" in a given usergroup
	 *
	 * @since 0.7
	 */
	function column_users( $usergroup ) {
		global $edit_flow;
		return '<a href="' . esc_url( $edit_flow->usergroups->get_link( array( 'action' => 'edit-usergroup', 'usergroup-id' => $usergroup->term_id ) ) ) . '">' . count( $usergroup->user_ids ) . '</a>';
	}
	
	/**
	 * Prepare a single row of information about a usergroup
	 *
	 * @since 0.7
	 */
	function single_row( $usergroup ) {
		static $row_class = '';
		$row_class = ( $row_class == '' ? ' class="alternate"' : '' );

		echo '<tr id="usergroup-' . $usergroup->term_id . '"' . $row_class . '>';
		echo $this->single_row_columns( $usergroup );
		echo '</tr>';
	}
	
	/**
	 * If we use this form, we can have inline editing!
	 *
	 * @since 0.7
	 */
	function inline_edit() {
		global $edit_flow;
?>
	<form method="get" action=""><table style="display: none"><tbody id="inlineedit">
		<tr id="inline-edit" class="inline-edit-row" style="display: none"><td colspan="<?php echo $this->get_column_count(); ?>" class="colspanchange">
			<fieldset><div class="inline-edit-col">
				<h4><?php _e( 'Quick Edit' ); ?></h4>
				<label>
					<span class="title"><?php _e( 'Name', 'edit-flow' ); ?></span>
					<span class="input-text-wrap"><input type="text" name="name" class="ptitle" value="" maxlength="40" /></span>
				</label>
				<label>
					<span class="title"><?php _e( 'Description', 'edit-flow' ); ?></span>
					<span class="input-text-wrap"><input type="text" name="description" class="pdescription" value="" /></span>
				</label>
			</div></fieldset>
		<p class="inline-edit-save submit">
			<a accesskey="c" href="#inline-edit" title="<?php _e( 'Cancel' ); ?>" class="cancel button-secondary alignleft"><?php _e( 'Cancel' ); ?></a>
			<?php $update_text = __( 'Update Usergroup', 'edit-flow' ); ?>
			<a accesskey="s" href="#inline-edit" title="<?php echo esc_attr( $update_text ); ?>" class="save button-primary alignright"><?php echo $update_text; ?></a>
			<img class="waiting" style="display:none;" src="<?php echo esc_url( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" />
			<span class="error" style="display:none;"></span>
			<?php wp_nonce_field( 'usergroups-inline-edit-nonce', 'inline_edit', false ); ?>
			<br class="clear" />
		</p>
		</td></tr>
		</tbody></table></form>
	<?php
	}
		
}
}