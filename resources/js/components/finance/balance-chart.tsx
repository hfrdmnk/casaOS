import { Liveline } from 'liveline';
import { useState } from 'react';
import { useAppearance } from '@/hooks/use-appearance';
import { formatAmount } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { BalancePoint } from '@/types/finance';

const daySecs = 86400;

const ranges = [
    { label: '1M', days: 30, period: 'past month' },
    { label: '3M', days: 91, period: 'past 3 months' },
    { label: '1Y', days: 365, period: 'past year' },
    { label: 'All', days: null, period: null },
];

/**
 * Liveline only parses hex and rgb colours. These are the colours of
 * `upClass` and `downClass` (emerald-700/400, --destructive-foreground).
 */
const colors = {
    light: { up: '#007a55', down: '#d73431' },
    dark: { up: '#00d492', down: '#f2716a' },
};

const upClass = 'text-emerald-700 dark:text-emerald-400';
const downClass = 'text-destructive-foreground';

/**
 * Liveline keeps 5% of the window right of the newest point for its badge, so
 * a window of secs / 0.95 shows exactly `secs` of history.
 */
const visibleShare = 0.95;

const wholeFrancs = new Intl.NumberFormat('de-CH', {
    maximumFractionDigits: 0,
});

function localTime(date: string): number {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).getTime() / 1000;
}

function formatDay(time: number, year?: '2-digit' | 'numeric'): string {
    return new Date(time * 1000).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year,
    });
}

/**
 * A balance over time with a time range picker. The line is green when the
 * balance at the end of the range is at least the one at its start, red when
 * it is lower.
 */
export function BalanceChart({ history }: { history: BalancePoint[] }) {
    const { resolvedAppearance } = useAppearance();
    const points = history.map((point) => ({
        time: localTime(point.date),
        value: point.balance / 100,
    }));
    const first = points[0]?.time ?? 0;
    const last = points[points.length - 1]?.time ?? 0;
    const options = ranges.filter(
        (range) => range.days === null || range.days * daySecs < last - first,
    );
    const [selected, setSelected] = useState(
        options.some((range) => range.label === '1Y') ? '1Y' : 'All',
    );

    if (points.length < 2) {
        return null;
    }

    const range =
        options.find((option) => option.label === selected) ??
        options[options.length - 1];
    const now = Date.now() / 1000;
    const start = range.days === null ? first : now - range.days * daySecs;
    const startIndex = points.findLastIndex((point) => point.time <= start);
    const startBalance = history[Math.max(startIndex, 0)].balance;
    const endBalance = history[history.length - 1].balance;
    const change = endBalance - startBalance;
    const color = colors[resolvedAppearance][change < 0 ? 'down' : 'up'];
    const year = now - start > 365 * daySecs ? '2-digit' : undefined;

    return (
        <div data-test="balance-chart">
            <div className="flex min-h-7 items-center justify-between gap-3">
                <p
                    className={cn(
                        'text-[13px] tabular-nums',
                        change < 0 ? downClass : upClass,
                    )}
                >
                    {change >= 0 && '+'}
                    {formatAmount(change)}
                    {startBalance > 0 && (
                        <>
                            {' '}
                            ({change >= 0 && '+'}
                            {((change / startBalance) * 100).toFixed(1)}%)
                        </>
                    )}{' '}
                    <span className="text-faint">
                        {range.period ?? `since ${formatDay(first, 'numeric')}`}
                    </span>
                </p>
                {options.length > 1 && (
                    <div
                        role="group"
                        aria-label="Time range"
                        className="flex gap-0.5"
                    >
                        {options.map((option) => (
                            <button
                                key={option.label}
                                type="button"
                                aria-pressed={option.label === range.label}
                                onClick={() => setSelected(option.label)}
                                className={cn(
                                    'h-7 rounded-md px-2 text-[13px] text-faint tabular-nums transition-colors duration-150 outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring',
                                    option.label === range.label &&
                                        'bg-accent text-foreground',
                                )}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>
            <div className="mt-3 h-56">
                <Liveline
                    data={points}
                    value={points[points.length - 1].value}
                    theme={resolvedAppearance}
                    color={color}
                    window={(now - start) / visibleShare}
                    momentum={false}
                    formatValue={(value) => wholeFrancs.format(value)}
                    formatTime={(time) => formatDay(time, year)}
                />
            </div>
        </div>
    );
}
