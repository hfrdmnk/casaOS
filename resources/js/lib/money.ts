const formatter = new Intl.NumberFormat('de-CH', {
    style: 'currency',
    currency: 'CHF',
});

/**
 * Format signed Rappen as CHF, e.g. `CHF 1’234.50` or `CHF -6.70`.
 */
export function formatMoney(rappen: number): string {
    return formatter.format(rappen / 100);
}

const amountFormatter = new Intl.NumberFormat('de-CH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/**
 * Format signed Rappen without the currency, e.g. `1’234.50`, for columns
 * whose heading or total already says CHF.
 */
export function formatAmount(rappen: number): string {
    return amountFormatter.format(rappen / 100);
}
