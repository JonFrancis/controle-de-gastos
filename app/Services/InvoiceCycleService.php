<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class InvoiceCycleService
{
    public function closingDate(CarbonInterface $purchaseDate, int $closingDay): CarbonImmutable
    {
        $date = CarbonImmutable::instance($purchaseDate);
        $effectiveClosingDay = min($closingDay, $date->daysInMonth);

        if ($date->day > $effectiveClosingDay) {
            $date = $date->addMonthNoOverflow();
            $effectiveClosingDay = min($closingDay, $date->daysInMonth);
        }

        return $date->setDay($effectiveClosingDay)->startOfDay();
    }

    public function dueDate(CarbonInterface $closingDate, int $dueDay): CarbonImmutable
    {
        $date = CarbonImmutable::instance($closingDate)->startOfDay();
        $effectiveDueDay = min($dueDay, $date->daysInMonth);
        $candidate = $date->setDay($effectiveDueDay);

        if ($candidate->greaterThan($date)) {
            return $candidate;
        }

        $nextMonth = $date->addMonthNoOverflow();

        return $nextMonth->setDay(min($dueDay, $nextMonth->daysInMonth))->startOfDay();
    }

    public function previousClosingDate(CarbonInterface $closingDate, int $closingDay): CarbonImmutable
    {
        $previousMonth = CarbonImmutable::instance($closingDate)->subMonthNoOverflow();

        return $previousMonth->setDay(min($closingDay, $previousMonth->daysInMonth))->startOfDay();
    }
}
