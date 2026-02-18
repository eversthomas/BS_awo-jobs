<?php

namespace BsAwoJobs\Core;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Vergleicht zwei Snapshots von Jobs und erkennt Lifecycle-Events.
 */
class DiffEngine
{
    /**
     * Vergleicht Snapshots und liefert Events gruppiert nach Typ.
     *
     * @param array $previousJobs Vorheriger Snapshot (beliebig indiziert).
     * @param array $currentJobs  Aktueller Snapshot (beliebig indiziert).
     * @param int   $runId        ID des aktuellen Runs (wird im Ergebnis nur durchgereicht).
     * @return array{created: array<int, array>, modified: array<int, array>, offlined: array<int, array>}
     */
    public function compute_diff(array $previousJobs, array $currentJobs, $runId)
    {
        $prevIndexed = $this->index_by_job_id($previousJobs);
        $currIndexed = $this->index_by_job_id($currentJobs);

        $created  = [];
        $modified = [];
        $offlined = [];

        $today = current_time('Y-m-d');

        // CREATED + MODIFIED
        foreach ($currIndexed as $jobId => $newJob) {
            if (! isset($prevIndexed[$jobId])) {
                // Neu
                $created[] = [
                    'job_id'         => $jobId,
                    'event_type'     => 'created',
                    'event_date'     => $today,
                    'previous_state' => null,
                    'new_state'      => $newJob,
                    'run_id'         => $runId,
                ];
                continue;
            }

            $oldJob = $prevIndexed[$jobId];

            if ($this->has_modified($oldJob, $newJob)) {
                $modified[] = [
                    'job_id'         => $jobId,
                    'event_type'     => 'modified',
                    'event_date'     => $today,
                    'previous_state' => $oldJob,
                    'new_state'      => $newJob,
                    'run_id'         => $runId,
                ];
            }
        }

        // OFFLINED
        foreach ($prevIndexed as $jobId => $oldJob) {
            if (! isset($currIndexed[$jobId])) {
                $offlined[] = [
                    'job_id'         => $jobId,
                    'event_type'     => 'offlined',
                    'event_date'     => $today,
                    'previous_state' => $oldJob,
                    'new_state'      => null,
                    'run_id'         => $runId,
                ];
            }
        }

        return [
            'created'  => $created,
            'modified' => $modified,
            'offlined' => $offlined,
        ];
    }

    /**
     * Indexiert ein Job-Array nach Stellennummer (job_id).
     *
     * @param array $jobs
     * @return array<string, array>
     */
    private function index_by_job_id(array $jobs)
    {
        $indexed = [];

        foreach ($jobs as $job) {
            if (! is_array($job)) {
                continue;
            }

            if (! isset($job['Stellennummer']) || $job['Stellennummer'] === '') {
                continue;
            }

            $jobId = (string) $job['Stellennummer'];
            $indexed[$jobId] = $job;
        }

        return $indexed;
    }

    /**
     * Prüft, ob ein Job als "modified" gilt.
     *
     * Aktuell reicht ein geändertes Feld "Aenderungsdatum" (Unix-Timestamp).
     *
     * @param array $oldJob
     * @param array $newJob
     * @return bool
     */
    private function has_modified(array $oldJob, array $newJob)
    {
        $old = isset($oldJob['Aenderungsdatum']) ? (string) $oldJob['Aenderungsdatum'] : '';
        $new = isset($newJob['Aenderungsdatum']) ? (string) $newJob['Aenderungsdatum'] : '';

        return $old !== '' && $new !== '' && $old !== $new;
    }
}

