<?php
/*
Plugin Name: User Login Plus
Plugin URI: http://wordpress.org/plugins/user-login-log/
Description: Show a users last login date by creating a sortable column in your WordPress users list.
Author: Liu Yong
Version: 1.1
Author URI: http://tipton.cn/
*/

class User_Login_Plus {

    public function init() {
        add_action( 'wp_login', array( $this, 'user_login_action' ), 10, 2 );

        add_filter( 'manage_users_columns', array( $this, 'add_user_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'fill_user_columns' ), 10, 3 );

	    add_action( 'wp_login_failed', array( $this, 'login_attempt_failed' ) );
	    add_action( 'login_redirect', array( $this, 'login_attempt' ), 10, 3 );
	    add_filter( 'login_message', array( $this, 'login_attempt_message' ) );

	    add_action( 'show_user_profile', array( $this, 'extra_profile_fields' ) );
	    add_action( 'edit_user_profile', array( $this, 'extra_profile_fields' ) );
	    add_action( 'user_new_form', array( $this, 'extra_profile_fields' ) );

	    add_action( 'personal_options_update', array( $this, 'save_extra_profile_fields' ) );
	    add_action( 'edit_user_profile_update', array( $this, 'save_extra_profile_fields' ) );
	    add_action( 'user_register', array( $this, 'save_extra_profile_fields' ) );

	    register_activation_hook( __FILE__, array( $this, 'login_attempts_activation' ) );


    }


	/**
	 * 初始化数据库表：users_login_logs
	 */

	public function login_attempts_activation(){

		global $wpdb;

		$table_name = $wpdb->prefix . 'users_login_logs';

		$wpdb_collate = $wpdb->collate;

		$sql =
			"CREATE TABLE {$table_name} (
            id int(11) unsigned NOT NULL auto_increment,
            username varchar(255) NULL, 
            ip_address varchar(255) NULL, 
            time_slot varchar(255) NULL,
            PRIMARY KEY  (id) 
          ) 
          COLLATE {$wpdb_collate}";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta( $sql );


	}

    /**
     * 记录登录时间和登录次数
     */
    public function user_login_action( $user_login, $user ) {
        update_user_meta($user->ID, 'login_ip', $this->get_user_ip());
        update_user_meta($user->ID, 'last_login', current_time('mysql'));

        $count = get_user_meta( $user->ID, 'login_count', true );
        if ( ! empty( $count ) ) {
            $login_count = get_user_meta( $user->ID, 'login_count', true );
            update_user_meta( $user->ID, 'login_count', ( (int) $login_count + 1 ) );
        }
        else {
            update_user_meta( $user->ID, 'login_count', 1 );
        }
    }

	/**
	 * @param $columns
	 *
	 * @return mixed
     *
     * 添加登录信息至用户列表
	 */
    public function add_user_columns( $columns ) {
        unset($columns['posts']);
        unset($columns['name']);
        $columns['display_name'] = '姓名';
        $columns['login_stat'] = __( '登录次数' );
        $columns['lastlogin'] = __( '最后登录时间' );
        $columns['login_ip'] = __( '最后登录IP' );

        return $columns;
    }


    public function fill_user_columns( $value, $column_name, $user_id ) {
        $user = get_userdata( $user_id );

        if ( 'display_name' == $column_name ){
            $display_name = $user->display_name;
            return $display_name;

        }

        if ( 'login_stat' == $column_name ) {
            if ( get_user_meta( $user_id, 'login_count', true ) !== '' ) {

                $login_count = get_user_meta( $user_id, 'login_count', true );

                return $login_count;
            }
            else {
                return __( '0' );
            }
        }

        if ( 'lastlogin' == $column_name ){

            return $this->get_user_last_login($user_id,false);

        }
        if ( 'login_ip' == $column_name ){
            $login_ip = get_user_meta( $user_id, 'login_ip', true );

            return $login_ip;

        }

        return $value;
    }


	/*
	 * 最后登录时间
	 */
    public function get_user_last_login($user_id,$echo = true){
        $date_format = get_option('date_format') . ' ' . get_option('time_format');
        $last_login = get_user_meta($user_id, 'last_login', true);
        $login_time = '-';
        if(!empty($last_login)){
            if(is_array($last_login)){
                $login_time = mysql2date($date_format, array_pop($last_login), false);
            }
            else{
                $login_time = mysql2date($date_format, $last_login, false);
            }
        }
        if($echo){
            echo $login_time;
        }
        else{
            return $login_time;
        }
    }


    /*
     * 获取用户 IP
     */
    public function get_user_ip(){
		if(!empty($_SERVER['HTTP_CLIENT_IP'])){
			//ip from share internet
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		}elseif(!empty($_SERVER['HTTP_X_FORWARDED_FOR'])){
			//ip pass from proxy
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}else{
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		return $ip;
	}

	/*
	 * 获取当前 IP 登录次数
	 */
	private function get_login_attempts(){

		$ip = $this::get_user_ip();

		global $wpdb;

		$table_name = $wpdb->prefix . 'users_login_logs';

		$login_timeframe = apply_filters( 'wll_login_limit_timeframe', 10 );

		$current_time = strtotime( current_time( 'mysql') . ' - '.$login_timeframe.' MINUTE' );

		$sql = "SELECT count(*) as attempts FROM `$table_name` WHERE `ip_address` = '$ip' AND `time_slot` > $current_time";

		$results = $wpdb->get_row( $sql );

		$login_attempts = intval( $results->attempts );

		return $login_attempts;

	}

	public function login_attempt( $redirect, $request, $user ){

		$login_attempts = $this::get_login_attempts();

		if( isset( $user ) ){

			if( $login_attempts >= 3 ){

				$redirect = wp_login_url();

				return $redirect;

			} else {

				return $redirect;

			}

		} else {

			if( $login_attempts >= 3 ){

				$redirect = wp_login_url();

				return $redirect;

			} else {

				return $redirect;

			}
		}
	}

	public function login_attempt_message( $message ){

		$login_attempts = $this->get_login_attempts();

		$login_timeframe = apply_filters( 'wll_login_limit_timeframe', 10 );

		$login_limit = apply_filters( 'wll_login_limit_value', 3 );

		if( $login_attempts >= $login_limit ){

			$message .= "<div class='message'><p><strong>Login Attempt Blocked</strong> You have been blocked for $login_timeframe minutes. Please try again later.</p></div>";

		}

		return $message;

	}



	public function login_attempt_failed( $username ){

		add_filter( 'login_errors', function( $error ) {

			$login_timeframe = apply_filters( 'wll_login_limit_timeframe', 10 );

			$login_limit = apply_filters( 'wll_login_limit_value', 3 );

			$login_attempts = $this::get_login_attempts();

			if( $login_attempts < $login_limit ){
				$error .= "<br/><strong>Login Attempt Blocked</strong> After $login_limit incorrect attempts you will be blocked for $login_timeframe minutes.";
			}

			return $error;

		} );

		global $wpdb;

		$table_name = $wpdb->prefix . 'users_login_logs';

		$ip = $this::get_user_ip();



		$wpdb->insert(
			$table_name,
			array(
				'username' => $username,
				'ip_address' => $ip,
				'time_slot' => current_time( 'timestamp' )
			),
			array(
				'%s',
				'%s',
				'%s'
			)
		);

	}

	public function extra_profile_fields( $user ) {
		if(is_object($user))
			$phone = esc_attr( get_user_meta( $user->ID,'phone',true ) );
		else
			$phone = null;
		?>
		<table class="form-table">
			<tr>
				<th><label for="phone">手机号</label></th>
				<td>
					<input type="tel" name="phone" id="phone" value="<?php echo $phone; ?>" class="regular-text" /><br />
					<span class="description">Please enter your phone number.</span>
				</td>
			</tr>
		</table>
	<?php }
	public function save_extra_profile_fields( $user_id ) {

		if ( !current_user_can( 'edit_user', $user_id ) )
			return false;

		update_user_meta( $user_id, 'phone', $_POST['phone'] );
	}



    /**
     * Singleton class instance
     * @return Login_Counter
     */
    public static function get_instance() {
        static $instance;
        if ( ! isset( $instance ) ) {
            $instance = new self();
            $instance->init();
        }

        return $instance;
    }
}

User_Login_Plus::get_instance();