<?php

namespace BsAwoJobs\Core;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Fetcher
{
    /**
     * Ruft JSON von einer URL ab und liefert das dekodierte Array.
     *
     * @param string $url
     * @return array|WP_Error
     */
    public static function fetch_json_from_url(string $url)
    {
        $url = trim($url);

        if ($url === '') {
            return new WP_Error(
                'bs_awo_jobs_invalid_url',
                __('Die API-URL ist leer.', 'bs-awo-jobs')
            );
        }

        // Nur HTTPS-URLs zulassen (Sicherheit).
        $safe_url = esc_url_raw($url, ['https']);
        if ($safe_url === '' || $safe_url !== $url) {
            return new WP_Error(
                'bs_awo_jobs_invalid_url',
                __('Die API-URL ist ungültig. Es sind nur HTTPS-URLs erlaubt.', 'bs-awo-jobs')
            );
        }

        $response = wp_remote_get(
            $safe_url,
            [
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error(
                'bs_awo_jobs_http_error',
                sprintf(
                    /* translators: %s: Fehlernachricht */
                    __('Fehler beim Abruf der API: %s', 'bs-awo-jobs'),
                    $response->get_error_message()
                )
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'bs_awo_jobs_http_status',
                sprintf(
                    /* translators: %d: HTTP-Statuscode */
                    __('Unerwarteter HTTP-Statuscode: %d', 'bs-awo-jobs'),
                    $code
                )
            );
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || $body === null) {
            return new WP_Error(
                'bs_awo_jobs_empty_body',
                __('Leere Antwort vom API-Server.', 'bs-awo-jobs')
            );
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            return new WP_Error(
                'bs_awo_jobs_malformed_json',
                sprintf(
                    /* translators: %s: JSON-Fehlernachricht */
                    __('Fehler beim Dekodieren des JSON: %s', 'bs-awo-jobs'),
                    json_last_error_msg()
                )
            );
        }

        return $data;
    }
}

