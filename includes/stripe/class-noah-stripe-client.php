<?php
defined( 'ABSPATH' ) || exit;

/**
 * Noah_Stripe_Client
 *
 * Thin wrapper around WC_Stripe_API (the official WooCommerce Stripe Gateway's
 * internal helper). That plugin does NOT bundle the stripe-php SDK — there is
 * no \Stripe\StripeClient object anywhere. WC_Stripe_API::request()/retrieve()
 * make raw wp_remote_post()/wp_remote_get() calls to api.stripe.com and return
 * the decoded JSON response directly; Stripe-level errors come back as a
 * decoded object with an ->error property, not a thrown exception (only
 * network outages/empty responses throw WC_Stripe_Exception).
 *
 * This class normalizes both failure modes into a single thrown \RuntimeException
 * so callers can use one try/catch, same as if a real SDK client were in use.
 */
class Noah_Stripe_Client {

    public static function available(): bool {
        return class_exists( 'WC_Stripe_API' );
    }

    /**
     * @throws \RuntimeException
     */
    public static function post( array $params, string $api ): object {
        return self::call( $params, $api, 'POST' );
    }

    /**
     * @throws \RuntimeException
     */
    public static function delete( string $api ): object {
        return self::call( [], $api, 'DELETE' );
    }

    /**
     * @throws \RuntimeException
     */
    public static function get( string $api, array $query = [] ): object {
        $path     = $query ? $api . '?' . http_build_query( $query ) : $api;
        $response = WC_Stripe_API::retrieve( $path );

        if ( null === $response || is_wp_error( $response ) ) {
            throw new \RuntimeException( is_wp_error( $response )
                ? $response->get_error_message()
                : "Stripe API GET {$api} returned no response" );
        }
        if ( isset( $response->error ) ) {
            throw new \RuntimeException( $response->error->message ?? 'Unknown Stripe API error' );
        }
        return $response;
    }

    /**
     * @throws \RuntimeException
     */
    private static function call( array $params, string $api, string $method ): object {
        $response = WC_Stripe_API::request( $params, $api, $method );

        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException( $response->get_error_message() );
        }
        if ( isset( $response->error ) ) {
            throw new \RuntimeException( $response->error->message ?? 'Unknown Stripe API error' );
        }
        return $response;
    }
}
