// Kilometers use two decimal places and stay in decimal-point
// notation in form data, independently of their Brazilian display format.
export function normalizeKilometers(value) {
    const text = String(value ?? '').trim();
    if (text === '') return '';
    if (!/^\d+(?:\.\d{1,3})?$/.test(text)) return null;
    const [integer, fraction = ''] = text.split('.');
    const whole = integer.replace(/^0+(?=\d)/, '');
    if (whole.length > 7) return null;
    // The database returns three places; round existing draft values to two.
    const decimals = fraction.padEnd(3, '0');
    const hundredths = Number(whole) * 100 + Number(decimals.slice(0, 2)) + (Number(decimals[2]) >= 5 ? 1 : 0);
    const digits = String(hundredths).padStart(3, '0');
    return `${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

export function formatKilometers(value) {
    return (normalizeKilometers(value) ?? '').replace('.', ',');
}

export function kilometersFromTyping(text) {
    if (text === '') return '';
    if (!/^[\d.,\s]+$/.test(text)) return null;
    const digits = text.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    if (!digits) return '';
    if (digits.length > 9) return null;
    const padded = digits.padStart(3, '0');
    return `${padded.slice(0, -2)}.${padded.slice(-2)}`;
}

export function kilometersFromPaste(text) {
    const compact = text.trim().replace(/\s/g, '');
    if (compact.includes(',')) {
        if (!/^\d+(?:\.\d{3})*,\d{1,2}$/.test(compact)) return null;
        return normalizeKilometers(compact.replace(/\./g, '').replace(',', '.'));
    }
    if (compact !== '' && !/^\d+(?:\.\d{1,2})?$/.test(compact)) return null;
    return normalizeKilometers(compact);
}
