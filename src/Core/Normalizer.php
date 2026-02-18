<?php

namespace BsAwoJobs\Core;

if (! defined('ABSPATH')) {
    exit;
}

class Normalizer
{
    /**
     * Normalisiert einen String:
     * - Kleinbuchstaben
     * - Umlaute ersetzen
     * - Nicht-alphanumerische Zeichen entfernen
     *
     * @param string $str
     * @return string
     */
    public static function normalize_string($str)
    {
        $str = (string) $str;
        $str = mb_strtolower($str, 'UTF-8');
        $str = str_replace(
            ['ä', 'ö', 'ü', 'ß'],
            ['ae', 'oe', 'ue', 'ss'],
            $str
        );
        $str = preg_replace('/[^a-z0-9]+/', '', $str);

        return (string) $str;
    }

    /**
     * Generiert eine facility_id aus Einrichtungs- und Adressdaten.
     *
     * hash(Einrichtung + Strasse + PLZ + Ort)
     *
     * @param array $jobData
     * @return string
     */
    public static function generate_facility_id(array $jobData)
    {
        $einrichtung = isset($jobData['Einrichtung']) ? $jobData['Einrichtung'] : '';
        $strasse     = isset($jobData['Strasse']) ? $jobData['Strasse'] : '';
        $plz         = isset($jobData['PLZ']) ? $jobData['PLZ'] : '';
        $ort         = isset($jobData['Ort']) ? $jobData['Ort'] : '';

        $key = sprintf(
            '%s|%s|%s|%s',
            self::normalize_string($einrichtung),
            self::normalize_string($strasse),
            (string) $plz,
            self::normalize_string($ort)
        );

        $algo = 'md5';
        if (function_exists('hash_algos') && in_array('xxh3', hash_algos(), true)) {
            $algo = 'xxh3';
        }

        $hash = hash($algo, $key);

        // Tabellen-Schema sieht CHAR(16) vor – wir kürzen den Hash deterministisch.
        return substr($hash, 0, 16);
    }
}

