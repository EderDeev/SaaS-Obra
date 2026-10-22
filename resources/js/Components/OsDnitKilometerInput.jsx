import { formatKilometers, kilometersFromPaste, kilometersFromTyping } from '@/Support/osDnitKilometers';

export default function OsDnitKilometerInput({ value, onValueChange, ...props }) {
    return <input {...props} type="text" inputMode="numeric" placeholder="0,00"
        pattern="[0-9]{1,7},[0-9]{2}" maxLength={10} value={formatKilometers(value)}
        onChange={(event) => {
            const next = kilometersFromTyping(event.target.value);
            if (next !== null) onValueChange(next);
        }}
        onPaste={(event) => {
            event.preventDefault();
            const next = kilometersFromPaste(event.clipboardData.getData('text'));
            if (next !== null) onValueChange(next);
        }}
        onKeyDown={(event) => {
            if (event.key === 'Backspace' && value === '0.00'
                && event.currentTarget.selectionStart === event.currentTarget.value.length
                && event.currentTarget.selectionEnd === event.currentTarget.value.length) {
                event.preventDefault();
                onValueChange('');
            }
        }} />;
}
