/**
 * Convert an exact decimal string into scaled BigInt units without floats.
 */
export function parseDecimalToScaledBigInt(value: string, scale = 12): bigint {
    const trimmed = value.trim();
    const isNegative = trimmed.startsWith('-');
    const unsigned = trimmed.replace(/^[-+]/, '');
    const [wholePart = '0', fractionPart = ''] = unsigned.split('.');
    const paddedFraction = (fractionPart + '0'.repeat(scale)).slice(0, scale);
    const scaled =
        BigInt(wholePart || '0') * 10n ** BigInt(scale) +
        BigInt(paddedFraction || '0');

    return isNegative ? -scaled : scaled;
}

/**
 * Format an exact decimal USD string for display without using floats.
 */
export function formatUsd(decimalString: string, minDecimals = 2): string {
    const trimmed = decimalString.trim();
    const isNegative = trimmed.startsWith('-');
    const unsigned = trimmed.replace(/^[-+]/, '');
    const [wholePart = '0', fractionPart = ''] = unsigned.split('.');
    const strippedTrailingZeros = fractionPart.replace(/0+$/, '');
    const visibleFraction =
        strippedTrailingZeros.length > minDecimals
            ? strippedTrailingZeros
            : strippedTrailingZeros.padEnd(minDecimals, '0');

    return `${isNegative ? '-' : ''}$${wholePart || '0'}.${visibleFraction}`;
}
