<?php

error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

/**
 * Plugin Name: OMD Woocommerce DI
 * Description: Woocommerce addon that exports orders to DI Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'OMDWoocommerceDI ') ) {

    class OMDWoocommerceDI
    {
        private $shop_id;
        private $transport_solution_id;
        private $token_url;
        private $booking_url;
        private $username;
        private $password;

        public $token;

        function __construct() {

            $this->shop_id                  = get_option( 'di_shop_id' );
            $this->transport_solution_id    = get_option( 'di_transport_id' );
            $this->token_url                = get_option( 'di_token_url' );
            $this->booking_url              = get_option( 'di_booking_url' );
            $this->username                 = get_option( 'di_username' );
            $this->password                 = get_option( 'di_password' );

            add_action( 'admin_menu',                           array( $this, 'setup_settings' ) );
            add_action( 'admin_enqueue_scripts',                array( $this, 'register_script' ) );
            add_action( 'klarna_after_kco_confirmation',        array( $this, 'send_data_to_di' ), 10, 2 );
            add_action( 'wp_ajax_address_helper',               array( $this, 'address_helper' ) );
            add_action( 'wp_ajax_nopriv_address_helper',        array( $this, 'address_helper' ) );
            add_action( 'omd_before_index_load' ,               array( $this, 'clear_DI_SENT_session' ) );
            add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'show_address_helper_data' ) );

        }

        function clear_DI_SENT_session() {
            WC()->session->set( 'DI_SENT', FALSE );
        }

        function send_data_to_di( $order_id, $klarna_order ) {
            // var_dump( $klarna_order );
            // Do not run again if order is already successfully sent to DI previously
            if( !WC()->session->get( 'DI_SENT' ) ) {
                $order = wc_get_order( $order_id );
                // $this->ah_to_woocommerce_shipping();

                $data = $this->create_order_data_for_di( $order_id, $klarna_order );
                
                if( !$data ) {
                    // We somehow got here without the data we need.
                    // Session might have been lost and user reloaded the thank you-page.
                    $this->clear_and_redirect_home();
                }
                
                $data_string = json_encode( $data );

                $token = $this->get_token( $order_id );

                $this->localize_script( $order_id, $klarna_order );

                $curl = curl_init();

                curl_setopt( $curl, CURLOPT_HTTPHEADER, array(
                        "Authorization: Bearer $token",
                        "Content-Type: application/json"
                    )
                );

                curl_setopt_array( $curl, array(
                        CURLOPT_RETURNTRANSFER  => 1,
                        CURLOPT_URL             => $this->booking_url,
                        CURLOPT_POST            => 1,
                        CURLOPT_POSTFIELDS      => $data_string
                    )
                );

                try {
                    $response = curl_exec( $curl );
                } catch(Exception $e) {
                    $this->handle_error('DI BOOKING API ERROR', $order_id );
                }

                $response = json_decode($response);

                if(property_exists($response, 'errorKey')) {
                    // Something went wrong, log the error from di booking api
                    $this->handle_error($response->errorKey,  $order_id );
                } else {
                    WC()->session->set( 'DI_SENT', TRUE );

                    // Add Address Helper data to order
                    $order->update_meta_data( 'ah_city',            WC()->session->get( 'ah_city' ) );
                    $order->update_meta_data( 'ah_street',          WC()->session->get( 'ah_street' ) );
                    $order->update_meta_data( 'ah_street_number',   WC()->session->get( 'ah_streetNumber' ) );
                    $order->update_meta_data( 'ah_street_entrance', WC()->session->get( 'ah_streetEntrance' ) );
                    $order->update_meta_data( 'ah_postalcode',      WC()->session->get( 'ah_postalCode' ) );
                    $order->update_meta_data( 'ah_floorNo',         WC()->session->get( 'ah_floorNo' ) );
                    $order->update_meta_data( 'ah_flatNo',          WC()->session->get( 'ah_flatNo' ) );
                    $order->save();

                    // $this->log_order( $response, $order_id );
                }

                curl_close( $curl );

                // Clear session variables
                WC()->session->__unset( 'ah_city' );
                WC()->session->__unset( 'ah_street' );
                WC()->session->__unset( 'ah_streetNumber' );
                WC()->session->__unset( 'ah_streetEntrance' );
                WC()->session->__unset( 'ah_postalCode' );
                WC()->session->__unset( 'ah_floorNo' );
                WC()->session->__unset( 'ah_flatNo' )

                // Output result to console
                ?>
                    <script>
                        console.log('DI Response: ', <?php echo json_encode($response); ?>);
                    </script>
                <?php

                // Test for error handling

                // $error = 'error text';
                // $log = "IP: " . $_SERVER['REMOTE_ADDR'] . ' - ' . date('F j, Y, g:i a') . PHP_EOL .
                // "Error message: " . $error . PHP_EOL;

                // file_put_contents('./log_' . date('j.n.Y') . '.txt', $log, FILE_APPEND);   
                
                // // Cancel order and deliver customer message
                // $this->order->update_status('cancelled', 'DI Token API Error');

                // $html = '<script>jQuery(".klarna-thank-you-snippet").hide();</script>';
                // echo $html . '<div class="error-message">Något gick fel, försök igen senare.</div>';

                // end test
            } else {
                $this->clear_and_redirect_home();
            }
        }

        function clear_and_redirect_home() {
            // Clear session and redirect home if order is already sent
            WC()->session->set( 'DI_SENT', FALSE );
            wp_redirect( home_url() );
            exit;
        }

        function get_token( $order_id ) {

            $curl = curl_init();

            $data = array(
                'username' => $this->username,
                'password' => $this->password
            );

            $data_string = json_encode( $data );

            curl_setopt( $curl, CURLOPT_HTTPHEADER, array(
                    "Content-Type: application/json"
                )
            );

            curl_setopt_array( $curl, array(
                    CURLOPT_RETURNTRANSFER  => 1,
                    CURLOPT_URL             => $this->token_url,
                    CURLOPT_POST            => 1,
                    CURLOPT_POSTFIELDS      => $data_string
                )
            );

            $token = curl_exec( $curl );
            
            try {
                $token = json_decode( $token )->token;
            } catch(Exception $e)  {
                $this->handle_error('DI TOKEN API ERROR', $order_id );
            }

            curl_close( $curl );

            return $token;
        }

        function address_helper() {
            WC()->session->set( 'ah_city', $_REQUEST['city'] );
            WC()->session->set( 'ah_street', $_REQUEST['street'] );
            WC()->session->set( 'ah_streetNumber', $_REQUEST['streetNumber'] );

            if( !empty( $_REQUEST['streetEntrance'] ) ) {
                WC()->session->set( 'ah_streetEntrance', $_REQUEST['streetEntrance']);
            } else {
                WC()->session->set( 'ah_streetEntrance', '' );
            }

            WC()->session->set( 'ah_postalCode', $_REQUEST['postalCode'] );

            if( !empty( $_REQUEST['floorNo'] ) && $_REQUEST['floorNo'] > -1 ) {
                WC()->session->set( 'ah_floorNo', $_REQUEST['floorNo']);
            } else {
                WC()->session->set( 'ah_floorNo', '' );
            }

            if( !empty( $_REQUEST['flatNo'] ) && $_REQUEST['flatNo'] > -1 ) {
                WC()->session->set( 'ah_flatNo', $_REQUEST['flatNo']);
            } else {
                WC()->session->set( 'ah_flatNo', '' );
            }

            $response = array(
                'ahCity'                => WC()->session->get( 'ah_city' ),
                'ahStreet'              => WC()->session->get( 'ah_street' ),
                'ahStreetNumber'        => WC()->session->get( 'ah_streetNumber' ),
                'ahStreetEntrance'      => WC()->session->get( 'ah_streetEntrance' ),
                'ahPostalCode'          => WC()->session->get( 'ah_postalCode' ),
                'ahFloorNo'             => WC()->session->get( 'ah_floorNo' ),
                'ahFlatNo'              => WC()->session->get( 'ah_flatNo' )
            );

            echo json_encode($response);

            die();
        }

        function show_address_helper_data( $order ) {
            $floorNo = '';
            $flatNo = '';

            if( !empty( $order->get_meta( 'ah_floorNo' ) ) ) {
                $floorNo = 'Våning: ' . $order->get_meta( 'ah_floorNo' );
            }

            if( !empty( $order->get_meta( 'ah_flatNo' ) ) ) {
                $flatNo = 'Lägenhetsnummer: ' . $order->get_meta( 'ah_flatNo' );
            }

            echo '<div class="order_data_column">
                    <h3>Address Helper</h3>
                    <div class="address">
                        <p><b>Adress:</b><br>
                            '. $order->get_meta( 'ah_street' ) .' ' . $order->get_meta( 'ah_street_number' ) . ' ' . $order->get_meta( 'ah_street_entrance' ) .'<br>
                            '. $order->get_meta( 'ah_postalcode' ) .' '. $order->get_meta( 'ah_city' ) .'<br>
                            '. $floorNo .'<br>
                            '. $flatNo .'
                        </p>
                    </div>
                </div>';
        }

        function create_order_data_for_di( $order_id, $klarna_order ) {
            $order = wc_get_order( $order_id );
            $items = array();
            $counter = 1;
            
            foreach ( $order->get_items() as $item ) {
                for($i = 0; $i < $item->get_quantity(); $i++) {
                    $temp = array(
                        'itemNumber' => $counter++,
                        'weight'     => 800,
                        'contents'   => $item->get_name(),
                        'properties' => array (
                            'ProductType' => 'Bread'
                        )
                    );
                    $items[] = $temp;
                }
            }

            $isThursdayPast15 = 4 == date('w', strtotime('now -15 hours'));
            $isFriday = 5 == date('w', strtotime('now'));
            
            if ( $isThursdayPast15 || $isFriday ) {
                $desiredDeliveryDate = date("Ymd", strtotime("next saturday + 1 week"));
            } else {
                $desiredDeliveryDate = date("Ymd", strtotime("next saturday"));
            }

            $data = array(
                'shopId' => $this->shop_id,
                'transportSolutionId' => $this->transport_solution_id,
                'desiredDeliveryDate' => $desiredDeliveryDate,
                'parties' => array(
                        array(
                            'type'          => 'consignee',
                            'name'          => $klarna_order['shipping_address']['given_name'] . ' ' . $klarna_order['shipping_address']['family_name'],
                            'countryCode'   => 'SE',
                            'postalName'    => WC()->session->get( 'ah_city' ),
                            'zipCode'       => WC()->session->get( 'ah_postalCode' ),
                            'address'       => WC()->session->get( 'ah_street' ) . ' ' 
                                             . WC()->session->get( 'ah_streetNumber' ) . '' 
                                             . WC()->session->get( 'ah_streetEntrance' ) . ' '
                                             . WC()->session->get( 'ah_floorNo' ) . ''
                                             . WC()->session->get( 'ah_flatNo' ),
                            'phone1'        => $klarna_order['billing_address']['phone'],
                            'email'         => $klarna_order['billing_address']['email']
                        ),
                        array(
                            'type'          => 'consignor',
                            'name'          => 'Bread&Paper',
                            'countryCode'   => 'SE',
                            'zipCode'       => '60228'
                        )
                    ),
                'items' => $items
            );

            // If somehow session is missing data, return NULL
            if( empty( WC()->session->get( 'ah_city' ) ) ||
                empty( WC()->session->get( 'ah_postalCode' ) ) ||
                empty( WC()->session->get( 'ah_street' ) ) ) {
                return NULL;
            }

            return $data;
        }

        function register_script() {
            wp_enqueue_script( 'omd-woocommerce-di-mainjs', plugin_dir_url( __FILE__ ) . 'js/main.js', array( 'jquery' ), '1.0', true );
        }

        function localize_script( $order_id, $klarna_order ) {
            wp_localize_script(
                'omd-woocommerce-di-mainjs',
                'admin',
                array(
                    'url' => admin_url( 'admin-ajax.php' ),
                    'order' => $this->create_order_data_for_di( $order_id, $klarna_order )
                )
            );
        }

        function handle_error( $message, $order_id ) {
            $order = wc_get_order( $order_id );
            $actual_link = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

            // log file            
            $log = "IP: " . $_SERVER['REMOTE_ADDR'] . ' - ' . date('F j, Y, g:i a') . PHP_EOL .
                    "Error message: " . $message . PHP_EOL .
                    "Order ID: " . $order->id . PHP_EOL .
                    "Name sent to DI: " . $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() . PHP_EOL .
                    "URL: " . $actual_link . PHP_EOL;

            file_put_contents('./log_' . date('j.n.Y') . '.txt', $log, FILE_APPEND);   
            
            if( $order_id ) {
                // Cancel order and deliver customer message
                $order->update_status('cancelled', $message );
            }
            
            $html = '<script>jQuery(".klarna-thank-you-snippet").hide();</script>';
            echo $html . '<div class="error-message">Något gick fel, försök igen senare.</div>';

            echo $html;
        }

        function log_order( $response, $order_id ) {
            // $log = "IP: " . $_SERVER['REMOTE_ADDR'] . ' - ' . date('F j, Y, g:i a') . PHP_EOL .
            //         "Respone message: " . $response . PHP_EOL .
            //         "Order ID: " . $order_id . PHP_EOL .
            //         "Name sent to DI: " . $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() . PHP_EOL;

            // file_put_contents('./di_order_response' . date('j.n.Y') . '.txt', $log, FILE_APPEND);
        }

        function setup_settings() {

            // Generate admin page
            add_menu_page( 'OMD DI Inställningar', 'OMD DI', 'manage_options', 'omd-woocommerce-di', 'omd_woocommerce_di_page', '' , 110 );

            function omd_woocommerce_di_page() {
                require_once( plugin_dir_path( __FILE__ ) . 'omd-woocommerce-di-admin.php' );
            }

            add_settings_section( 'omd-di-settings-section', '', 'omd_di_settings_section', 'omd-woocommerce-di' );

            add_settings_field( 'omd-di-transport-id', 'Transport ID', 'omd_di_transport_id', 'omd-woocommerce-di', 'omd-di-settings-section' );
            add_settings_field( 'omd-di-shop-id', 'Shop ID', 'omd_di_shop_id', 'omd-woocommerce-di', 'omd-di-settings-section' );

            add_settings_field( 'omd-di-token-url', 'Token API URL', 'omd_di_token_url', 'omd-woocommerce-di', 'omd-di-settings-section' );
            add_settings_field( 'omd-di-booking-url', 'Booking API URL', 'omd_di_booking_url', 'omd-woocommerce-di', 'omd-di-settings-section' );

            add_settings_field( 'omd-di-username', 'Username', 'omd_di_username', 'omd-woocommerce-di', 'omd-di-settings-section' );
            add_settings_field( 'omd-di-password', 'Password', 'omd_di_password', 'omd-woocommerce-di', 'omd-di-settings-section' );

            register_setting( 'omd-woocommerce-di', 'di_transport_id' );
            register_setting( 'omd-woocommerce-di', 'di_shop_id' );

            register_setting( 'omd-woocommerce-di', 'di_token_url' );
            register_setting( 'omd-woocommerce-di', 'di_booking_url' );

            register_setting( 'omd-woocommerce-di', 'di_username' );
            register_setting( 'omd-woocommerce-di', 'di_password' );

            function omd_di_settings_section() {
                echo '<h2>Inställningar</h2>';
            }

            function omd_di_transport_id() {
                $transport_id = esc_attr( get_option( 'di_transport_id' ) );
                echo "<input type='text' name='di_transport_id' value='$transport_id' />";
            }

            function omd_di_shop_id() {
                $shop_id = esc_attr( get_option( 'di_shop_id' ) );
                echo "<input type='text' name='di_shop_id' value='$shop_id' />";
            }

            function omd_di_token_url() {
                $token_url = esc_attr( get_option( 'di_token_url' ) );
                echo "<input type='text' name='di_token_url' value='$token_url' />";
            }

            function omd_di_booking_url() {
                $booking_url = esc_attr( get_option( 'di_booking_url' ) );
                echo "<input type='text' name='di_booking_url' value='$booking_url' />";
            }

            function omd_di_username() {
                $username = esc_attr( get_option( 'di_username' ) );
                echo "<input type='text' name='di_username' value='$username' />";
            }

            function omd_di_password() {
                $password = esc_attr( get_option( 'di_password') );
                echo "<input type='text' name='di_password' value='$password' />";
            }

        }

    }

}

$omdWoocommerceDi = new OMDWoocommerceDI();