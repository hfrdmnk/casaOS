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
