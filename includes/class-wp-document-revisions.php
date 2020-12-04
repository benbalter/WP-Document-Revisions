<?php
/**
 * Main class for WP Document Revisions.
 *
 * @since 3.0.0
 * @package WP_Document_Revisions
 */

/**
 * Main WP_Document_Revisions class.
 */
class WP_Document_Revisions {

	/**
	 * Singleton instance.
	 *
	 * @var Object $instance
	 */
	public static $instance;

	/**
	 * Length of feed key.
	 *
	 * @var Int $key_length
	 */
	public static $key_length = 32;

	/**
	 * User meta key used auth feeds.
	 *
	 * @var String $meta_key
	 */
	public static $meta_key = 'document_revisions_feed_key';

	/**
	 * The plugin version.
	 *
	 * @var String $version
	 */
	public $version = '3.2.4';

	/**
	 * The WP default directory cache.
	 *
	 * @var Array $wp_default_dir
	 *
	 * @since 3.2
	 */
	public static $wp_default_dir = array();

	/**
	 * The document directory cache.
	 *
	 * @var String $wpdr_document_dir
	 *
	 * @since 3.2
	 */
	public static $wpdr_document_dir = null;

	/**
	 * Whether processing document or image directory.
	 *
	 * @var Boolean $doc_image
	 *
	 * @since 3.2
	 */
	public static $doc_image = true;

	/**
	 * Identify if processing document or image directory.
	 *
	 * @return Boolean $doc_image
	 *
	 * @since 3.2
	 */
	public function is_doc_image() {
		return self::$doc_image;
	}

	/**
	 * Taxonomy key - Workflow state or EditFlow or PublishPress statuses to use.
	 *
	 * @var String $taxonomy_key_val
	 *
	 * @since 3.3.0
	 */
	public static $taxonomy_key_val = 'workflow_state';

	/**
	 * Function to return Taxonomy key.
	 *
	 * @return String $taxonomy_key
	 *
	 * @since 3.3.0
	 */
	public static function taxonomy_key() {
		return self::$taxonomy_key_val;
	}

	/**
	 * Initiates an instance of the class and adds hooks.
	 *
	 * @since 0.5
	 */
	public function __construct() {
		self::$instance = &$this;

		// admin.
		add_action( 'plugins_loaded', array( &$this, 'admin_init' ) );
		add_action( 'plugins_loaded', array( &$this, 'i18n' ), 5 );
		add_action( 'admin_notices', array( &$this, 'activation_error_notice' ) );

		// CPT/CT.
		add_action( 'init', array( &$this, 'register_cpt' ) );
		add_action( 'init', array( &$this, 'use_read_capability' ) );
		add_action( 'init', array( &$this, 'register_ct' ), 2000 ); // note: low priority to allow for edit flow/publishpress support.
		add_action( 'admin_init', array( &$this, 'initialize_workflow_states' ) );
		add_action( 'admin_init', array( &$this, 'register_term_count_cb' ), 2000 ); // note: late and low priority to allow for all taxonomies.
		add_filter( 'the_content', array( &$this, 'content_filter' ), 1 );

		// rewrites and permalinks.
		add_filter( 'rewrite_rules_array', array( &$this, 'revision_rewrite' ) );
		add_filter( 'transient_rewrite_rules', array( &$this, 'revision_rewrite' ) );
		add_action( 'init', array( &$this, 'inject_rules' ) );
		add_action( 'post_type_link', array( &$this, 'permalink' ), 10, 4 );
		add_action( 'post_link', array( &$this, 'permalink' ), 10, 4 );
		add_filter( 'template_include', array( &$this, 'serve_file' ), 10, 1 );
		add_filter( 'serve_document_auth', array( &$this, 'serve_document_auth' ), 10, 3 );
		add_action( 'parse_request', array( &$this, 'ie_cache_fix' ) );
		add_filter( 'query_vars', array( &$this, 'add_query_var' ), 10, 4 );
		add_filter( 'default_feed', array( &$this, 'hijack_feed' ) );
		add_action( 'do_feed_revision_log', array( &$this, 'do_feed_revision_log' ) );
		add_action( 'template_redirect', array( $this, 'revision_feed_auth' ) );
		add_filter( 'get_sample_permalink_html', array( &$this, 'sample_permalink_html_filter' ), 10, 4 );
		add_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ), 10, 2 );
		add_filter( 'image_downsize', array( &$this, 'image_downsize' ), 10, 3 );
		add_filter( 'document_path', array( &$this, 'wamp_document_path_filter' ), 9, 1 );
		add_filter( 'redirect_canonical', array( &$this, 'redirect_canonical_filter' ), 10, 2 );

		// RSS.
		add_filter( 'private_title_format', array( &$this, 'no_title_prepend' ), 20 );
		add_filter( 'protected_title_format', array( &$this, 'no_title_prepend' ), 20 );
		add_filter( 'the_title', array( &$this, 'add_revision_num_to_title' ), 20, 2 );

		// uploads.
		add_filter( 'upload_dir', array( &$this, 'document_upload_dir_filter' ), 10, 2 );
		add_filter( 'attachment_link', array( &$this, 'attachment_link_filter' ), 10, 2 );
		add_filter( 'wp_handle_upload_prefilter', array( &$this, 'filename_rewrite' ) );
		add_filter( 'wp_handle_upload', array( &$this, 'rewrite_file_url' ), 10, 2 );

		// locking.
		add_action( 'wp_ajax_override_lock', array( &$this, 'override_lock' ) );

		// cache clean.
		add_action( 'save_post_document', array( &$this, 'clear_cache' ) );

		// edit flow or PublishPress.
		add_action( 'ef_module_options_loaded', array( &$this, 'edit_flow_support' ) );
		add_action( 'pp_module_options_loaded', array( &$this, 'publishpress_support' ) );
		// always called to determine whether user has turned off workflow_state support.
		add_action( 'init', array( &$this, 'disable_workflow_states' ), 1900 );

		// don't leak summary information if user can't access admin pages.
		add_filter( 'get_the_excerpt', array( &$this, 'empty_excerpt_return' ), 10, 2 );

		// no next/previous navigation links (would appear on password entry page).
		add_filter( 'get_next_post_where', array( &$this, 'suppress_adjacent_doc' ), 10, 5 );
		add_filter( 'get_previous_post_where', array( &$this, 'suppress_adjacent_doc' ), 10, 5 );

		// load front-end features (shortcode, widgets, etc.).
		include __DIR__ . '/class-wp-document-revisions-front-end.php';
		include __DIR__ . '/class-wp-document-revisions-recently-revised-widget.php';

		// For shortcode blocks, json endpoint need to link back to front end and widget so make global.
		global $wpdr_fe, $wpdr_widget;

		$wpdr_fe     = new WP_Document_Revisions_Front_End( $this );
		$wpdr_widget = new WP_Document_Revisions_Recently_Revised_Widget();
	}

	/**
	 * Callback called when the plugin is initially activated
	 *
	 * Registers custom capabilities and flushes rewrite rules.
	 *
	 * @return void
	 */
	public function activation_hook() {
		$this->add_caps();
		flush_rewrite_rules();
		if ( ! current_user_can( 'edit_documents' ) ) {
			// Unfortunately we cannot create a message directly out of the activation process, so create transient data.
			set_transient( 'wpdr_activation_issue', true, 15 );
		}
	}

	/**
	 * Called when the plugin is initially activated and checks whether the user has edit_documents capability.
	 *
	 * This can occur if the user has multiple roles with one denying access overriding the admin access.
	 *
	 * @since 3.2.3
	 * @return void
	 */
	public function activation_error_notice() {
		if ( get_transient( 'wpdr_activation_issue' ) ) {
			delete_transient( 'wpdr_activation_issue' );
			// timing of initial permissions being set as can give message before initial activation.
			if ( ! current_user_can( 'edit_documents' ) ) {
				?>
				<div class="notice notice-warning is-dismissible"><p>
				<?php esc_html_e( 'You do not have the edit_documents capability possibly due to multiple conficting roles or use of a custom role!', 'wp-document-revisions' ); ?>
				</p><p>
				<?php esc_html_e( 'Documents menu may not be displayed completely with "All Documents" and "Add Document" options missing', 'wp-document-revisions' ); ?>
				</p></div>
				<?php
			}
		}
	}

	/**
	 * Init i18n files
	 * Must be done early on init because they need to be in place when register_cpt is called.
	 */
	public function i18n() {
		load_plugin_textdomain( 'wp-document-revisions', false, plugin_basename( dirname( __DIR__ ) ) . '/languages/' );
	}


	/**
	 * Clear document directory name cache
	 *
	 * Used by Admin function when changing options.
	 *
	 * @return void
	 *
	 * @since 3.2
	 */
	public function clear_document_dir_cache() {
		self::$wpdr_document_dir = null;
	}


	/**
	 * Set the default content directory name into cacle.
	 *
	 * @return void
	 *
	 * @since 3.2
	 */
	private function set_default_dir_cache() {
		remove_filter( 'upload_dir', array( &$this, 'document_upload_dir_filter' ), 10 );
		self::$wp_default_dir = wp_upload_dir();
		add_filter( 'upload_dir', array( &$this, 'document_upload_dir_filter' ), 10 );
	}


	/**
	 * Extends class with admin functions when in admin backend.
	 *
	 * @since 0.5
	 */
	public function admin_init() {
		// only fire on admin + escape hatch to prevent fatal errors.
		if ( ! is_admin() || class_exists( 'WP_Document_Revisions_Admin' ) ) {
			return;
		}

		include __DIR__ . '/class-wp-document-revisions-admin.php';
		$this->admin = new WP_Document_Revisions_Admin( self::$instance );
	}


	/**
	 * Registers the document custom post type.
	 *
	 * @since 0.5
	 */
	public function register_cpt() {
		$labels = array(
			'name'                  => _x( 'Documents', 'post type general name', 'wp-document-revisions' ),
			'singular_name'         => _x( 'Document', 'post type singular name', 'wp-document-revisions' ),
			'add_new'               => _x( 'Add Document', 'document', 'wp-document-revisions' ),
			'add_new_item'          => __( 'Add New Document', 'wp-document-revisions' ),
			'edit_item'             => __( 'Edit Document', 'wp-document-revisions' ),
			'new_item'              => __( 'New Document', 'wp-document-revisions' ),
			'view_item'             => __( 'View Document', 'wp-document-revisions' ),
			'view_items'            => __( 'View Documents', 'wp-document-revisions' ),
			'search_items'          => __( 'Search Documents', 'wp-document-revisions' ),
			'not_found'             => __( 'No documents found', 'wp-document-revisions' ),
			'not_found_in_trash'    => __( 'No documents found in Trash', 'wp-document-revisions' ),
			'parent_item_colon'     => '',
			'menu_name'             => __( 'Documents', 'wp-document-revisions' ),
			'all_items'             => __( 'All Documents', 'wp-document-revisions' ),
			'featured_image'        => __( 'Document Image', 'wp-document-revisions' ),
			'set_featured_image'    => __( 'Set Document Image', 'wp-document-revisions' ),
			'remove_featured_image' => __( 'Remove Document Image', 'wp-document-revisions' ),
			'use_featured_image'    => __( 'Use as Document Image', 'wp-document-revisions' ),
		);

		$args = array(
			'labels'               => $labels,
			'publicly_queryable'   => true,
			'public'               => true,
			'show_ui'              => true,
			'show_in_menu'         => true,
			'query_var'            => true,
			'rewrite'              => true,
			'capability_type'      => array( 'document', 'documents' ),
			'map_meta_cap'         => true,
			'has_archive'          => true,
			'hierarchical'         => false,
			'menu_position'        => null,
			'register_meta_box_cb' => array( &$this->admin, 'meta_cb' ),
			'supports'             => array( 'title', 'author', 'revisions', 'excerpt', 'custom-fields', 'thumbnail' ),
			'menu_icon'            => plugins_url( '../img/menu-icon.png', __FILE__ ),
		);

		/**
		 * Filters the delivered document type definition prior to registering it.
		 *
		 * @since 0.5
		 *
		 * @param array $args delivered document type definition.
		 */
		register_post_type( 'document', apply_filters( 'document_revisions_cpt', $args ) );

		// Ensure that there is a post-thumbnail size set - could/should be set by theme - copy from thumbnail.
		if ( ! array_key_exists( 'post-thumbnail', wp_get_additional_image_sizes() ) ) {
			add_image_size( 'post-thumbnail', get_option( 'thumbnail_size_w' ), get_option( 'thumbnail_size_h' ), false );
		}

		if ( empty( self::$wp_default_dir ) ) {
			// Set the default upload directory cache.
			$this->set_default_dir_cache();
		}

		// Set Global for Document Image from Cookie doc_image (may be updated later).
		self::$doc_image = ( isset( $_COOKIE['doc_image'] ) ? 'true' === $_COOKIE['doc_image'] : true );
	}

	/**
	 * Determines if read requires read_document instead of read.
	 *
	 * @since 3.3
	 */
	public function use_read_capability() {
		// user requires read_document and not just read to read document.
		/**
		 * Filters the users capacities to require read (or read_document) capability.
		 *
		 * @since 3.3
		 *
		 * @param boolean true  default action to capability read for documents (not read_document).
		 */
		if ( ! apply_filters( 'document_read_uses_read', true ) ) {
			// invoke logic.
			add_filter( 'map_meta_cap', array( &$this, 'map_meta_cap' ), 10, 4 );
			add_filter( 'user_has_cap', array( &$this, 'user_has_cap' ), 10, 4 );
			if ( ! current_user_can( 'read_documents' ) ) {
				// user does not have read_documents capability, so any need to be filtered out of results.
				add_filter( 'posts_results', array( &$this, 'posts_results' ), 10, 2 );	
			}
		}

		// filter the queries.
		add_action( 'pre_get_posts', array( &$this, 'retrieve_documents' ) );
	}

	/**
	 * Registers custom status taxonomy.
	 *
	 * @since 0.5
	 */
	public function register_ct() {
		$labels = array(
			'name'              => _x( 'Workflow States', 'taxonomy general name', 'wp-document-revisions' ),
			'singular_name'     => _x( 'Workflow State', 'taxonomy singular name', 'wp-document-revisions' ),
			'search_items'      => __( 'Search Workflow States', 'wp-document-revisions' ),
			'all_items'         => __( 'All Workflow States', 'wp-document-revisions' ),
			'parent_item'       => __( 'Parent Workflow State', 'wp-document-revisions' ),
			'parent_item_colon' => __( 'Parent Workflow State:', 'wp-document-revisions' ),
			'edit_item'         => __( 'Edit Workflow State', 'wp-document-revisions' ),
			'update_item'       => __( 'Update Workflow State', 'wp-document-revisions' ),
			'add_new_item'      => __( 'Add New Workflow State', 'wp-document-revisions' ),
			'new_item_name'     => __( 'New Workflow State Name', 'wp-document-revisions' ),
			'menu_name'         => __( 'Workflow States', 'wp-document-revisions' ),
		);

		/**
		 * Filters the default structure and label values of the workflow_state taxonomy on declaration.
		 *
		 * @since 0.5
		 *
		 * @param array of default structure and label workflow_state values.
		 */
		register_taxonomy(
			'workflow_state',
			array( 'document' ),
			apply_filters(
				'document_revisions_ct',
				array(
					'hierarchical'          => false,
					'labels'                => $labels,
					'show_ui'               => true,
					'rewrite'               => false,
					'update_count_callback' => array( &$this, 'term_count_cb' ),
				)
			)
		);
	}


	/**
	 * Propagates initial workflow states on plugin activation.
	 *
	 * @since 0.5
	 * @return unknown
	 */
	public function initialize_workflow_states() {
		$terms = get_terms(
			'workflow_state',
			array(
				'hide_empty' => false,
			)
		);

		if ( ! empty( $terms ) ) {
			return false;
		}

		$states = array(
			__( 'In Progress', 'wp-document-revisions' )   => __( 'Document is in the process of being written', 'wp-document-revisions' ),
			__( 'Initial Draft', 'wp-document-revisions' ) => __( 'Document is being edited and refined', 'wp-document-revisions' ),
			__( 'Under Review', 'wp-document-revisions' )  => __( 'Document is pending final review', 'wp-document-revisions' ),
			__( 'Final', 'wp-document-revisions' )         => __( 'Document is in its final form', 'wp-document-revisions' ),
		);

		/**
		 * Filters the default workflow state values.
		 *
		 * @since 0.5
		 *
		 * @param array $states default workflow_state values.
		 */
		$states = apply_filters( 'default_workflow_states', $states );

		foreach ( $states as $state => $desc ) {
			wp_insert_term(
				$state,
				'workflow_state',
				array(
					'description' => $desc,
				)
			);
		}
	}

	/**
	 * Given a post object, returns all attached uploads.
	 *
	 * @since 0.5
	 * @param object $document (optional) post object.
	 * @return object all attached uploads
	 */
	public function get_attachments( $document = '' ) {
		if ( '' === $document ) {
			global $post;
			$document = $post;
		}

		// verify that it's an object.
		if ( ! is_object( $document ) ) {
			$document = get_post( $document );
		}

		// check for revisions.
		$parent = wp_is_post_revision( $document );
		if ( $parent ) {
			$document = get_post( $parent );
		}

		// check for attachments.
		if ( 'attachment' === $document->post_type ) {
			$document = get_post( $document->post_parent );
		}

		if ( ! isset( $document->ID ) ) {
			return array();
		}

		$args = array(
			'post_parent' => $document->ID,
			'post_status' => 'inherit',
			'post_type'   => 'attachment',
			'order'       => 'DESC',
			'orderby'     => 'post_date',
		);

		/**
		 * Filters the plugin query to fetch all the attachments of a parent post.
		 *
		 * @param array $args Delivered WP Query to fetch all attachments for a document.
		 */
		$args = apply_filters( 'document_revision_query', $args );

		return get_children( $args );
	}


	/**
	 * Checks if document is locked, if so, returns the lock holder's name.
	 *
	 * @since 0.5
	 * @param object|int $document the post object or postID.
	 * @return bool|string false if no lock, user's display name if locked
	 */
	public function get_document_lock( $document ) {
		if ( ! is_object( $document ) ) {
			$document = get_post( $document );
		}

		if ( ! $document ) {
			return false;
		}

		// get the post lock.
		$user = wp_check_post_lock( $document->ID );
		if ( ! ( $user ) ) {
			$user = false;
		}

		// allow others to shortcircuit.
		/**
		 * Filters the user locking the document file.
		 *
		 * @param string $user     user locking the document.
		 * @param object $document Post object.
		 */
		$user = apply_filters( 'document_lock_check', $user, $document );

		if ( ! $user ) {
			return false;
		}

		// get displayname from userID.
		$last_user = get_userdata( $user );
		return ( $last_user ) ? $last_user->display_name : __( 'Somebody', 'wp-document-revisions' );
	}


	/**
	 * Given a file, returns the file's extension.
	 *
	 * @since 0.5
	 * @param string $file URL, path, or filename to file.
	 * @return string extension
	 */
	public function get_extension( $file ) {
		$extension = '.' . pathinfo( $file, PATHINFO_EXTENSION );

		// don't return a . extension.
		if ( '.' === $extension ) {
			return '';
		}

		/**
		 * Filters the file extension.
		 *
		 * @since 0.5
		 *
		 * @param string $extension attachment file name extension.
		 * @param string $file      attachment file name.
		 */
		return apply_filters( 'document_extension', $extension, $file );
	}


	/**
	 * Gets a file extension from a post.
	 *
	 * @since 0.5
	 * @param object|int $document_or_attachment document or attachment.
	 * @return string the extension to the latest revision
	 */
	public function get_file_type( $document_or_attachment = '' ) {
		if ( '' === $document_or_attachment ) {
			global $post;
			$document_or_attachment = $post;
		}

		if ( ! is_object( $document_or_attachment ) ) {
			$document_or_attachment = get_post( $document_or_attachment );
		}

		// note, changing $post here would break $post in the global scope
		// rename $post to attachment, or grab the attachment from $post
		// either way, $attachment is now the object we're looking to query.
		if ( 'attachment' === get_post_type( $document_or_attachment ) ) {
			$attachment = $document_or_attachment;
		} elseif ( 'document' === get_post_type( $document_or_attachment ) ) {
			$latest_revision = $this->get_latest_revision( $document_or_attachment->ID );

			// verify a previous revision exists.
			if ( ! $latest_revision ) {
				return '';
			}

				$attachment = get_post( $latest_revision->post_content );

			// sanity check in case post_content somehow doesn't represent an attachment,
			// or in case some sort of non-document, non-attachment object/ID was passed.
			if ( get_post_type( $attachment ) !== 'attachment' ) {
				return '';
			}
		}

		// although get_attached_file uses the standard directory,
		// not used, so doesn't matter so no correction needed.
		return $this->get_extension( get_attached_file( $attachment->ID ) );
	}


	/**
	 * Adds document CPT rewrite rules.
	 *
	 * @since 0.5
	 */
	public function inject_rules() {
		global $wp_rewrite;
		$wp_rewrite->add_rewrite_tag( '%document%', '([^.]+)\.[A-Za-z0-9]{1,7}?', 'document=' );
	}


	/**
	 * Filter to remove previous rewrite rules.
	 *
	 * @since 3.3.0
	 * @param string $key key of rewrite rule.
	 */
	private function remove_old_rules( $key ) {
		$slug = $this->document_slug();
		if ( 0 === strpos( $key, $slug . '/([0-9]{4})/([0-9]{1,2})/([^.]+)' ) ) {
			return false;
		}
		return true;
	}


	/**
	 * Adds document rewrite rules to the rewrite array.
	 *
	 * @since 0.5
	 * @param Array $rules rewrite rules.
	 * @return Array rewrite rules
	 */
	public function revision_rewrite( $rules ) {
		$slug = $this->document_slug();

		// remove any previous versions of file matches (will be added back if same).
		$rules = array_filter( $rules, array( $this, 'remove_old_rules' ), ARRAY_FILTER_USE_KEY );

		$my_rules = array();

		// revisions in the form of yyyy/mm/[slug]-revision-##.[extension], yyyy/mm/[slug]-revision-##.[extension]/, yyyy/mm/[slug]-revision-##/ and yyyy/mm/[slug]-revision-##.
		$my_rules[ $slug . '/([0-9]{4})/([0-9]{1,2})/([^.]+)-' . __( 'revision', 'wp-document-revisions' ) . '-([0-9]+)[.][A-Za-z0-9]{1,7}/?$' ] = 'index.php?year=$matches[1]&monthnum=$matches[2]&document=$matches[3]&revision=$matches[4]';

		// revision feeds in the form of yyyy/mm/[slug]-revision-##.[extension]/feed/, yyyy/mm/[slug]-revision-##/feed/, etc.
		$my_rules[ $slug . '/([0-9]{4})/([0-9]{1,2})/([^.]+)([.][A-Za-z0-9]{1,7})?/feed/?$' ] = 'index.php?year=$matches[1]&monthnum=$matches[2]&document=$matches[3]&feed=feed';

		// documents in the form of yyyy/mm/[slug]-revision-##.[extension], yyyy/mm/[slug]-revision-##.[extension]/.
		$my_rules[ $slug . '/([0-9]{4})/([0-9]{1,2})/([^.]+)[.][A-Za-z0-9]{1,7}/?$' ] = 'index.php?year=$matches[1]&monthnum=$matches[2]&document=$matches[3]';

		// site.com/documents/ should list all documents that user has access to (private, public).
		$my_rules[ $slug . '/?$' ]                   = 'index.php?post_type=document';
		$my_rules[ $slug . '/page/?([0-9]{1,})/?$' ] = 'index.php?post_type=document&paged=$matches[1]';

		/**
		 * Filters the Document rewrite rules.
		 *
		 * @param array $my_rules Array of rewrite rules to add for for Documents.
		 * @param array $rules    Array of rewrite rules being built.
		 */
		$my_rules = apply_filters( 'document_rewrite_rules', $my_rules, $rules );

		return $my_rules + $rules;
	}


	/**
	 * Tells WP to recognize document query vars.
	 *
	 * @since 0.5
	 * @param array $vars the query vars.
	 * @return array the modified query vars
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'revision';
		$vars[] = 'document';
		return $vars;
	}


	/**
	 * Builds document post type permalink.
	 *
	 * @since 0.5
	 * @param string $link original permalink.
	 * @param object $document post object.
	 * @param bool   $leavename whether to leave the %document% placeholder.
	 * @param String $sample (optional) not used.
	 * @return string the real permalink
	 */
	public function permalink( $link, $document, $leavename, $sample = '' ) {
		global $wp_rewrite;
		$revision_num = false;

		// if this isn't our post type, kick.
		if ( ! $this->verify_post_type( $document ) ) {
			return $link;
		}

		// check if it's a revision.
		if ( 'revision' === $document->post_type ) {
			$parent            = clone get_post( $document->post_parent );
			$revision_num      = $this->get_revision_number( $document->ID );
			$parent->post_name = $parent->post_name . __( '-revision-', 'wp-document-revisions' ) . $revision_num;
			$document          = $parent;
		}

		// if no permastruct.
		if ( '' === $wp_rewrite->permalink_structure || in_array( $document->post_status, array( 'pending', 'draft' ), true ) ) {
			$link = site_url( '?post_type=document&p=' . $document->ID );
			if ( $revision_num ) {
				$link = add_query_arg( 'revision', $revision_num, $link );
			}
		} else {
			// build documents/yyyy/mm/slug.
			$extension = $this->get_file_type( $document );
			$timestamp = strtotime( $document->post_date_gmt );

			$link  = untrailingslashit( home_url() ) . '/' . $this->document_slug() . '/' . gmdate( 'Y', $timestamp ) . '/' . gmdate( 'm', $timestamp ) . '/';
			$link .= ( $leavename ) ? '%document%' : $document->post_name;
			$link .= $extension;
			// add trailing slash if user has set it as their permalink.
			$link = user_trailingslashit( $link );
		}

		/**
		 * Filters the Document permalink.
		 *
		 * @param string $link     generated permalink.
		 * @param object $document Post object.
		 */
		$link = apply_filters( 'document_permalink', $link, $document );

		return $link;
	}


	/**
	 * Filters permalink displayed on edit screen in the event that there is no attachment yet uploaded.
	 *
	 * @rerurns string modified HTML
	 * @since 0.5
	 * @param string $html original HTML.
	 * @param int    $id Post ID.
	 * @return unknown
	 */
	public function sample_permalink_html_filter( $html, $id ) {
		$document = get_post( $id );

		// verify post type.
		if ( ! $this->verify_post_type( $document ) ) {
			return $html;
		}

		// grab attachments.
		$attachments = $this->get_attachments( $document );

		// if no attachments, return nothing.
		if ( empty( $attachments ) ) {
			return '';
		}

		// otherwise return html unfiltered.
		return $html;
	}


	/**
	 * Retrieves all revisions for a given post (including the current post)
	 * Workaround for #16215 to ensure revision author is accurate
	 * http://core.trac.wordpress.org/ticket/16215.
	 * Workaround removed as a) 16215 fixed 6 years ago and b) gives erroneous results
	 *
	 * @since 1.0
	 * @param int $post_id the post ID.
	 * @return array array of post objects
	 */
	public function get_revisions( $post_id ) {
		$document = get_post( $post_id );

		if ( ! $document ) {
			return false;
		}

		$cache = wp_cache_get( $post_id, 'document_revisions' );
		if ( $cache ) {
			return $cache;
		}

		// correct the modified date.
		$document->post_date = gmdate( 'Y-m-d H:i:s', (int) get_post_modified_time( 'U', null, $post_id ) );

		// fix for Quotes in the most recent post because it comes from get_post.
		$document->post_excerpt = html_entity_decode( $document->post_excerpt );

		// get revisions, remove autosaves, and prepend the post.
		$get_revs = wp_get_post_revisions(
			$post_id,
			array(
				'order' => 'DESC',
			)
		);

		$revs     = array();
		$post_rev = $post_id . '-autosave';
		foreach ( $get_revs as $id => &$get_rev ) {
			if ( false === strpos( $get_rev->post_name, $post_rev ) ) {
				// not an autosave.
				$revs[ $id ] = $get_rev;
			}
		}
		array_unshift( $revs, $document );

		wp_cache_set( $post_id, $revs, 'document_revisions' );

		return $revs;
	}


	/**
	 * Returns a modified WP Query object of a document and its revisions
	 * Corrects the authors bug.
	 *
	 * @since 1.0.4
	 * @param int  $post_id the ID of the document.
	 * @param bool $feed (optional) whether this is a feed.
	 * @return obj|bool the WP_Query object, false on failure
	 */
	public function get_revision_query( $post_id, $feed = false ) {
		$posts = $this->get_revisions( $post_id );

		if ( ! $posts ) {
			return false;
		}

		// suppress revisions if user cannot read them to keep just the document.
		if ( ! current_user_can( 'read_document_revisions' ) ) {
			$posts = array_slice( $posts, 0, 1, true );
		}

		$rev_query             = new WP_Query();
		$rev_query->posts      = $posts;
		$rev_query->post_count = count( $posts );
		$rev_query->is_feed    = $feed;

		return $rev_query;
	}


	/**
	 * For a given post, builds a 1-indexed array of revision post ID's.
	 *
	 * @since 0.5
	 * @param int $post_id the parent post id.
	 * @return array array of revisions
	 */
	public function get_revision_indices( $post_id ) {
		$cache = wp_cache_get( $post_id, 'document_revision_indices' );
		if ( $cache ) {
			return $cache;
		}

		$revs = wp_get_post_revisions(
			$post_id,
			array(
				'order' => 'ASC',
			)
		);

		$i = 1;

		// ignore autosaves keeping only real revisions.
		$output   = array();
		$post_rev = $post_id . '-autosave';
		foreach ( $revs as $rev ) {
			if ( false === strpos( $rev->post_name, $post_rev ) ) {
				// not an autosave.
				$output[ $i++ ] = $rev->ID;
			}
		}

		if ( ! empty( $output ) ) {
			wp_cache_set( $post_id, $output, 'document_revision_indices' );
		}

		return $output;
	}


	/**
	 * Given a revision id (post->ID) returns the revisions spot in the sequence.
	 *
	 * @since 0.5
	 * @param int $revision_id the revision ID.
	 * @return int revision number
	 */
	public function get_revision_number( $revision_id ) {
		$revision = get_post( $revision_id );

		if ( ! isset( $revision->post_parent ) ) {
			return false;
		}

		$index = $this->get_revision_indices( $revision->post_parent );

		return array_search( $revision_id, $index, true );
	}


	/**
	 * Given a revision number (e.g., 4 from foo-revision-4) returns the revision ID.
	 *
	 * @since 0.5
	 * @param int $revision_num the 1-indexed revision #.
	 * @param int $post_id the ID of the parent post.
	 * @return int the ID of the revision
	 */
	public function get_revision_id( $revision_num, $post_id ) {
		$index = $this->get_revision_indices( $post_id );

		return ( isset( $index[ $revision_num ] ) ) ? $index[ $revision_num ] : false;

	}


	/**
	 * Serves document files.
	 *
	 * @since 0.5
	 * @param String $template the requested template.
	 * @return String the resolved template
	 */
	public function serve_file( $template ) {
		global $post;
		global $wp_query;
		global $wp;

		if ( ! is_single() ) {
			return $template;
		}

		if ( ! $this->verify_post_type( $post ) ) {
			return $template;
		}

		// if this is a passworded document and no password is sent
		// use the normal template which should prompt for password.
		if ( post_password_required( $post ) ) {
			return $template;
		}

		// grab the post revision if any.
		$version = get_query_var( 'revision' );

		// if there's not a post revision given, default to the latest.
		if ( ! $version ) {
			$revn = $this->get_latest_revision( $post->ID );
			if ( false === $revn ) {
				// no revision.
				wp_die(
					esc_html__( 'No document file is attached.', 'wp-document-revisions' ),
					null,
					array( 'response' => 403 )
				);
				// for unit testing.
				$wp_query->is_404 = true;
				return false;
			}
			$rev_id = $revn->ID;
		} else {
			$rev_id = $this->get_revision_id( $version, $post->ID );
		}

		// get the attachment (id in post_content of rev_id).
		$attach = get_post( get_post_field( 'post_content', $rev_id ) );
		$file   = get_attached_file( $attach->ID );

		// Above used a cached version of std directory, so cannot change within call and may be wrong,
		// so possibly replace it in the output.
		if ( empty( self::$wp_default_dir ) ) {
			// Set the default upload directory cache.
			$this->set_default_dir_cache();
		}
		$std_dir = self::$wp_default_dir['basedir'];
		$doc_dir = $this->document_upload_dir();
		if ( $std_dir !== $doc_dir ) {
			$file = str_replace( $std_dir, $doc_dir, $file );
		}

		// flip slashes for WAMP settups to prevent 404ing on the next line.
		/**
		 * Filters the file name for WAMP settings (filter routine provided by plugin).
		 *
		 * @param string $file attached file name.
		 */
		$file = apply_filters( 'document_path', $file );

		// return 404 if the file is a dud or malformed.
		if ( ! is_file( $file ) ) {

			// this will send 404 and no cache headers
			// and tell wp_query that this is a 404 so that is_404() works as expected
			// and theme formats appropriately.
			$wp_query->posts          = array();
			$wp_query->queried_object = null;
			$wp_query->is_404         = true;
			$wp->handle_404();

			// tell WP to serve the theme's standard 404 template, this is a filter after all...
			return get_404_template();

		}

		// note: authentication is happening via a hook here to allow shortcircuiting.
		/**
		 * Filters the decision to serve the document through WP Document Revisions.
		 *
		 * I.e. return null if user not logged on and want to deny existence.
		 * (only if filter 'document_read_uses_read' returns false)
		 *
		 * @param boolean true     default action to serve file.
		 * @param object  $post    WP Post to be served.
		 * @param string  $version Document revision.
		 */
		$serve_file = apply_filters( 'serve_document_auth', true, $post, $version );
		if ( ! $serve_file ) {
			if ( false === $serve_file ) {
				wp_die(
					esc_html__( 'You are not authorized to access that file.', 'wp-document-revisions' ),
					null,
					array( 'response' => 403 )
				);
				// for unit testing.
				$wp_query->is_404 = true;
				return false;
			} else {
				// not logged on, deny file existence (as above).
				$wp_query->posts          = array();
				$wp_query->queried_object = null;
				$wp_query->is_404         = true;
				$wp->handle_404();

				// tell WP to serve the theme's standard 404 template, this is a filter after all...
				return get_404_template();
			}
		}

		/**
		 * Action hook when the document is served.
		 *
		 * @param integer $post->ID     Post id of the document.
		 * @param string  $file         File name to be served.
		 */
		do_action( 'serve_document', $post->ID, $file );

		/**
		 * Filters file name of document to be served. (Useful if file is encrypted at rest).
		 *
		 * @param string  $file       File name to be served.
		 * @param integer $post->ID   Post id of the document.
		 * @param integer $attach->ID Post id of the attachment.
		 */
		$file = apply_filters( 'document_serve', $file, $post->ID, $attach->ID );

		// We may override this later.
		status_header( 200 );

		// fake the filename.
		$filename  = $post->post_name;
		$filename .= ( '' === $version ) ? '' : __( '-revision-', 'wp-document-revisions' ) . $version;

		// we want the true attachment URL, not the permalink, so temporarily remove our filter.
		remove_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ) );
		$filename .= $this->get_extension( wp_get_attachment_url( $attach->ID ) );
		add_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ), 10, 2 );

		$headers = array();

		// Set content-disposition header. Two options here:
		// "attachment" -- force save-as dialog to pop up when file is downloaded (pre 1.3.1 default)
		// "inline" -- attempt to open in browser (e.g., PDFs), if not possible, prompt with save as (1.3.1+ default).
		$disposition = ( apply_filters( 'document_content_disposition_inline', true ) ) ? 'inline' : 'attachment';

		$headers['Content-Disposition'] = $disposition . '; filename="' . $filename . '"';

		/**
		 * Filters the MIME type for a file before it is processed by WP Document Revisions.
		 *
		 * If filtered to `false`, no `Content-Type` header will be set by the plugin.
		 *
		 * If filtered to a string, that value will be set for the `Content-Type` header.
		 *
		 * @param null|bool|string $mimetype The MIME type for a given file.
		 * @param string           $file     The file being served.
		 */
		$mimetype = apply_filters( 'document_revisions_mimetype', null, $file );

		if ( is_null( $mimetype ) ) {
			// inspired by wp-includes/ms-files.php.
			$mime = wp_check_filetype( $file );
			if ( false === $mime['type'] && function_exists( 'mime_content_type' ) ) {
				$mime['type'] = mime_content_type( $file );
			}

			if ( $mime['type'] ) {
				$mimetype = $mime['type'];
			} else {
				$mimetype = 'image/' . substr( $file, strrpos( $file, '.' ) + 1 );
			}
		}

		// Set the Content-Type header if a mimetype has been detected or provided.
		if ( is_string( $mimetype ) ) {
			$headers['Content-Type'] = $mimetype;
		}

		$filesize                  = filesize( $file );
		$headers['Content-Length'] = $filesize;

		// modified.
		$last_modified            = gmdate( 'D, d M Y H:i:s', filemtime( $file ) );
		$etag                     = '"' . md5( $last_modified ) . '"';
		$headers['Last-Modified'] = $last_modified . ' GMT';
		$headers['ETag']          = $etag;
		$headers['Expires']       = gmdate( 'D, d M Y H:i:s', time() + 100000000 ) . ' GMT';

		/**
		 * Filters the HTTP headers sent when a file is served through WP Document Revisions.
		 *
		 * @param array  $headers The HTTP headers to be sent.
		 * @param string $file    The file being served.
		 */
		$headers = apply_filters( 'document_revisions_serve_file_headers', $headers, $file );

		foreach ( $headers as $header => $value ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@header( $header . ': ' . $value );
		}

		// Support for Conditional GET.
		$client_etag = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? stripslashes( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : false;

		if ( ! isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$_SERVER['HTTP_IF_MODIFIED_SINCE'] = false;
		}

		$client_last_modified = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) );

		// If string is empty, return 0. If not, attempt to parse into a timestamp.
		$client_modified_timestamp = $client_last_modified ? strtotime( $client_last_modified ) : 0;

		// Make a timestamp for our most recent modification...
		$modified_timestamp = strtotime( $last_modified );

		if ( ( $client_last_modified && $client_etag )
			? ( ( $client_modified_timestamp >= $modified_timestamp ) && ( $client_etag === $etag ) )
			: ( ( $client_modified_timestamp >= $modified_timestamp ) || ( $client_etag === $etag ) )
		) {
			status_header( 304 );
			return $template;
		}

		// in case this is a large file, remove PHP time limits.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 0 );

		// In normal operation, corruption can occur if ouput is written by any other process.
		// However, when doing PHPUnit testing, this will occur, so we need to check whether we are in a test harness.
		$under_test = class_exists( 'WP_UnitTestCase' );

		if ( ! $under_test ) {
			// clear any existing output buffer(s) to prevent other plugins from corrupting the file.
			$levels = ob_get_level();
			for ( $i = 0; $i < $levels; $i++ ) {
				ob_end_clean();
			}

			// If any output has been generated (by another plugin), it could cause corruption.
			/**
			 * Filters to output even if output already written.
			 *
			 * Note: Use `add_filter( 'document_output_sent_is_ok', '__return_true' )` to shortcircuit.
			 *
			 * @param bool $debug Set to false.
			 */
			if ( ! apply_filters( 'document_output_sent_is_ok', false ) ) {
				// oops, at least one still there,  deleted and contains data.
				if ( ob_get_level() > 0 && ob_get_length() > 0 ) {
					wp_die( esc_html__( 'Sorry, Output buffer exists with data. Filewriting suppressed.', 'wp-document-revisions' ) );
				}

				// data may already have been flushed so should error.
				if ( headers_sent() ) {
					// normal case is to fail as can cause corrupted output.
					wp_die( esc_html__( 'Sorry, Output has already been written, so your file cannot be downloaded.', 'wp-document-revisions' ) );
				}
			}
		}

		/**
		 * Filter to define file writing buffer size.
		 *
		 * Note: Use `add_filter( 'document_use_gzip', '__return_false' )` to shortcircuit.
		 *       This is always subject to browser negociation.
		 *
		 * @param integer $buffsize  0 (no intermediate flushing).
		 * @param integer $filesize  File size.
		 */
		$buffsize = apply_filters( 'document_buffer_size', 0, $filesize );

		// Make sure that there is a buffer to be written on close.
		/**
		 * Filter to see if gzip should be used.
		 *
		 * Note: Use `add_filter( 'document_use_gzip', '__return_false' )` to shortcircuit.
		 *       This is always subject to browser negociation.
		 *
		 * @param bool    $default   true.
		 * @param string  $mimetype  Mime type to be served.
		 * @param integer $filesize  File size.
		 */
		if ( apply_filters( 'document_use_gzip', true, $mimetype, $filesize ) ) {
			// request compression.
			ob_start( 'ob_gzhandler', $buffsize );
		} else {
			ob_start( null, $buffsize );
		}

		// If we made it this far, just serve the file
		// Note: We use readfile, and not WP_Filesystem for memory/performance reasons.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		readfile( $file );

		/**
		 * Action hook after the document is served.
		 *
		 * Useful to delete temporary file.
		 *
		 * @param string  $file        File name that was served.
		 * @param integer $attach->ID  Post id of the attachment.
		 */
		do_action( 'document_serve_done', $file, $attach->ID );

		// opened buffer, so flush output.
		ob_end_flush();

		// successful call, exit to avoid anything adding to output unless in PHPUnit test mode.
		if ( $under_test ) {
			return $template;
		}

		exit;
	}

	/**
	 * Filter to authenticate document delivery.
	 *
	 * @param bool     $default true unless overridden by prior filter.
	 * @param obj      $post the post object.
	 * @param bool|int $version version of the document being served, if any.
	 * @return unknown
	 */
	public function serve_document_auth( $default, $post, $version ) {
		$user     = wp_get_current_user();
		$ret_null = ( 0 === $user->ID && ! apply_filters( 'document_read_uses_read', true ) );
		// public file, not a revision, no need to go any further
		// note: non-authenticated users only have the "read" cap, so can't auth via read_document.
		if ( ! $version && 'publish' === $post->post_status ) {
			if ( 0 === $user->ID && apply_filters( 'document_read_uses_read', true ) ) {
				// Not logged on. But only default read capability.
				return $default;
			}
		}

		// need to check access.
		// attempting to access a revision.
		if ( $version && ! current_user_can( 'read_document_revisions' ) ) {
			return ( $ret_null ? null : false );
		}

		// specific document cap check.
		if ( ! current_user_can( 'read_document', $post->ID ) ) {
			return ( $ret_null ? null : false );
		}

		return $default;
	}

	/**
	 * Deprecated for consistency of terms.
	 *
	 * @param Int $id the post ID.
	 * @return unknown
	 */
	public function get_latest_version( $id ) {
		_deprecated_function( __FUNCTION__, '1.0.3 of WP Document Revisions', 'get_latest_version' );
		return $this->get_latest_revision( $id );
	}


	/**
	 * Given a post ID, returns the latest revision attachment.
	 *
	 * @param Int $post_id the post id.
	 * @return object latest revision object
	 */
	public function get_latest_revision( $post_id ) {
		if ( is_object( $post_id ) ) {
			$post_id = $post_id->ID;
		}

		$revisions = $this->get_revisions( $post_id );

		if ( ! $revisions ) {
			return false;
		}

		// verify that there's an upload ID in the content field
		// if there's no upload ID for some reason, default to latest attached upload.
		if ( ! is_numeric( $revisions[0]->post_content ) ) {
			$attachments = $this->get_attachments( $post_id );

			if ( empty( $attachments ) ) {
				return false;
			}

			$latest_attachment          = reset( $attachments );
			$revisions[0]->post_content = $latest_attachment->ID;
		}

		return $revisions[0];
	}

	/**
	 * Deprecated for consistency sake.
	 *
	 * @param Int $id the post ID.
	 * @return String the revision URL
	 */
	public function get_latest_version_url( $id ) {
		_deprecated_function( __FUNCTION__, '1.0.3 of WP Document Revisions', 'get_latest_revision_url' );
		return $this->get_latest_revision_url( $id );
	}


	/**
	 * Returns the URL to a post's latest revision.
	 *
	 * @since 0.5
	 * @param int $id post ID.
	 * @return string|bool URL to revision or false if no attachment
	 */
	public function get_latest_revision_url( $id ) {

		$latest = $this->get_latest_revision( $id );

		if ( ! $latest ) {
			return false;
		}

		// temporarily remove our filter to get the true URL, not the permalink.
		remove_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ) );
		$url = wp_get_attachment_url( $latest->post_content );
		add_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ), 10, 2 );

		return $url;
	}


	/**
	 * Calculated path to upload documents.
	 *
	 * @since 0.5
	 * @return string path to document
	 */
	public function document_upload_dir() {
		global $wpdb;

		if ( ! is_null( self::$wpdr_document_dir ) ) {
			return self::$wpdr_document_dir;
		}

		// If no options set, default to normal upload dir.
		$dir = get_site_option( 'document_upload_directory' );
		if ( ! ( $dir ) ) {
			if ( empty( self::$wp_default_dir ) ) {
				// Set the default upload directory cache.
				$this->set_default_dir_cache();
			}
			self::$wpdr_document_dir = self::$wp_default_dir['basedir'];
			return self::$wpdr_document_dir;
		}

		self::$wpdr_document_dir = $dir;
		if ( ! is_multisite() ) {
			return $dir;
		}

		// make site specific on multisite.
		if ( is_multisite() && ! is_network_admin() ) {
			if ( is_main_site() && get_current_network_id() === get_main_network_id() ) {
				$dir = str_replace( '/sites/%site_id%', '', $dir );
			}

			$dir                     = str_replace( '%site_id%', $wpdb->blogid, $dir );
			self::$wpdr_document_dir = $dir;
		}

		return $dir;
	}


	/**
	 * Directory with which to namespace document URLs
	 * Defaults to "documents".
	 *
	 * @return string
	 */
	public function document_slug() {
		$slug = get_site_option( 'document_slug' );

		if ( ! $slug ) {
			$slug = 'documents';
		}

		/**
		 * Filters the document slug.
		 *
		 * @param string $slug The slug (default or parameter).
		 */
		return apply_filters( 'document_slug', $slug );
	}


	/**
	 * Modifies location of uploaded document revisions.
	 *
	 * @since 0.5
	 * @param array $dir defaults passed from WP.
	 * @return array $dir modified directory
	 */
	public function document_upload_dir_filter( $dir ) {
		if ( ! $this->verify_post_type() ) {
			// Ensure cookie variable is set correctly - if needed elsewhere.
			self::$doc_image = true;
			return $dir;
		}

		// Ignore if dealing with thumbnail on document page.
		if ( self::$doc_image ) {
			return $dir;
		}

		global $pagenow;

		// got past cookie check (could be initial display), but may be in thumbnail code
		// Set image directory if dealing with thumbnail on document page.
		$pages = array(
			'admin-ajax.php',
			'async-upload.php',
			'edit.php',
			'media-upload.php',
			'post.php',
			'post-new.php',
		);
		if ( in_array( $pagenow, $pages, true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$trace     = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$functions = array(
				'wp_ajax_get_post_thumbnail_html',
				'_wp_post_thumbnail_html',
				'post_thumbnail_meta_box',
			);
			foreach ( $trace as $traceline ) :
				if ( in_array( $traceline['function'], $functions, true ) ) {
					self::$doc_image = true;
					return $dir;
				}
			endforeach;
		}

		self::$doc_image = false;
		$dir['path']     = $this->document_upload_dir() . $dir['subdir'];
		$dir['url']      = home_url( '/' . $this->document_slug() ) . $dir['subdir'];
		$dir['basedir']  = $this->document_upload_dir();
		$dir['baseurl']  = home_url( '/' . $this->document_slug() );

		return $dir;
	}


	/**
	 * Hides file's true location from users in the Gallery.
	 *
	 * @since 0.5
	 * @param string $link URL to file's tru location.
	 * @param int    $id attachment ID.
	 * @return string empty string
	 */
	public function attachment_link_filter( $link, $id ) {

		if ( ! $this->verify_post_type( $id ) ) {
			return $link;
		}

		return '';
	}


	/**
	 * Rewrites uploaded revisions filename with secure hash to mask true location.
	 *
	 * @since 0.5
	 * @param array $file file data from WP.
	 * @return array $file file with new filename
	 */
	public function filename_rewrite( $file ) {
		// verify this is a document.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['post_id'] ) || ! $this->verify_post_type( sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) ) ) {
			self::$doc_image = true;
			return $file;
		}

		// Ignore if dealing with thumbnail on document page.
		if ( self::$doc_image ) {
			return $file;
		}

		global $pagenow;

		if ( 'async-upload.php' === $pagenow ) {
			// got past cookie, but may be in thumbnail code.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$trace     = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$functions = array(
				'wp_ajax_get_post_thumbnail_html',
				'_wp_post_thumbnail_html',
				'post_thumbnail_meta_box',
			);
			foreach ( $trace as $traceline ) :
				if ( in_array( $traceline['function'], $functions, true ) ) {
					self::$doc_image = true;
					return $file;
				}
			endforeach;
		}

		self::$doc_image = false;
		// hash and replace filename, appending extension.
		$file['name'] = md5( $file['name'] . time() ) . $this->get_extension( $file['name'] );

		/**
		 * Filters the encoded file name for the attached document (on save).
		 *
		 * @param array $file encoded file name.
		 */
		$file = apply_filters( 'document_internal_filename', $file );

		return $file;
	}


	/**
	 * Rewrites a file URL to it's public URL.
	 *
	 * @since 0.5
	 * @param array $file file object from WP.
	 * @return array modified file array
	 */
	public function rewrite_file_url( $file ) {
		// verify that this is a document.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['post_id'] ) || ! $this->verify_post_type( sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) ) ) {
			self::$doc_image = true;
			return $file;
		}

		// Ignore if dealing with thumbnail on document page.
		if ( self::$doc_image ) {
			return $file;
		}

		global $pagenow;

		if ( 'async-upload.php' === $pagenow ) {
			// got past cookie, but may be in thumbnail code.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$trace     = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS );
			$functions = array(
				'wp_ajax_get_post_thumbnail_html',
				'_wp_post_thumbnail_html',
				'post_thumbnail_meta_box',
			);
			foreach ( $trace as $traceline ) :
				if ( in_array( $traceline['function'], $functions, true ) ) {
					self::$doc_image = true;
					return $file;
				}
			endforeach;
		}

		self::$doc_image = false;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$file['url'] = get_permalink( sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) );

		return $file;
	}


	/**
	 * Checks if a given post is a document.
	 *
	 * When called with `false`, will look to other available global data to determine whether
	 * this request is for a document post type. Will *not* look to the global `$post` object.
	 *
	 * Note: We can't use the screen API because A) used on front end, and B) admin_init is too early (enqueue scripts).
	 *
	 * @param object|int|bool $documentish a post object, postID, or false.
	 * @since 0.5
	 * @return bool true if document, false if not
	 */
	public function verify_post_type( $documentish = false ) {
		global $wp_query;

		if ( false === $documentish ) {

			// check for post_type query arg (post new).
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['post_type'] ) && 'document' === $_GET['post_type'] ) {
				return true;
			}

			// Assume that a document feed is a document feed, even without a post object.
			if ( isset( $wp_query->query_vars ) && is_array( $wp_query->query_vars ) &&
			( array_key_exists( 'post_type', $wp_query->query_vars ) ) && 'document' === $wp_query->query_vars['post_type'] && is_feed() ) {
				return true;
			}

			// if post isn't set, try get vars (edit post).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['post'] ) ) {
				$documentish = intval( $_GET['post'] );
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
			}

			// look for post_id via post or get (media upload).
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_REQUEST['post_id'] ) ) {
				$documentish = intval( $_REQUEST['post_id'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}

		if ( false === $documentish ) {
			return false;
		}

		$post = get_post( $documentish );

		if ( ! $post ) {
			return false;
		}

		$post_type = $post->post_type;

		// if post is really an attachment or revision, look to the post's parent.
		if ( ( 'attachment' === $post_type || 'revision' === $post_type ) && 0 !== $post->post_parent ) {
			$post_type = get_post_type( $post->post_parent );
		}

		return 'document' === $post_type;
	}


	/**
	 * Clears cache on post_save_document.
	 *
	 * @param int $post_id the post ID.
	 */
	public function clear_cache( $post_id ) {
		wp_cache_delete( $post_id, 'document_revision_indices' );
		wp_cache_delete( $post_id, 'document_revisions' );
	}

	/**
	 * Callback to handle revision RSS feed.
	 *
	 * @since 0.5
	 */
	public function do_feed_revision_log() {
		// because we're in function scope, pass $post as a global.
		global $post;

		// remove this filter to A) prevent trimming and B) to prevent WP from using the attachID if there's no revision log.
		remove_filter( 'get_the_excerpt', 'wp_trim_excerpt' );
		remove_filter( 'get_the_excerpt', 'twentyeleven_custom_excerpt_more' );

		// include the feed and then die.
		load_template( __DIR__ . '/revision-feed.php' );
	}


	/**
	 * Intercepts RSS feed redirect and forces our custom feed.
	 *
	 * Note: Use `add_filter( 'document_custom_feed', '__return_false' )` to shortcircuit.
	 *
	 * @since 0.5
	 * @param string $default the original feed.
	 * @return string the slug for our feed
	 */
	public function hijack_feed( $default ) {
		global $post;

		if ( ! $this->verify_post_type( ( isset( $post->ID ) ? $post : false ) ) || ! apply_filters( 'document_custom_feed', true ) ) {
			return $default;
		}

		return 'revision_log';
	}


	/**
	 * Verifies that users are auth'd to view a revision feed.
	 *
	 * Note: Use `add_filter( 'document_verify_feed_key', '__return_false' )` to shortcircuit.
	 *
	 * @since 0.5
	 */
	public function revision_feed_auth() {
		/**
		 * Allows the RSS feed to be switched off.
		 *
		 * @param boolean true Allows an RSS feed for documents.
		 */
		if ( ! $this->verify_post_type() || ! apply_filters( 'document_verify_feed_key', true ) ) {
			return;
		}

		if ( is_feed() && ! $this->validate_feed_key() ) {
				wp_die( esc_html__( 'Sorry, this is a private feed.', 'wp-document-revisions' ) );
		}
	}


	/**
	 * Checks feed key before serving revision RSS feed.
	 *
	 * @since 0.5
	 * @return bool
	 */
	public function validate_feed_key() {
		global $wpdb;

		// verify key exists.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['key'] ) ) {
			return false;
		}

		// make alphanumeric.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key = preg_replace( '/[^a-z0-9]/i', '', sanitize_text_field( wp_unslash( $_GET['key'] ) ) );

		// verify length.
		if ( strlen( $key ) !== self::$key_length ) {
			return false;
		}

		// is a user logged on?
		$user = wp_get_current_user();
		if ( $user->exists() ) {
			// yes, validate against their key, i.e. act somewhat like nonce.
			$key_user = get_user_option( self::$meta_key );
			if ( $key === $key_user ) {
				return true;
			} else {
				return false;
			}
		}

		// lookup key and, if found, set user_id (so current_user_can will work).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$feed_user = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value = %s", $wpdb->prefix . self::$meta_key, $key ) );
		if ( '' !== $feed_user ) {
			wp_set_current_user( $feed_user );
			return true;
		}

		return false;
	}


	/**
	 * Ajax Callback to change filelock on lock override.
	 *
	 * @since 0.5
	 * @param bool $send_notice (optional) whether or not to send an e-mail to the former lock owner.
	 */
	public function override_lock( $send_notice = true ) {
		check_ajax_referer( 'wp-document-revisions', 'nonce' );

		$post_id = ( isset( $_POST['post_id'] ) ? sanitize_text_field( wp_unslash( $_POST['post_id'] ) ) : false );

		// verify current user can edit
		// consider a specific permission check here.
		if ( ! $post_id || ! current_user_can( 'edit_document', $post_id ) || ! current_user_can( 'override_document_lock' ) ) {
			wp_die( esc_html__( 'Not authorized', 'wp-document-revisions' ) );
		}

		// verify that there is a lock.
		$current_owner = wp_check_post_lock( $post_id );
		if ( ! ( $current_owner ) ) {
			die( '-1' );
		}

		// update the lock.
		wp_set_post_lock( $post_id );

		// get the current user ID.
		$current_user = wp_get_current_user();

		/**
		 * Filters the option to send a locked document override email.
		 *
		 * @param boolean $send_notice selector whether to send the locked document.
		 */
		if ( apply_filters( 'send_document_override_notice', $send_notice ) ) {
			$this->send_override_notice( $post_id, $current_owner, $current_user->ID );
		}

		do_action( 'document_lock_override', $post_id, $current_user->ID, $current_owner );

		die( '1' );
	}


	/**
	 * E-mails current lock owner to notify them that they lost their file lock.
	 *
	 * @since 0.5
	 * @param int $post_id id of document lock being overridden.
	 * @param int $owner_id id of current document owner.
	 * @param int $current_user_id id of user overriding lock.
	 * @return bool true on sucess, false on fail
	 */
	public function send_override_notice( $post_id, $owner_id, $current_user_id ) {
		// get lock owner's details.
		$lock_owner = get_userdata( $owner_id );

		// get the current user's details.
		$current_user = wp_get_current_user( $current_user_id );

		// get the post.
		$document = get_post( $post_id );

		// build the subject.
		// translators: %1$s is the blog name, %2$s is the overriding user, %3$s is the document title.
		$subject = sprintf( __( '%1$s: %2$s has overridden your lock on %3$s', 'wp-document-revisions' ), get_bloginfo( 'name' ), $current_user->display_name, $document->post_title );
		/**
		 * Filters the locked document email subject text.
		 *
		 * @param string $subject delivered email subject text.
		 */
		$subject = apply_filters( 'lock_override_notice_subject', $subject );

		// build the message.
		// translators: %s is the user's name.
		$message = sprintf( __( 'Dear %s:', 'wp-document-revisions' ), $lock_owner->display_name ) . "\n\n";
		// translators: %1$s is the overriding user, %2$s is the user's email, %3$s is the document title, %4$s is the document URL.
		$message .= sprintf( __( '%1$s (%2$s), has overridden your lock on the document %3$s (%4$s).', 'wp-document-revisions' ), $current_user->display_name, $current_user->user_email, $document->post_title, get_permalink( $document->ID ) ) . "\n\n";
		$message .= __( 'Any changes you have made will be lost.', 'wp-document-revisions' ) . "\n\n";
		// translators: %s is the blog name.
		$message .= sprintf( __( '- The %s Team', 'wp-document-revisions' ), get_bloginfo( 'name' ) );
		/**
		 * Filters the locked document email message text.
		 *
		 * @param string $message delivered email message text.
		 */
		$message = apply_filters( 'lock_override_notice_message', $message );

		/**
		 * Filters the lost lock document email text.
		 *
		 * @param string $message         lost lock email message text.
		 * @param int    $post_id         document id.
		 * @param int    $current_user_id current user id (who has lost lock).
		 * @param object $lock_owner      locking user details.
		 */
		$message = apply_filters( 'document_lock_override_email', $message, $post_id, $current_user_id, $lock_owner );

		// send mail.
		return wp_mail( $lock_owner->user_email, $subject, $message );
	}


	/**
	 * Adds doc-specific caps to all roles so that 3rd party plugins can manage them
	 * Gives admins all caps.

	 * @since 1.0
	 */
	public function add_caps() {
		global $wp_roles;
		if ( ! is_object( $wp_roles ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_roles = new WP_Roles();
		}

		// default role => capability mapping; based off of _post options
		// can be overridden by 3d party plugins.
		$defaults = array(
			'administrator' =>
			array(
				'edit_documents'             => true,
				'edit_others_documents'      => true,
				'edit_private_documents'     => true,
				'edit_published_documents'   => true,
				'read_documents'             => true,
				'read_document_revisions'    => true,
				'read_private_documents'     => true,
				'delete_documents'           => true,
				'delete_others_documents'    => true,
				'delete_private_documents'   => true,
				'delete_published_documents' => true,
				'publish_documents'          => true,
				'override_document_lock'     => true,
			),
			'editor'        =>
			array(
				'edit_documents'             => true,
				'edit_others_documents'      => true,
				'edit_private_documents'     => true,
				'edit_published_documents'   => true,
				'read_documents'             => true,
				'read_document_revisions'    => true,
				'read_private_documents'     => true,
				'delete_documents'           => true,
				'delete_others_documents'    => true,
				'delete_private_documents'   => true,
				'delete_published_documents' => true,
				'publish_documents'          => true,
				'override_document_lock'     => true,
			),
			'author'        =>
			array(
				'edit_documents'             => true,
				'edit_others_documents'      => false,
				'edit_private_documents'     => false,
				'edit_published_documents'   => true,
				'read_documents'             => true,
				'read_document_revisions'    => true,
				'read_private_documents'     => false,
				'delete_documents'           => true,
				'delete_others_documents'    => false,
				'delete_private_documents'   => false,
				'delete_published_documents' => true,
				'publish_documents'          => true,
				'override_document_lock'     => false,
			),
			'contributor'   =>
			array(
				'edit_documents'             => true,
				'edit_others_documents'      => false,
				'edit_private_documents'     => false,
				'edit_published_documents'   => false,
				'read_documents'             => true,
				'read_document_revisions'    => true,
				'read_private_documents'     => false,
				'delete_documents'           => true,
				'delete_others_documents'    => false,
				'delete_private_documents'   => false,
				'delete_published_documents' => false,
				'publish_documents'          => false,
				'override_document_lock'     => false,
			),
			'subscriber'    =>
			array(
				'edit_documents'             => false,
				'edit_others_documents'      => false,
				'edit_private_documents'     => false,
				'edit_published_documents'   => false,
				'read_documents'             => true,
				'read_document_revisions'    => false,
				'read_private_documents'     => false,
				'delete_documents'           => false,
				'delete_others_documents'    => false,
				'delete_private_documents'   => false,
				'delete_published_documents' => false,
				'publish_documents'          => false,
				'override_document_lock'     => false,
			),
		);

		foreach ( $wp_roles->role_names as $role => $label ) {

			// if the role is a standard role, map the default caps, otherwise, map as a subscriber.
			$caps = ( array_key_exists( $role, $defaults ) ) ? $defaults[ $role ] : $defaults['subscriber'];

			/**
			 * Filter the default capabilities.
			 *
			 * @param array  $caps the default set of capabilities for the role.
			 * @param string $role the role being reviewed (all will be reviewed in turn).
			 */
			$caps = apply_filters( 'document_caps', $caps, $role );

			$role_caps = $wp_roles->roles[ $role ]['capabilities'];
			// loop through capacities for role.
			foreach ( $caps as $cap => $grant ) {
				// add only missing capabilities.
				if ( ! array_key_exists( $cap, $role_caps ) ) {
					$wp_roles->add_cap( $role, $cap, $grant );
				}
			}
		}
	}


	/**
	 * Removes Private or Protected from document titles in RSS feeds.
	 *
	 * @since 1.0
	 * @param string $prepend the sprintf formatted string to prepend to the title.
	 * @return string just the string
	 */
	public function no_title_prepend( $prepend ) {
		global $post;

		if ( ! $this->verify_post_type( $post ) ) {
			return $prepend;
		}

		return '%s';
	}


	/**
	 * Adds revision number to document titles.
	 *
	 * @since 1.0
	 * @param string $title   the title.
	 * @param int    $post_id The ID of the post for which the title is being generated.
	 * @return string the title possibly with the revision number
	 */
	public function add_revision_num_to_title( $title, $post_id = null ) {
		// If a post ID is not provided, do not attempt to filter the title.
		if ( is_null( $post_id ) ) {
			return $title;
		}

		$post = get_post( $post_id );

		// verify post type.
		if ( ! $this->verify_post_type( $post ) ) {
			return $title;
		}

		// if this is a document, and not a revision, just filter and return the title.
		if ( 'revision' !== $post->post_type ) {

			if ( is_feed() ) {
				// translators: %s is the document title.
				$title = sprintf( __( '%s - Latest Revision', 'wp-document-revisions' ), $title );
			}

			/**
			 * Filter the document title from the post.
			 *
			 * @param string $title the title retrieved from the post.
			 */
			return apply_filters( 'document_title', $title );
		}

		// get revision num.
		$revision_num = $this->get_revision_number( $post->ID );

		// if for some reason there's no revision num.
		if ( ! $revision_num ) {
			return apply_filters( 'document_title', $title );
		}

		// add title, apply filters, and return.
		// translators: %1$s is the document title, %2$d is the revision ID.
		return apply_filters( 'document_title', sprintf( __( '%1$s - Revision %2$d', 'wp-document-revisions' ), $title, $revision_num ) );
	}


	/**
	 * Prevents Attachment ID from being displayed on front end.
	 *
	 * @since 1.0.3
	 * @param string $content the post content.
	 * @return string either the original content or none
	 */
	public function content_filter( $content ) {
		if ( ! $this->verify_post_type( get_post() ) ) {
			return $content;
		}

		// allow password prompt to display.
		if ( post_password_required() ) {
			return $content;
		}

		return '';
	}


	/**
	 * Provides support for edit flow and disables the default workflow state taxonomy.
	 */
	public function edit_flow_support() {
		// verify edit flow is enabled.
		/**
		 * Filter to switch off integration with Edit_Flow statuses.
		 *
		 * @param boolean true default value to use Edit_Flow processes if installed and active.
		 */
		if ( ! class_exists( 'EF_Custom_Status' ) || ! apply_filters( 'document_revisions_use_edit_flow', true ) ) {
			return;
		}

		global $edit_flow;

		// verify custom_status is enabled.
		if ( ! $edit_flow->custom_status->module_enabled( 'custom_status' ) ) {
			return;
		}

		// prevent errors if options aren't init'd yet.
		if ( ! isset( $edit_flow->custom_status->module->options->post_types['document'] ) ) {
			return;
		}

		// check if enabled.
		if ( 'off' === $edit_flow->custom_status->module->options->post_types['document'] ) {
			return;
		}

		// update the taxonomy key.
		self::$taxonomy_key_val = EF_Custom_Status::taxonomy_key;

		// EF doesn't add Status to Document view so need to add it.
		add_filter( 'manage_document_posts_columns', array( &$this, 'add_post_status_column' ) );
		add_action( 'manage_document_posts_custom_column', array( &$this, 'post_status_column_cb' ), 10, 2 );

		// workflow_state will be used as a query_var, but is not one.
		add_filter( 'query_vars', array( &$this, 'add_qv_workflow_state' ), 10 );

		// we are going to use Edit_Flow processes if installed and active.
		// make sure use_workflow_states returns false.
		add_filter( 'document_use_workflow_states', '__return_false' );
	}

	/**
	 * Adds EditFlow support for post status to the admin table.
	 *
	 * @since 3.3.0
	 * @param array $defaults the column chosen of the all documents list.
	 * @return array the updated column list.
	 */
	public function add_post_status_column( $defaults ) {
		// get checkbox and title.
		$output = array_slice( $defaults, 0, 2 );

		// splice in workflow state.
		$output['status'] = __( 'Status', 'wp-document-revisions' );

		// get the rest of the columns.
		$output = array_merge( $output, array_slice( $defaults, 2 ) );

		return $output;
	}


	/**
	 * Adds EditFlow support for post status to the admin table (when using EF Custom statuses).
	 *
	 * @since 3.3.0
	 * @param string $column_name the column name of the all documents list to be populated.
	 * @param string $post_id     the post id of the all documents list to be populated.
	 */
	public function post_status_column_cb( $column_name, $post_id ) {

		// verify column.
		if ( 'status' !== $column_name || ! $this->verify_post_type( $post_id ) ) {
			return;
		}

		$wp_status = array(
			'publish' => __( 'Published', 'wp-document-revisions' ),
			'draft'   => __( 'Draft', 'wp-document-revisions' ),
			'future'  => __( 'Scheduled', 'wp-document-revisions' ),
			'private' => __( 'Private', 'wp-document-revisions' ),
			'pending' => __( 'Pending Review', 'wp-document-revisions' ),
			'trash'   => __( 'Trash', 'wp-document-revisions' ),
		);

		$post   = get_post( $post_id );
		$status = $post->post_status;
		// Try builtin first.
		if ( array_key_exists( $status, $wp_status ) ) {
			echo esc_html( $wp_status[ $status ] );
			return;
		}

		// Try to see if EF Custom Status.
		$res = get_term_by( 'slug', $status, self::$taxonomy_key_val );
		if ( ! $res ) {
			// not found.
			echo esc_html( $status );
		} else {
			echo esc_html( $res->name );
		}
	}

	/**
	 * Tells WP to recognize workflow_state as a query vars.
	 *
	 * @since 3.3.0
	 * @param array $vars the query vars.
	 * @return array the modified query vars
	 */
	public function add_qv_workflow_state( $vars ) {
		$vars[] = 'workflow_state';
		return $vars;
	}

	/**
	 * Provides support for publish_press_support and disables the default workflow state taxonomy.
	 *
	 * @since 3.2.3
	 */
	public function publishpress_support() {
		// verify publishpress is enabled.
		/**
		 * Filter to switch off integration with PublishPress statuses.
		 *
		 * @param boolean true default value to use PublishPress processes if installed and active.
		 */
		if ( ! class_exists( 'PP_Custom_Status' ) || ! apply_filters( 'document_revisions_use_edit_flow', true ) ) {
			return;
		}

		global $publishpress;

		// prevent errors if options aren't init'd yet.
		if ( ! isset( $publishpress->modules->custom_status->options->post_types['document'] ) ) {
			return;
		}

		// check if enabled for documents.
		if ( 'off' === $publishpress->modules->custom_status->options->post_types['document'] ) {
			return;
		}

		// update the taxonomy key.
		self::$taxonomy_key_val = PP_Custom_Status::taxonomy_key;

		// PP adds the Post Status column so nothing to do.

		// workflow_state will be used as a query_var, but is not one.
		add_filter( 'query_vars', array( &$this, 'add_qv_workflow_state' ), 10 );

		// we are going to use PP processes if installed and active.
		// make sure use_workflow_states returns false.
		add_filter( 'document_use_workflow_states', '__return_false' );
	}

	/**
	 * Toggles workflow states on and off.
	 *
	 * @return bool true if workflow states are on, otherwise false
	 */
	public function use_workflow_states() {
		/**
		 * Filter to switch off use of Edit_Flow statuses and taxonomy.
		 *
		 * @param boolean true default value to use Edit_Flow processes if installed and active. Normally internally used.
		 */
		return apply_filters( 'document_use_workflow_states', true );
	}


	/**
	 * Removes front-end hooks to add workflow state support.
	 */
	public function disable_workflow_states() {
		if ( $this->use_workflow_states() ) {
			return;
		}

		// Have not changed taxonomy key for EF/PP support, so user turned off and neither should exist.
		if ( 'workflow_state' === self::$taxonomy_key_val ) {
			self::$taxonomy_key_val = '';
		}

		remove_action( 'admin_init', array( &$this, 'initialize_workflow_states' ) );
		remove_action( 'init', array( &$this, 'register_ct' ), 2000 );
	}

	/**
	 * Returns array of document objects matching supplied criteria.
	 *
	 * See http://codex.wordpress.org/Class_Reference/WP_Query#Parameters for more information on potential parameters
	 *
	 * @param array   $args (optional) an array of WP_Query arguments.
	 * @param unknown $return_attachments (optional).
	 * @return array an array of post objects
	 */
	public function get_documents( $args = array(), $return_attachments = false ) {
		$args              = (array) $args;
		$args['post_type'] = 'document';
		$documents         = get_posts( $args );
		$output            = array();

		if ( $return_attachments ) {

			// loop through each document and build an array of attachment objects
			// this would be the same output as a query for post_type = attachment
			// but allows querying of document metadata and returns only latest revision.
			foreach ( $documents as $document ) {
				$document_object = $this->get_latest_revision( $document->ID );
				$output[]        = get_post( $document_object->post_content );
			}
		} else {

			// used internal get_revision function so that filter work and revision bug is offset.
			foreach ( $documents as $document ) {
				$output[] = $this->get_latest_revision( $document->ID );
			}
		}

		// remove empty rows, e.g., created by autodraft, etc.
		$output = array_filter( $output );

		return $output;
	}


	/**
	 * Filter's calls for attachment URLs for files attached to documents
	 * Returns the document or revision URL instead of the file's true location
	 * Prevents direct access to files and ensures authentication.
	 *
	 * @since 1.2
	 * @param string $url the original URL.
	 * @param int    $post_id the attachment ID.
	 * @return string the modified URL
	 */
	public function attachment_url_filter( $url, $post_id ) {
		// not an attached attachment.
		if ( ! $this->verify_post_type( $post_id ) ) {
			return $url;
		}

		$document = get_post( $post_id );

		if ( ! $document ) {
			return $url;
		}

		// user can't read revisions anyways, so just give them the URL of the latest revision.
		if ( ! current_user_can( 'read_document_revisions' ) ) {
			return get_permalink( $document->post_parent );
		}

		// we know there's a revision out there that has the document as its parent and the attachment ID as its body, find it.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$revision_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_parent = %d AND post_content = %d LIMIT 1", $document->post_parent, $post_id ) );

		// couldn't find it, just return the true URL.
		if ( ! $revision_id ) {
			return $url;
		}

		// run through standard permalink filters and return.
		return get_permalink( $revision_id );
	}


	/**
	 * Prevents internal calls to files from breaking when apache is running on windows systems (Xampp, etc.)
	 * Code inspired by includes/class.wp.filesystem.php
	 * See generally http://wordpress.org/support/topic/plugin-wp-document-revisions-404-error-and-permalinks-are-set-correctly.
	 *
	 * @since 1.2.1
	 * @param string $url the permalink.
	 * @return string the modified permalink
	 */
	public function wamp_document_path_filter( $url ) {
		$url = preg_replace( '|^([a-z]{1}):|i', '', $url ); // Strip out windows drive letter if it's there.
		return str_replace( '\\', '/', $url ); // Windows path sanitization.
	}


	/**
	 * Term Count Callback that applies custom filter
	 * Allows Workflow State counts to include non-published posts.
	 *
	 * @since 1.2.1
	 * @param Array  $terms the terms to filter.
	 * @param Object $taxonomy the taxonomy object.
	 */
	public function term_count_cb( $terms, $taxonomy ) {
		add_filter( 'query', array( &$this, 'term_count_query_filter' ) );
		_update_post_term_count( $terms, $taxonomy );
		remove_filter( 'query', array( &$this, 'term_count_query_filter' ) );
	}


	/**
	 * Alters term count query to include all non-trashed posts.
	 * See generally, #17548
	 *
	 * @since 1.2.1
	 * @param Object $query the query object.
	 * @return String the modified query
	 */
	public function term_count_query_filter( $query ) {
		return str_replace( "= 'publish'", "!= 'trash'", $query );
	}


	/**
	 * Extends the modified term_count_cb to all custom taxonomies associated with documents
	 * Unless taxonomy already has a custom callback.
	 *
	 * @since 1.2.1
	 */
	public function register_term_count_cb() {
		// This will return only taxonomies for documents only, e.g. ignore those for documents AND another.
		$taxs = get_taxonomies(
			array(
				'object_type'           => array( 'document' ),
				'update_count_callback' => '',
			),
			'names'
		);

		/**
		 * Filter to select which taxonomies with default term count to be modified to count all non-trashed posts.
		 *
		 * @param array $taxs document taxonomies .
		 */
		$taxs = apply_filters( 'document_taxonomy_term_count', $taxs );

		if ( ! empty( $taxs ) ) {
			global $wp_taxonomies;

			foreach ( $taxs as $tax ) {
					$wp_taxonomies[ $tax ]->update_count_callback = array( &$this, 'term_count_cb' );
			}
		}
	}

	/**
	 * Removes auto-appended trailing slash from document requests prior to serving.
	 *
	 * WordPress SEO rules properly dictate that all post requests should be 301 redirected with a trailing slash
	 * Because documents end with a phaux file extension, we don't want that unless there is a named extension
	 * Removes trailing slash from documents, while allowing all other SEO goodies to continue working.
	 *
	 * @param String $redirect the redirect URL.
	 * @param Object $request the request object.
	 * @return String the redirect URL without the trailing slash
	 */
	public function redirect_canonical_filter( $redirect, $request ) {
		if ( ! $this->verify_post_type() ) {
			return $redirect;
		}

		// if the URL already has an extension, then no need to remove.
		$path = wp_parse_url( $redirect, PHP_URL_PATH );

		if ( preg_match( '#(^.+)\.[A-Za-z0-9]{1,7}/?$#', $path ) ) {
			return $redirect;
		}

		return untrailingslashit( $redirect );
	}

	/**
	 * Provides a workaround for the attachment url filter breaking wp_get_attachment_image_src
	 * Removes the wp_get_attachment_url filter and runs image_downsize normally
	 * Will also check to make sure the returned image doesn't leak the file's true path.
	 *
	 * @since 1.2.2
	 * @param bool|array $downsize Whether to short-circuit the image downsize.
	 * @param int        $id       the ID of the attachment.
	 * @param string     $size     the size requested.
	 * @return bool|array false or the image array to be returned from image_downsize()
	 */
	public function image_downsize( $downsize, $id, $size ) {
		// previous filter code wants to short-cut the process.
		if ( is_array( $downsize ) ) {
			return $downsize;
		}

		// not a document.
		if ( ! $this->verify_post_type( $id ) ) {
			return $downsize;
		}

		remove_filter( 'image_downsize', array( &$this, 'image_downsize' ) );
		remove_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ) );

		$direct = wp_get_attachment_url( $id );
		$image  = image_downsize( $id, $size );

		add_filter( 'image_downsize', array( &$this, 'image_downsize' ), 10, 3 );
		add_filter( 'wp_get_attachment_url', array( &$this, 'attachment_url_filter' ), 10, 2 );

		// if WordPress is going to return the direct url to the real file,
		// serve the document permalink (or revision permalink) instead.
		if ( $image && $image[0] === $direct ) {
			$image[0] = wp_get_attachment_url( $id );
		}

		return $image;
	}

	/**
	 * Return an empty excerpt for documents on front end views to avoid leaking
	 * revision notes (except if the user could see them by editting the post).
	 *
	 * @since 3.3.0
	 * @param string  $excerpt The original excerpt text associated with a post.
	 * @param WP_Post $post    The post object.
	 *
	 * @return string
	 */
	public function empty_excerpt_return( $excerpt, $post ) {
		if ( '' === $excerpt || ! $this->verify_post_type( $post ) ) {
			return $excerpt;
		}

		// suppress only if user could not edit the (parent) post and see it.
		$post_test = ( 0 === $post->post_parent ? $post->ID : $post->post_parent );
		if ( ! current_user_can( 'edit_document', $post_test ) ) {
			return '';
		}

		return $excerpt;
	}


	/**
	 * Filters the WHERE clause in the SQL for an adjacent post query.
	 *
	 * Add 1=0 test to WHERE clause for documents for a single page.
	 *
	 * @since 3.3.0
	 *
	 * @param string  $where          The `WHERE` clause in the SQL.
	 * @param bool    $in_same_term   Whether post should be in a same taxonomy term.
	 * @param array   $excluded_terms Array of excluded term IDs.
	 * @param string  $taxonomy       Taxonomy. Used to identify the term used when `$in_same_term` is true.
	 * @param WP_Post $post           WP_Post object.
	 *
	 * @return string
	 */
	public function suppress_adjacent_doc( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		if ( ! $this->verify_post_type( $post ) ) {
			return $where;
		}

		// Leakage arises on queries on a single page.
		if ( ! is_singular() ) {
			return $where;
		}

		return $where . ' AND 1 = 0 ';
	}

	/**
	 * Try to retrieve only correct documents.
	 *
	 * Queries by post_status do not do proper permissions check.
	 * See https://developer.wordpress.org/reference/classes/wp_query/
	 *
	 * @since 3.3.0
	 *
	 * @param WP_Query $query  Query object.
	 */
	public function retrieve_documents( $query ) {
		$query_fields = (array) $query->query;
		if ( isset( $query_fields['post_type'] ) && 'document' === $query_fields['post_type'] ) {
			// not for administrator.
			$user = wp_get_current_user();
			if ( in_array( 'administrator', $user->roles, true ) ) {
				return;
			}

			// dropped through initial tests.
			if ( isset( $query_fields['post_status'] ) && ! empty( $query_fields['post_status'] ) ) {
				if ( ! isset( $query_fields['perm'] ) ) {
					// create/modify taxonomy query.
					$query->set( 'perm', 'readable' );
				}
			}
		}
	}

	/**
	 * Maps caps from e.g., `read` to `read_document`
	 *
	 * @param array   $caps    Array of the user's required capabilities.
	 * @param string  $cap     Capability name.
	 * @param integer $user_id The user ID.
	 * @param array   $args    Adds the context to the cap. Typically the object ID.
	 * @return unknown
	 */
	public function map_meta_cap( $caps, $cap, $user_id, $args ) {
		// check that cap wanted is read_post.
		if ( 'read_post' !== $cap ) {
			return $caps;
		}

		// attempt to grab the post_ID.
		// note: will default to global $post if none passed.
		$post_ID = ( ! empty( $args ) ) ? $args[0] : null;

		// kick if not related to a document.
		if ( ! $this->verify_post_type( $post_ID ) ) {
			return $caps;
		}

		return array( 'read_documents' );
	}

	/**
	 * Dynamically filter a user's capabilities.
	 *
	 * @param bool[]   $allcaps Array of key/value pairs where keys represent a capability name and boolean values
	 *                          represent whether the user has that capability.
	 * @param string[] $caps    Required primitive capabilities for the requested capability.
	 * @param array    $args {
	 *     Arguments that accompany the requested capability check.
	 *
	 *     @type string    $0 Requested capability.
	 *     @type int       $1 Concerned user ID.
	 *     @type mixed  ...$2 Optional second and further parameters, typically object ID.
	 * }
	 * @param WP_User  $user    The user object.
	 */
	public function user_has_cap( $allcaps, $caps, $args, $user ) {
		// Is user an administrator. If so, bail.
		if ( in_array( 'administrator', $user->roles, true ) ) {
			return $allcaps;
		}

		// must have a base object that is a document.
		if ( isset( $args[2] ) && $this->verify_post_type( $args[2] ) ) {
			// remove the read capability for this test.
			unset( $allcaps['read'] );
		}

		return $allcaps;
	}

	/**
	 * Review WP_Query SQL results.
	 *
	 * Only invoked when user should NOT access documents via 'read' but does not have 'read_document'. Remove any documents.
	 *
	 * @param WP_Post[] $results      Array of post objects.
	 * @param WP_Query  $query_object Query object.
	 * @return WP_Post[] Array of post objects.
	 */
	public function posts_results( $results, $query_object ) {
		$match = false;
		if ( is_array( $results ) ) {
			foreach ( $results as $key => $result ) {
				// confirm a document.
				if ( $this->verify_post_type( $result ) ) {
					// user has no access, remove from result.
					unset( $results[ $key ] );
					$match = true;
				}
			}
		}
		// re-evaluate count.
		if ( $match ) {
			// reindex array.
			$results = array_values( $results );

			if ( is_array( $results ) ) {
				$query_object->found_posts = count( $results );
				$query_object->is_404      = (bool) ( 0 === $query_object->found_posts );
			} else {
				if ( null === $results ) {
					$query_object->found_posts = 0;
					$query_object->is_404      = true;
				} else {
					$query_object->found_posts = 1;
				}
			}
		}

		return $results;
	}

	/**
	 * Remove nocache headers from document downloads on IE < 8
	 * Hooked into parse_request so we can fire after request is parsed, but before headers are sent
	 * See http://support.microsoft.com/kb/323308.
	 *
	 * @param Object $wp The global WP object.
	 * @return the WP global object
	 */
	public function ie_cache_fix( $wp ) {
		// SSL check.
		if ( ! is_ssl() ) {
			return $wp;
		}

		// IE check.
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || stripos( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 'MSIE' ) === false ) {
			return $wp;
		}

		// verify that they are requesting a document.
		if ( ! isset( $wp->query_vars['post_type'] ) || 'document' !== $wp->query_vars['post_type'] ) {
			return $wp;
		}

		add_filter( 'nocache_headers', '__return_empty_array' );

		return $wp;
	}

}
