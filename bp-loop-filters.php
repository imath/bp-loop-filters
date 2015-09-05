<?php
/**
 * Plugin Name:       BP Loop Filters
 * Plugin URI:        http://codex.buddypress.org/add-custom-filters-to-loops-and-enjoy-them-within-your-plugin
 * Description:       Plugin example to illustrate loop filters
 * Version:           1.0
 * Author:            imath
 * Author URI:        http://imathi.eu
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

class BP_Loop_Filters {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->setup_actions();
		$this->setup_filters();
	}

	/**
	 * Actions
	 *
	 * @uses bp_is_active()
	 * @uses is_multisite()
	 */
	private function setup_actions() {
		/**
		 * Adds the random order to the select boxes of the Members, Groups and Blogs directory pages
		 */
		// Members component is core, so it will be available
		add_action( 'bp_members_directory_order_options', array( $this, 'random_order' ) );

		// You need to check Groups component is available
		if ( bp_is_active( 'groups' ) ) {
			add_action( 'bp_groups_directory_order_options',  array( $this, 'random_order' ) );
		}

		// You need to check WordPress config and that Blogs Component is available
		if ( is_multisite() && bp_is_active( 'blogs' ) ) {
			add_action( 'bp_blogs_directory_order_options',   array( $this, 'random_order' ) );
		}

		/**
		 * Registers the Activity actions so that they are available in the Activity Administration Screen
		 */
		// You need to check Activity component is available
		if ( bp_is_active( 'activity' ) ) {

			add_action( 'bp_register_activity_actions', array( $this, 'register_activity_actions' ) );

			// Adds a new filter into the select boxes of the Activity directory page,
			// of group and member single items activity screens
			add_action( 'bp_activity_filter_options',        array( $this, 'display_activity_actions' ) );
			add_action( 'bp_member_activity_filter_options', array( $this, 'display_activity_actions' ) );

			// You need to check Groups component is available
			if ( bp_is_active( 'groups' ) ) {
				add_action( 'bp_group_activity_filter_options', array( $this, 'display_activity_actions' ) );
			}

	        // You're going to output the favorite count after action buttons
			add_action( 'bp_activity_entry_meta', array( $this, 'display_favorite_count' ) );
		}

	}

	/**
	 * Displays a new option in the Members/Groups & Blogs directories
	 *
	 * @return string html output
	 */
	public function random_order() {
		?>
		<option value="random"><?php _e( 'Random', 'buddypress' ); ?></option>
		<?php
	}

	/**
	 * Registering the Activity actions for your component
	 *
	 * The registered actions will also be available in Administration
	 * screens
	 *
	 * @uses bp_activity_set_action()
	 * @uses is_admin()
	 */
	public function register_activity_actions() {
		/* arguments are :
		- 'component_id', 'component_action_type' to use in {$wpdb->prefix}bp_activity database
		- and 'caption' to display in the select boxes */
		bp_activity_set_action( 'bp_plugin', 'bpplugin_action', __( 'BP Plugin Action' ) );

		/* Activity Administration screen does not use bp_ajax_querystring
		Moreover This action type is reordering instead of filtering so you will only
		use it on front end */
		if ( ! is_admin() ) {
			bp_activity_set_action( 'bp_plugin', 'activity_mostfavs', __( 'Most Favorited' ) );
		}
	}

	/**
	 * Building an array to loop in from our display function
	 *
	 * Using bp_activity_get_types() will list all registered activity actions
	 * but you need to get the ones for your plugin, and this particular function
	 * directly returns an array of key => value. As you need to filter activity
	 * with your component id, the global buddypress()->activity->actions will be
	 * more helpful.
	 *
	 * @uses buddypress()
	 * @return array the list of your plugin actions.
	 */
	private function list_actions() {

		$bp_activity_actions = buddypress()->activity->actions;

		$bp_plugin_actions = array();

		if ( !empty( $bp_activity_actions->bp_plugin ) ) {
			$bp_plugin_actions = array_values( (array) $bp_activity_actions->bp_plugin );
		}

		return $bp_plugin_actions;
	}

	/**
	 * Displays new actions into the Activity select boxes
	 * to filter activities
	 * - Activity Directory
	 * - Single Group and Member activity screens
	 *
	 * @return string html output
	 */
	public function display_activity_actions() {
		$bp_plugin_actions = $this->list_actions();

		if ( empty( $bp_plugin_actions ) ) {
			return;
		}

		foreach ( $bp_plugin_actions as $type ):?>
			<option value="<?php echo esc_attr( $type['key'] );?>"><?php echo esc_attr( $type['value'] ); ?></option>
		<?php endforeach;
	}

	/**
	 * Displays a mention to inform about the number of time the activity
	 * was favorited.
	 *
	 * @global BP_Activity_Template $activities_template
	 * @return string html output
	 */
	public function display_favorite_count() {
		global $activities_template;

		// BuddyPress < 2.0 or filtering bp_use_legacy_activity_query
		if ( ! empty( $activities_template->activity->favorite_count ) ) {
			$fav_count = $activities_template->activity->favorite_count;
		} else {
			// This meta should already have been cached by BuddyPress :)
			$fav_count = (int) bp_activity_get_meta( bp_get_activity_id(), 'favorite_count' );
		}

		if ( ! empty( $fav_count ) ): ?>
			<a name="favorite-<?php bp_activity_id();?>" class="button bp-primary-action">Favorited <span><?php printf( _n( 'once', '%s times', $fav_count ), $fav_count );?></span></a>
		<?php endif;
	}

	/**
	 * Filters
	 */
	private function setup_filters() {
		add_filter( 'bp_ajax_querystring',              array( $this, 'activity_querystring_filter' ), 12, 2 );
		add_filter( 'bp_activity_get_user_join_filter', array( $this, 'order_by_most_favorited' ),     10, 6 );
		add_filter( 'bp_activity_paged_activities_sql', array( $this, 'order_by_most_favorited'),      10, 2 );

		// Maybe Fool Heartbeat Activities!
		add_filter( 'bp_before_activity_latest_args_parse_args', array( $this, 'maybe_fool_heartbeat' ), 10, 1 );
	}

	/**
	 * Builds an Activity Meta Query to retrieve the favorited activities
	 *
	 * @param  string $query_string the front end arguments for the Activity loop
	 * @param  string $object       the Component object
	 * @uses   wp_parse_args()
	 * @uses   bp_displayed_user_id()
	 * @return array()|string $query_string new arguments or same if not needed
	 */
	public function activity_querystring_filter( $query_string = '', $object = '' ) {
		if ( $object != 'activity' ) {
			return $query_string;
		}

		// You can easily manipulate the query string
		// by transforming it into an array and merging
		// arguments with these default ones
		$args = wp_parse_args( $query_string, array(
			'action'  => false,
			'type'    => false,
			'user_id' => false,
			'page'    => 1
		) );

		/* most favorited */
		if ( $args['action'] == 'activity_mostfavs' ) {
			unset( $args['action'], $args['type'] );

			// on user's profile, shows the most favorited activities for displayed user
			if( bp_is_user() ) {
				$args['user_id'] = bp_displayed_user_id();
			}

			// An activity meta query :)
			$args['meta_query'] = array(
				array(
					/* this is the meta_key you want to filter on */
					'key'     => 'favorite_count',
					/* You need to get all values that are >= to 1 */
					'value'   => 1,
					'type'    => 'numeric',
					'compare' => '>='
				),
			);

			$query_string = empty( $args ) ? $query_string : $args;
        }

        return apply_filters( 'bp_plugin_activity_querystring_filter', $query_string, $object );
	}

	/**
	 * Ninja Warrior trick to reorder the Activity Loop
	 * regarding the activities favorite count
	 *
	 * @param  string $sql        the sql query that will be run
	 * @param  string $select_sql the select part of the query
	 * @param  string $from_sql   the from part of the query
	 * @param  string $where_sql  the where part of the query
	 * @param  string $sort       the sort order (leaving it to DESC will be helpful!)
	 * @param  string $pag_sql    the offset part of the query
	 * @return string $sql        the current or edited query
	 */
	public function order_by_most_favorited( $sql = '', $select_sql = '', $from_sql = '', $where_sql = '', $sort = '', $pag_sql = '' ) {
		if ( apply_filters( 'bp_use_legacy_activity_query', false ) ) {
			preg_match( '/\'favorite_count\' AND CAST\((.*) AS/', $where_sql, $match );


			if ( ! empty( $match[1] ) ) {
				$new_order_by = 'ORDER BY '. $match[1] .' + 0';
				$new_select_sql = $select_sql . ', '. $match[1] .' AS favorite_count';

				$sql = str_replace(
					array( $select_sql, 'ORDER BY a.date_recorded' ),
					array( $new_select_sql, $new_order_by ),
					$sql
				);
			}

		// $select_sql is carrying the requested argument since BuddyPress 2.0.0
		} else {
			$r = $select_sql;

			if ( empty( $r['meta_query'] ) || ! is_array( $r['meta_query'] ) ) {
				return $sql;
			} else {
				$meta_query_keys = wp_list_pluck( $r['meta_query'], 'key' );

				if ( ! in_array( 'favorite_count', $meta_query_keys ) ) {
					return $sql;
				}

				preg_match( '/\'favorite_count\' AND CAST\((.*) AS/', $sql, $match );

				if ( ! empty( $match[1] ) ) {
					$sql = str_replace( 'ORDER BY a.date_recorded', 'ORDER BY '. $match[1] .' + 0', $sql );
				}
			}
		}

		return $sql;
	}

	/**
	 * Cannot pass the favorite data for now so just fool heartbeat activities
	 */
	public function maybe_fool_heartbeat( $r = array() ) {
		if ( empty( $r['meta_query'] ) ) {
			return $r;
		}

		$meta_query_keys = wp_list_pluck( $r['meta_query'], 'key' );

		if ( ! in_array( 'favorite_count', $meta_query_keys ) ) {
			return $r;
		} else {
			$r['since'] = '3000-12-31 00:00:00';
		}

		return $r;
	}
}

// 1, 2, 3 go !
function bp_loop_filters() {
	return new BP_Loop_Filters();
}

add_action( 'bp_include', 'bp_loop_filters' );

