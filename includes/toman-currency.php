<?php
/**
 * Add Toman currency for EDD
 *
 * @param 				array $currencies Currencies list
 * @return 				array
 */
if ( ! function_exists('packpay_add_toman_currency')):
    function packpay_add_toman_currency( $currencies ) {
        $currencies['IRT'] = 'تومان';
        return $currencies;
    }
endif;
add_filter( 'edd_currencies', 'packpay_add_toman_currency' );

/**
 * Format decimals
 */
add_filter( 'edd_sanitize_amount_decimals', function( $decimals ) {

    $currency = function_exists('edd_get_currency') ? edd_get_currency() : '';
    global $edd_options;

    if (array_key_exists('currency', $edd_options) &&( $edd_options['currency'] == 'IRT'|| $edd_options['currency'] == 'RIAL' )) {
        return $decimals = 0;
    }

    if ($currency == 'IRT' || $currency == 'RIAL' ) {
        return $decimals = 0;
    }

    return $decimals;
} );

add_filter( 'edd_format_amount_decimals', function( $decimals ) {

    $currency = function_exists('edd_get_currency') ? edd_get_currency() : '';

    global $edd_options;

    if (array_key_exists('currency', $edd_options) &&( $edd_options['currency'] == 'IRT'|| $edd_options['currency'] == 'RIAL' )) {
        return $decimals = 0;
    }

    if ($currency == 'IRT' || $currency == 'RIAL' ) {
        return $decimals = 0;
    }

    return $decimals;
} );

add_filter( 'edd_rial_currency_filter_after', 'pw_edd_change_currency_sign_rial', 10, 3 );
add_filter( 'edd_rial_currency_filter_before', 'pw_edd_change_currency_sign_rial', 10, 3 );

add_filter( 'edd_irt_currency_filter_after', 'pw_edd_change_currency_sign_toman', 10, 3 );
add_filter( 'edd_irt_currency_filter_before', 'pw_edd_change_currency_sign_toman', 10, 3 );
function pw_edd_change_currency_sign_rial( $formatted, $currency, $price ) {
    return $price . 'ریال';
}
function pw_edd_change_currency_sign_toman( $formatted, $currency, $price ) {
    return $price . 'تومان';
}

