const cfg = { prefix: 'Rp ', thousands: '.', decimal: ',' };
const parseCurrency = (value) => {
    if (value === null || value === undefined || value === '') return null;
    if (typeof value === 'number') return value;
    
    let str = String(value).trim();
    if (str === '') return null;

    if (/^-?\d+(\.\d+)?$/.test(str)) {
        return parseFloat(str);
    }

    const thousands = cfg.thousands ?? ',';
    const decimal = cfg.decimal ?? '.';
    if (cfg.prefix) {
        const escaped = cfg.prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        str = str.replace(new RegExp(escaped, 'gi'), '');
    }
    str = str.replace(new RegExp('\\' + thousands, 'g'), '');
    str = str.replace(new RegExp('[^0-9\\' + decimal + '\\-]', 'g'), '');
    if (decimal !== '.') {
        str = str.replace(new RegExp('\\' + decimal, 'g'), '.');
    }
    const num = parseFloat(str);
    return isNaN(num) ? null : num;
};

console.log('7000000.00 ->', parseCurrency('7000000.00'));
console.log('Rp 7.000.000,00 ->', parseCurrency('Rp 7.000.000,00'));
console.log('7000000 ->', parseCurrency('7000000'));
console.log('0 ->', parseCurrency('0'));
console.log('"" ->', parseCurrency(''));
