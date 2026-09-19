(() => {
    'use strict';

    const significantCount = (value) => (value.match(/[0-9,.]/g) || []).length;
    const cursorForSignificant = (value, count) => {
        if (count <= 0) return 0;
        let seen = 0;
        for (let i = 0; i < value.length; i += 1) {
            if (/[0-9,]/.test(value[i])) seen += 1;
            if (seen >= count) return i + 1;
        }
        return value.length;
    };
    const format = (input, final = false) => {
        let value = input.replace(/\u00a0/g, ' ').replace(/\./g, ',');
        const commaAt = value.indexOf(',');
        let wholeSource = commaAt >= 0 ? value.slice(0, commaAt) : value;
        let fractionSource = commaAt >= 0 ? value.slice(commaAt + 1) : '';
        let whole = wholeSource.replace(/\D/g, '').slice(0, 12);
        let fraction = fractionSource.replace(/\D/g, '').slice(0, 2);
        if (whole === '' && (commaAt >= 0 || fraction !== '')) whole = '0';
        whole = whole.replace(/^0+(?=\d)/, '') || (input !== '' ? '0' : '');
        const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        if (grouped === '') return '';
        if (commaAt >= 0 || final) {
            if (final) fraction = fraction.padEnd(2, '0');
            return `${grouped},${fraction}`;
        }
        return grouped;
    };

    const init = (root = document) => {
        root.querySelectorAll('[data-money-input]').forEach((input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.moneyReady === '1') return;
        input.dataset.moneyReady = '1';
        input.value = format(input.value, false);
        input.addEventListener('keydown', (event) => {
            if (event.ctrlKey || event.metaKey || event.altKey) return;
            if (['Backspace','Delete','Tab','Escape','Enter','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(event.key)) return;
            if (/^\d$/.test(event.key)) return;
            if ((event.key === ',' || event.key === '.') && !input.value.includes(',')) return;
            event.preventDefault();
        });
        input.addEventListener('input', () => {
            const cursor = input.selectionStart ?? input.value.length;
            const significant = significantCount(input.value.slice(0, cursor));
            const next = format(input.value, false);
            if (next !== input.value) {
                input.value = next;
                const nextCursor = cursorForSignificant(next, significant);
                input.setSelectionRange(nextCursor, nextCursor);
            }
        });
        input.addEventListener('blur', () => {
            if (input.value !== '') input.value = format(input.value, true);
        });
        });
    };

    init();
    document.addEventListener('tms:dynamic-fields', (event) => {
        const root = event.detail && event.detail.root instanceof Element ? event.detail.root : document;
        init(root);
    });
})();
