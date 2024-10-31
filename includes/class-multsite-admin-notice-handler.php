<?php
if( !class_exists( 'EO_Pro_Admin_Notice_Handler' ) ):

/**
 * Helper class for adding notices to admin screen
 * <code>
 * $notice_handler = Multsite_Admin_Notice_Handler::get_instance()
 * $notice_handler->add_notice( 'foobar', 'screen_id', 'Notice...' ); 
 * </code>
 * @ignore
 */
class Multsite_Admin_Notice_Handler{

	static $prefix = 'msan-notices';

	static $notices = array();

	static $instance;

	/**
	 * Singleton model
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Construct the controller and listen for form submission
	 */
	public function __construct() {

		//Singletons!
		if ( !is_null( self::$instance ) )
			trigger_error( "Tried to construct a second instance of class \"$class\"", E_USER_WARNING );

		if( did_action( 'plugins_loaded') ){
			self::load();
		}else{
			add_action ( 'plugins_loaded', array( __CLASS__, 'load' ) );
		}
	}

	/**
	 * Hooks the dismiss listener (ajax and no-js) and maybe shows notices on admin_notices
	 */
	static function load(){
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_init',array( __CLASS__, 'dismiss_handler' ) );
		add_action( 'wp_ajax_'.self::$prefix.'-dismiss-notice', array( __CLASS__, 'dismiss_handler' ) );
	}

	function add_notice( $id, $screen_id, $message, $type = 'alert' ){
		self::$notices[$id] = array(
				'screen_id' => $screen_id,
				'message'   => $message,
				'type'      => $type
		);
	}

	function remove_notice( $id ){
		if( isset( self::$notices[$id] ) ){
			unset( self::$notices[$id] );
		}
	}

	/**
	 * Print appropriate notices.
	 * Hooks EO_Admin_Notice_Handle::print_footer_scripts to admin_print_footer_scripts to
	 * print js to handle AJAX dismiss.
	 */
	static function admin_notice(){

		$screen_id = get_current_screen()->id;

		//Notices of the form ID=> array('screen_id'=>screen ID, 'message' => Message,'type'=>error|alert)
		if( !self::$notices )
			return;

		$seen_notices = get_option(self::$prefix.'_admin_notices',array());

		foreach( self::$notices as $id => $notice ){
			$id = sanitize_key($id);

			//Notices cannot have been dismissed and must have a message
			if( in_array($id, $seen_notices)  || empty($notice['message'])  )
				continue;

			$notice_screen_id = (array) $notice['screen_id'];
			$notice_screen_id = array_filter($notice_screen_id);

			//Notices must for this screen. If empty, its for all screens.
			if( !empty($notice_screen_id) && !in_array($screen_id, $notice_screen_id) )
				continue;

			$class = $notice['type'] == 'error' ? 'error' : 'updated';

			printf(
				"<div class='%s-notice {$class}' id='%s'>%s<p> <a class='%s' href='%s' title='%s'><strong>%s</strong></a></p></div>",
				esc_attr( self::$prefix ),
				esc_attr( self::$prefix.'-notice-'.$id ),
				wpautop( $notice['message'] ),
				esc_attr( self::$prefix.'-dismiss' ),
				esc_url( add_query_arg(array(
					'action' => self::$prefix.'-dismiss-notice',
					'notice' => $id,
					'_wpnonce' => wp_create_nonce(self::$prefix.'-dismiss-'.$id),
				)) ),
				__( 'Dismiss this notice', 'multisite-admin-notices' ),
				__( 'Dismiss', 'multisite-admin-notices' )
			);
			add_action( 'admin_print_footer_scripts', array( __CLASS__, 'print_footer_scripts' ), 11 );
		}
	}

	/**
	 * Handles AJAX and no-js requests to dismiss a notice
	 */
	static function dismiss_handler(){

		$notice = isset($_REQUEST['notice']) ? $_REQUEST['notice'] : false;
		if( empty($notice) )
			return;

		if ( defined('DOING_AJAX') && DOING_AJAX ){
			//Ajax dismiss handler
			if( empty($_REQUEST['notice'])  || empty($_REQUEST['_wpnonce'])  || $_REQUEST['action'] !== self::$prefix.'-dismiss-notice' )
				return;

			if( !wp_verify_nonce( $_REQUEST['_wpnonce'],self::$prefix."-ajax-dismiss") )
				return;

		}else{
			//Fallback dismiss handler
			if( empty($_REQUEST['action']) || empty($_REQUEST['notice'])  || empty($_REQUEST['_wpnonce'])  || $_REQUEST['action'] !== self::$prefix.'-dismiss-notice' )
				return;

			if( !wp_verify_nonce( $_REQUEST['_wpnonce'],self::$prefix.'-dismiss-'.$notice ) )
				return;
		}

		self::dismiss_notice($notice);

		if ( defined('DOING_AJAX') && DOING_AJAX )
			wp_die(1);
	}

	/**
	 * Dismiss a given a notice
	 *@param string $notice The notice (ID) to dismiss
	 */
	static function dismiss_notice($notice){
		$seen_notices = get_option(self::$prefix.'_admin_notices',array());
		$seen_notices[] = $notice;
		$seen_notices = array_unique($seen_notices);
		update_option(self::$prefix.'_admin_notices',$seen_notices);
	}

	/**
	 * Prints javascript in footer to handle AJAX dismiss.
	 */
	static function print_footer_scripts() {
		?>
		<style>
		.msan-notices-dismiss:before {
			background: none;
			color: #BBB;
			content: '\f153';
			font: normal 16px/1 'dashicons';
			speak: none;
			height: 20px;
			margin: 2px 0;
			text-align: center;
			width: 20px;
			-webkit-font-smoothing: antialiased !important;
			vertical-align: text-bottom;
			margin-right: 2px;
		}
		.msan-notices-dismiss:hover:before {
			color: #C00;
		}
		</style>
		<script type="text/javascript">
			jQuery(document).ready(function ($){
				var dismissClass = '<?php echo esc_js(self::$prefix."-dismiss");?>';
        			var ajaxaction = '<?php echo esc_js(self::$prefix."-dismiss-notice"); ?>';
				var _wpnonce = '<?php echo wp_create_nonce(self::$prefix."-ajax-dismiss")?>';
				var noticeClass = '<?php echo esc_js(self::$prefix."-notice");?>';

				jQuery('.'+dismissClass).click(function(e){
					e.preventDefault();
					var noticeID= $(this).parents('.'+noticeClass).attr('id').substring(noticeClass.length+1);

					$.post(ajaxurl, {
						action: ajaxaction,
						notice: noticeID,
						_wpnonce: _wpnonce
					}, function (response) {
						if ('1' === response) {
							$('#'+noticeClass+'-'+noticeID).fadeOut('slow');
			                    } else {
							$('#'+noticeClass+'-'+noticeID).removeClass('updated').addClass('error');
                    		 	   }
                			});
				});
        		});
		</script><?php
	}
}//End EO_Admin_Notice_Handler
endif;