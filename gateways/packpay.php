<?php
/**
 * Packpay Gateway for Easy Digital Downloads
 */
if ( !defined( 'ABSPATH' ) )exit;

if ( !class_exists( 'EDD_Packpay_Gateway' ) ):


    class EDD_Packpay_Gateway {
        /**
         * Gateway keyname
         *
         * @var string
         */
        public $keyname;

        public $token;



        /**
         * Initialize gateway and hook
         *
         * @return 				void
         */
        public function __construct() {
            $this->keyname = 'packpay';

            add_filter( 'edd_payment_gateways', array( $this, 'add' ) );
            add_action( $this->format( 'edd_{key}_cc_form' ), array( $this, 'cc_form' ) );
            add_action( $this->format( 'edd_gateway_{key}' ), array( $this, 'process' ) );
            add_action( $this->format( 'edd_verify_{key}' ), array( $this, 'verify' ) );
            add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );

            add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );

            add_action( 'init', array( $this, 'listen' ) );
        }

        /**
         * Add gateway to list
         *
         * @param 				array $gateways Gateways array
         * @return 				array
         */
        public function add( $gateways ) {
            global $edd_options;

            $gateways[ $this->keyname ] = array(
                'checkout_label' => isset( $edd_options[ 'packpay_label' ] ) ? $edd_options[ 'packpay_label' ] : 'درگاه پرداخت پکپی',
                'admin_label' => 'پکپی'
            );

            return $gateways;
        }

        /**
         * CC Form
         * We don't need it anyway.
         *
         * @return bool
         */
        public function cc_form() {
            return;
        }

        /**
         * Process the payment
         *
         * @param 				array $purchase_data
         * @return 				void
         */
        public function process( $purchase_data ) {


            $payment = $this->insert_payment( $purchase_data );

            if ( $payment ) {

                $redirect = add_query_arg(
                    array('verify_' . $this->keyname => '1',
                        'paymentId' => $payment
                    ), get_permalink( edd_get_option( 'success_page', false ) ) );


                $amount = intval($purchase_data['price']);
                if ( edd_get_currency() == 'IRT' )
                    $amount = $amount * 10;

                $token_result = $this->refresh_token();
                if (!$token_result){
                    $this->fail_process($payment,"خطا در دریافت توکن");
                }
                $data = [
                    'access_token' => $this->token,
                    'amount' => $amount,
                    'callback_url' => $redirect,
                    'verify_on_request' => true
                ];
                $method = 'developers/bank/api/v2/purchase?' . http_build_query($data);
                $result = $this->request($method, []);

                $reference_code = array_key_exists('reference_code', $result) ? $result['reference_code'] :-1;
                $message = $result['message'];
                $status = $result['status'];

                if ( $status == 0) {
                    wp_redirect( "https://dashboard.packpay.ir/bank/purchase/send/?RefId=${reference_code}" );
                } else if ($status!=500) {
                    $this->fail_process($payment,$message);
                }
            } else {
                edd_send_back_to_checkout( '?payment-mode=' . $purchase_data[ 'post_data' ][ 'edd-gateway' ] );
            }
        }

        private function fail_process($payment,$message){
            edd_insert_payment_note( $payment, 'کد خطا: ' . $message );
            edd_update_payment_status( $payment, 'failed' );
            edd_set_error("1","$message");
            edd_send_back_to_checkout();
        }


        public function verify()
        {
            global $edd_options;

            $payment = isset($_GET['paymentId']) ? $_GET['paymentId'] : null;
            $this->refresh_token();
            $data = [
                'access_token' => $this->token,
                'reference_code' => $_GET['reference_code'],
            ];
            $method = 'developers/bank/api/v2/purchase/verify?' . http_build_query($data);
            $result = $this->request($method, [], 'POST');
            $access = $result['status'] == '0' && $result['message'] == 'successful' ? true : false;
            if ($access) {
                edd_insert_payment_note( $payment, 'شماره پیگیری پکپی: ' . $_GET['reference_code'] );
                edd_update_payment_meta( $payment, 'packpay_refid', $_GET['reference_code'] );
                edd_update_payment_status($payment, 'publish');
                edd_send_to_success_page();
            } else {
                edd_update_payment_status($payment, 'failed');
                wp_redirect(get_permalink($edd_options['failure_page']));
                exit;
            }

        }
        public function refresh_token()
        {
            global $edd_options;
            $ref_tkn = $edd_options[ $this->keyname . '_ref_tkn' ];
            $data = [
                'grant_type' => 'refresh_token',
                'refresh_token' => $ref_tkn,
            ];
            $method = 'oauth/token?' . http_build_query($data);
            $result = $this->request($method, []);
            if (!array_key_exists('access_token',$result)) return false;
            $this->token = $result['access_token'];
            return true;
        }

        public function request($method, $params, $type = 'POST')
        {
            try {
                global $edd_options;
                $cid = $edd_options[ $this->keyname . '_cid' ];
                $sid = $edd_options[ $this->keyname . '_sid' ];
                $edd_options[ $this->keyname . '_sid' ];
                $ch = curl_init("https://dashboard.packpay.ir/" . $method);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, $cid . ":" . $sid);
                curl_setopt(
                    $ch,
                    CURLOPT_HTTPHEADER,
                    array(
                        'Content-Type: application/json',
                    )
                );
                $result = curl_exec($ch);

                return json_decode($result, true);
            } catch (Exception $ex) {
                return false;
            }
        }

        /**
         * Receipt field for payment
         *
         * @param 				object $payment
         * @return 				void
         */
        public function receipt( $payment ) {
            $refid = edd_get_payment_meta( $payment, 'packpay_refid' );
            if ( $refid ) {
                echo '<tr class="packpay-ref-id-row bdt-field packpay-dev"><td><strong>شماره تراکنش پکپی:</strong></td><td>' . $refid . '</td></tr>';
            }
        }

        /**
         * Gateway settings
         *
         * @param 				array $settings
         * @return 				array
         */
        public function settings( $settings ) {
            return array_merge( $settings, array(
                $this->keyname . '_header' => array(
                    'id' => $this->keyname . '_header',
                    'type' => 'header',
                    'name' => '<strong>درگاه پکپی</strong>'
                ),
                $this->keyname . '_cid' => array(
                    'id' => $this->keyname . '_cid',
                    'name' => 'Client ID',
                    'type' => 'text',
                    'size' => 'regular'
                ),
                $this->keyname . '_sid' => array(
                    'id' => $this->keyname . '_sid',
                    'name' => 'Secret ID',
                    'type' => 'password',
                    'size' => 'regular'
                ),
                $this->keyname . '_reftkn' => array(
                    'id' => $this->keyname . '_ref_tkn',
                    'name' => 'Refresh Token',
                    'type' => 'text',
                    'size' => 'regular',
                )
            ) );
        }

        /**
         * Format a string, replaces {key} with $keyname
         *
         * @param 			string $string To format
         * @return 			string Formatted
         */
        private function format( $string ) {
            return str_replace( '{key}', $this->keyname, $string );
        }

        /**
         * Inserts a payment into database
         *
         * @param 			array $purchase_data
         * @return 			int $payment
         */
        private function insert_payment( $purchase_data ) {
            global $edd_options;

            $payment_data = array(
                'price' => $purchase_data[ 'price' ],
                'date' => $purchase_data[ 'date' ],
                'user_email' => $purchase_data[ 'user_email' ],
                'purchase_key' => $purchase_data[ 'purchase_key' ],
                'currency' => $edd_options[ 'currency' ],
                'downloads' => $purchase_data[ 'downloads' ],
                'user_info' => $purchase_data[ 'user_info' ],
                'cart_details' => $purchase_data[ 'cart_details' ],
                'status' => 'pending'
            );
            $payment = edd_insert_payment( $payment_data );

            return $payment;
        }

        /**
         * Listen to incoming queries
         *
         * @return 			void
         */
        public function listen() {
            if ( isset( $_GET[ 'verify_' . $this->keyname ] ) && $_GET[ 'verify_' . $this->keyname ] ) {
                do_action( 'edd_verify_' . $this->keyname );
            }
        }
    }

endif;

new EDD_Packpay_Gateway;