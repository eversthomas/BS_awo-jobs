<?php

namespace BsAwoJobs\Core;

if (! defined('ABSPATH')) {
    exit;
}

class SchemaInspector
{
    /**
     * Analysiert das Job-Array und liefert einen Bericht.
     *
     * @param array $jobs
     * @return array
     */
    public static function analyze(array $jobs)
    {
        $fieldStats = [];
        $totalJobs  = count($jobs);

        $stellennummerSet   = [];
        $stellennummerDupes = 0;
        $stellennummerMissing = 0;

        $mandantFilled   = 0;
        $fachbereichEnum = [];
        $jobfamilyEnum   = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            // Stellennummer-Checks.
            if (empty($job['Stellennummer'])) {
                $stellennummerMissing++;
            } else {
                $id = (string) $job['Stellennummer'];
                if (isset($stellennummerSet[$id])) {
                    $stellennummerDupes++;
                } else {
                    $stellennummerSet[$id] = true;
                }
            }

            // Mandantnr/Einrichtungsnr Belegung.
            if (! empty($job['Mandantnr/Einrichtungsnr'])) {
                $mandantFilled++;
            }

            // Feldstatistik.
            foreach ($job as $field => $value) {
                if (! isset($fieldStats[$field])) {
                    $fieldStats[$field] = [
                        'name'        => $field,
                        'types'       => [],
                        'samples'     => [],
                        'null_count'  => 0,
                        'total'       => 0,
                    ];
                }

                $fieldStats[$field]['total']++;

                $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);
                if ($isEmpty) {
                    $fieldStats[$field]['null_count']++;
                }

                $type = self::detectType($value);
                $fieldStats[$field]['types'][$type] = true;

                if (count($fieldStats[$field]['samples']) < 3 && ! $isEmpty) {
                    $sampleValue = $value;
                    if (is_array($sampleValue) || is_object($sampleValue)) {
                        $sampleValue = wp_json_encode($sampleValue);
                    }

                    if (! in_array($sampleValue, $fieldStats[$field]['samples'], true)) {
                        $fieldStats[$field]['samples'][] = $sampleValue;
                    }
                }
            }

            // Fachbereich-IDs (Enumeration).
            if (isset($job['Fachbereich-IDs']) && is_array($job['Fachbereich-IDs'])) {
                foreach ($job['Fachbereich-IDs'] as $k => $v) {
                    $fachbereichEnum[(string) $k] = (string) $v;
                }
            }

            // Stellenbezeichnung-IDs (Jobfamily Enumeration).
            if (isset($job['Stellenbezeichnung-IDs']) && is_array($job['Stellenbezeichnung-IDs'])) {
                foreach ($job['Stellenbezeichnung-IDs'] as $k => $v) {
                    $jobfamilyEnum[(string) $k] = (string) $v;
                }
            }
        }

        // Typen als Strings zusammenführen.
        foreach ($fieldStats as &$stat) {
            $stat['types'] = implode(', ', array_keys($stat['types']));
        }
        unset($stat);

        $mandantPercent = $totalJobs > 0 ? round(($mandantFilled / $totalJobs) * 100, 2) : 0.0;

        return [
            'total_jobs'                  => $totalJobs,
            'fields'                      => $fieldStats,
            'stellennummer_unique'        => $stellennummerDupes === 0 && $stellennummerMissing === 0,
            'stellennummer_missing_count' => $stellennummerMissing,
            'stellennummer_dupe_count'    => $stellennummerDupes,
            'mandant_population_percent'  => $mandantPercent,
            'fachbereich_enum'            => $fachbereichEnum,
            'jobfamily_enum'              => $jobfamilyEnum,
        ];
    }

    /**
     * Einfache Typbestimmung.
     *
     * @param mixed $value
     * @return string
     */
    private static function detectType($value)
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_int($value)) {
            return 'int';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_bool($value)) {
            return 'bool';
        }

        if (is_array($value)) {
            return 'array';
        }

        if (is_object($value)) {
            return 'object';
        }

        return gettype($value);
    }
}

