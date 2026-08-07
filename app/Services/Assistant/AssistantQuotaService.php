<?php

namespace App\Services\Assistant;

use App\Models\AiMonthlyUsage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssistantQuotaService
{
    /** @return array<string, mixed> */
    public function status(Tenant $tenant, User $user): array
    {
        $period = now()->startOfMonth()->toDateString();
        $tenantUsed = (int) AiMonthlyUsage::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('period', $period)
            ->sum('total_tokens');
        $userUsed = (int) AiMonthlyUsage::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereDate('period', $period)
            ->value('total_tokens');
        $membershipLimit = $tenant->memberships()
            ->where('user_id', $user->id)
            ->value('ai_monthly_token_limit');
        $tenantLimit = $this->effectiveLimit(
            $tenant->ai_monthly_token_limit,
            config('services.openai.tenant_monthly_token_limit')
        );
        $userLimit = $this->effectiveUserLimit(
            $membershipLimit,
            config('services.openai.user_monthly_token_limit')
        );
        $tenantExhausted = $tenantLimit !== null && $tenantUsed >= $tenantLimit;
        $userExhausted = $userLimit !== null && $userUsed >= $userLimit;

        return [
            'period' => now()->format('m/Y'),
            'allowed' => ! $tenantExhausted && ! $userExhausted,
            'exhausted_by' => $tenantExhausted ? 'tenant' : ($userExhausted ? 'user' : null),
            'tenant' => $this->scopeStatus($tenantUsed, $tenantLimit),
            'user' => $this->scopeStatus($userUsed, $userLimit),
        ];
    }

    /** @param array<string, mixed> $usage */
    public function record(Tenant $tenant, User $user, array $usage): array
    {
        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));
        $totalTokens = max(0, (int) ($usage['total_tokens'] ?? ($inputTokens + $outputTokens)));
        $cachedTokens = max(0, (int) data_get($usage, 'input_tokens_details.cached_tokens', 0));

        if ($totalTokens === 0) {
            return $this->status($tenant, $user);
        }

        DB::transaction(function () use ($tenant, $user, $inputTokens, $cachedTokens, $outputTokens, $totalTokens): void {
            $period = now()->startOfMonth()->toDateString();
            $row = AiMonthlyUsage::query()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'period' => $period,
            ]);

            $row = AiMonthlyUsage::query()
                ->whereKey($row->id)
                ->lockForUpdate()
                ->firstOrFail();

            AiMonthlyUsage::query()->whereKey($row->id)->incrementEach([
                'input_tokens' => $inputTokens,
                'cached_input_tokens' => $cachedTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $totalTokens,
                'requests_count' => 1,
            ]);
        });

        return $this->status($tenant, $user);
    }

    /** @return array<string, int|float|null> */
    private function scopeStatus(int $used, ?int $limit): array
    {
        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $used),
            'percentage' => $limit === null ? null : min(100, round(($used / max(1, $limit)) * 100, 1)),
        ];
    }

    private function effectiveLimit(mixed $configured, mixed $fallback): ?int
    {
        $limit = $configured !== null ? (int) $configured : (int) $fallback;

        return $limit > 0 ? $limit : null;
    }

    private function effectiveUserLimit(mixed $configured, mixed $ceiling): ?int
    {
        $maximum = $this->effectiveLimit(null, $ceiling);
        $requested = $this->effectiveLimit($configured, $ceiling);

        if ($maximum === null) {
            return $requested;
        }

        return $requested === null ? $maximum : min($requested, $maximum);
    }
}
