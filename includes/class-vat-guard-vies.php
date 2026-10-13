<?php
/**
 * VAT Guard for WooCommerce VIES validation helper
 *
 * @package Stormlabs\EUVATGuard
 */

namespace Stormlabs\EUVATGuard;

use SoapClient;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

class VAT_Guard_VIES
{
    /**
     * Check VAT number validity using the VIES SOAP API
     * @param string $country Two-letter country code
     * @param string $vat VAT number (without country code)
     * @return bool|null true=valid, false=invalid, null=error/unavailable
     */
    public static function check_vat($country, $vat)
    {
		if ( ! class_exists( 'SoapClient' ) ) {
			return null;
		}

        $wsdl = 'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl';
        try {
            $client = @new SoapClient($wsdl, ['exceptions' => true, 'cache_wsdl' => WSDL_CACHE_NONE]);
            $result = $client->checkVat([
                'countryCode' => $country,
                'vatNumber' => $vat
            ]);
            return $result->valid ? true : false;
        } catch ( Throwable $e ) {
			wc_get_logger()->error(
				$e->getMessage(),
				array( 'source' => 'eu-vat-guard-vies' )
			);

            return null; // VIES unavailable or error
        }
    }
}
