<?php

namespace StackWatch\Laravel\Listeners;

use StackWatch\Laravel\StackWatch;

/**
 * Listener for Spatie Laravel Activity Log events.
 *
 * @see https://spatie.be/docs/laravel-activitylog
 */
class SpatieActivityLogListener
{
    protected StackWatch $stackWatch;

    public function __construct(StackWatch $stackWatch)
    {
        $this->stackWatch = $stackWatch;
    }

    /**
     * Handle activity logged event.
     */
    public function handleActivityLogged($event): void
    {
        $activity = $event->activity;

        // Get configuration for filtering
        $allowedLogNames = config('stackwatch.integrations.spatie_activitylog.log_names', []);
        $allowedEventTypes = config('stackwatch.integrations.spatie_activitylog.event_types', []);

        // Filter by log name if configured
        if (!empty($allowedLogNames) && !in_array($activity->log_name, $allowedLogNames)) {
            return;
        }

        // Filter by event type if configured
        if (!empty($allowedEventTypes) && !in_array($activity->event, $allowedEventTypes)) {
            return;
        }

        // Determine level based on event type
        $level = match ($activity->event) {
            'deleted' => 'warning',
            'created', 'updated' => 'info',
            default => 'info',
        };

        // Build context
        $context = [
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'causer_type' => $activity->causer_type,
            'causer_id' => $activity->causer_id,
            'properties' => $activity->properties?->toArray() ?? [],
            'batch_uuid' => $activity->batch_uuid,
        ];

        // Build message
        $message = $activity->description;
        if ($activity->causer) {
            $causerName = $activity->causer->name ?? $activity->causer->email ?? "User #{$activity->causer_id}";
            $message = "{$causerName}: {$activity->description}";
        }

        $this->stackWatch->captureEvent('activity', $level, $message, $context);

        // Add as breadcrumb too
        $this->stackWatch->addBreadcrumb('activity', $activity->description, [
            'event' => $activity->event,
            'subject' => $activity->subject_type,
            'causer' => $activity->causer_type,
        ], $level);
    }

    /**
     * Subscribe to Spatie Activity Log events.
     *
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            'Spatie\Activitylog\Events\ActivityLoggedEvent' => 'handleActivityLogged',
        ];
    }
}
