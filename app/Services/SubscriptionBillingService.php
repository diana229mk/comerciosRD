<?php

namespace App\Services;

use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPlan;
use App\Models\Business;
use App\Models\SubscriptionInvoice;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Facturación de ComerciosRD (suscripciones), no ventas POS de los locales.
 */
class SubscriptionBillingService
{
    public function __construct(
        protected SeatLimitService $seats,
    ) {}

    public function ensureCurrentInvoice(Business $business, ?Carbon $month = null): ?SubscriptionInvoice
    {
        if (! $business->is_active) {
            return null;
        }

        $month = ($month ?? now())->copy()->startOfMonth();
        $periodEnd = $month->copy()->endOfMonth()->toDateString();
        $periodStart = $month->toDateString();

        $existing = SubscriptionInvoice::query()
            ->where('business_id', $business->id)
            ->whereDate('period_start', $periodStart)
            ->first();

        $amount = $this->seats->monthlyTotal($business);
        $plan = $this->seats->plan($business);
        $extra = $this->seats->extraSeats($business);
        $due = $month->copy()->startOfMonth();
        if ($business->created_at && $business->created_at->greaterThan($due)) {
            $due = $business->created_at->copy()->startOfDay();
        }

        if ($existing) {
            if ($existing->status === SubscriptionInvoiceStatus::Paid
                || $existing->status === SubscriptionInvoiceStatus::Cancelled) {
                return $existing;
            }

            $existing->forceFill([
                'plan' => $plan,
                'extra_seats' => $extra,
                'amount' => $amount,
                'period_end' => $periodEnd,
                'due_at' => $due->toDateString(),
                'status' => $due->isPast() && ! $due->isToday()
                    ? SubscriptionInvoiceStatus::Overdue
                    : SubscriptionInvoiceStatus::Pending,
            ])->save();

            return $existing->fresh();
        }

        return SubscriptionInvoice::query()->create([
            'business_id' => $business->id,
            'plan' => $plan,
            'extra_seats' => $extra,
            'amount' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_at' => $due->toDateString(),
            'status' => $due->isPast() && ! $due->isToday()
                ? SubscriptionInvoiceStatus::Overdue
                : SubscriptionInvoiceStatus::Pending,
        ]);
    }

    public function generateOpenInvoices(): int
    {
        $count = 0;
        Business::query()->where('is_active', true)->orderBy('id')
            ->chunkById(100, function ($businesses) use (&$count): void {
                foreach ($businesses as $business) {
                    $this->ensureCurrentInvoice($business);
                    $count++;
                }
            });

        $this->markOverdue();

        return $count;
    }

    public function markOverdue(): int
    {
        return SubscriptionInvoice::query()
            ->where('status', SubscriptionInvoiceStatus::Pending)
            ->whereDate('due_at', '<', now()->toDateString())
            ->update(['status' => SubscriptionInvoiceStatus::Overdue->value]);
    }

    public function markPaid(SubscriptionInvoice $invoice, ?Carbon $when = null): SubscriptionInvoice
    {
        $invoice->forceFill([
            'status' => SubscriptionInvoiceStatus::Paid,
            'paid_at' => $when ?? now(),
        ])->save();

        return $invoice->fresh();
    }

    public function expectedMonthly(): int
    {
        $total = 0;
        Business::query()->where('is_active', true)->each(function (Business $b) use (&$total): void {
            $total += $this->seats->monthlyTotal($b);
        });

        return $total;
    }

    public function collectedThisMonth(): int
    {
        return (int) SubscriptionInvoice::query()
            ->where('status', SubscriptionInvoiceStatus::Paid)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');
    }

    public function outstanding(): int
    {
        return (int) SubscriptionInvoice::query()
            ->open()
            ->sum('amount');
    }

    public function overdueAmount(): int
    {
        return (int) SubscriptionInvoice::query()
            ->where('status', SubscriptionInvoiceStatus::Overdue)
            ->sum('amount');
    }

    /**
     * @return Collection<string, array{label: string, businesses: int, monthly: int}>
     */
    public function byPlan(): Collection
    {
        $rows = collect();
        foreach (SubscriptionPlan::cases() as $plan) {
            $biz = Business::query()
                ->where('is_active', true)
                ->where('subscription_plan', $plan->value)
                ->get();
            $monthly = 0;
            foreach ($biz as $b) {
                $monthly += $this->seats->monthlyTotal($b);
            }
            $rows[$plan->value] = [
                'label' => $plan->label(),
                'businesses' => $biz->count(),
                'monthly' => $monthly,
            ];
        }

        return $rows;
    }
}
