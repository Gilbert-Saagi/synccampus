<?php
declare(strict_types=1);

namespace SyncCampus\Engine;

/**
 * Decides whether an unresolved concern needs a reminder or an escalation.
 * Each concern gets at most one of each; an escalation also covers the reminder.
 */
final class Escalation
{
    /** @return 'escalation'|'reminder'|null */
    public static function evaluate(string $level, float $hoursOpen, bool $hasReminder, bool $hasEscalation): ?string
    {
        $limits = Rules::ESCALATION_HOURS[$level] ?? Rules::ESCALATION_HOURS['Low'];
        if ($hoursOpen >= $limits['escalate'] && !$hasEscalation) {
            return 'escalation';
        }
        if ($hoursOpen >= $limits['reminder'] && !$hasReminder && !$hasEscalation) {
            return 'reminder';
        }
        return null;
    }
}
