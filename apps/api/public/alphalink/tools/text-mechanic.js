(function (window, document, navigator) {
    'use strict';

    function naturalSort(a, b) {
        const re = /(^([+\-]?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?(?=\D|\s|$))|^0x[\da-fA-F]+$|\d+)/g;
        const sre = /^\s+|\s+$/g;
        const snre = /\s+/g;
        const dre = /(^([\w ]+,?[\w ]+)?[\w ]+,?[\w ]+\d+:\d+(:\d+)?[\w ]?|^\d{1,4}[\/\-]\d{1,4}[\/\-]\d{1,4}|^\w+, \w+ \d+, \d{4})/;
        const hre = /^0x[0-9a-f]+$/i;
        const ore = /^0/;
        const insensitive = true;
        const normalize = (value) => (insensitive && String(value).toLowerCase() || String(value)).replace(sre, '');
        const x = normalize(a);
        const y = normalize(b);
        const xN = x.replace(re, '\0$1\0').replace(/\0$/, '').replace(/^\0/, '').split('\0');
        const yN = y.replace(re, '\0$1\0').replace(/\0$/, '').replace(/^\0/, '').split('\0');
        const xD = parseInt(x.match(hre), 16) || (xN.length !== 1 && Date.parse(x));
        const yD = parseInt(y.match(hre), 16) || (xD && y.match(dre) && Date.parse(y)) || null;
        const normChunk = (value, length) => ((!value.match(ore) || length === 1) && parseFloat(value)) || value.replace(snre, ' ').replace(sre, '') || 0;

        if (yD) {
            if (xD < yD) {
                return -1;
            }
            if (xD > yD) {
                return 1;
            }
        }

        for (let index = 0, max = Math.max(xN.length, yN.length); index < max; index++) {
            const xChunk = normChunk(xN[index] || '', xN.length);
            const yChunk = normChunk(yN[index] || '', yN.length);

            if (isNaN(xChunk) !== isNaN(yChunk)) {
                return isNaN(xChunk) ? 1 : -1;
            }
            if (/[^\x00-\x80]/.test(xChunk + yChunk) && xChunk.localeCompare) {
                const comparison = xChunk.localeCompare(yChunk);
                return comparison / Math.abs(comparison);
            }
            if (xChunk < yChunk) {
                return -1;
            }
            if (xChunk > yChunk) {
                return 1;
            }
        }

        return 0;
    }

    function normalizedLines(text) {
        return text.replace(/\r\n/g, '\n').split('\n');
    }

    function sortAscending(text) {
        return normalizedLines(text).sort(naturalSort).filter((line) => line !== '').join('\n');
    }

    function sortDescending(text) {
        return normalizedLines(text).reverse().filter((line) => line !== '').join('\n');
    }

    function sortByLength(text) {
        return normalizedLines(text)
            .sort((a, b) => a.toString().length - b.toString().length)
            .filter((line) => line !== '')
            .join('\n');
    }

    function sortRandom(text, random = Math.random) {
        const lines = normalizedLines(text);

        for (let index = lines.length - 1; index > 0; index--) {
            const target = Math.floor(random() * (index + 1));
            [lines[index], lines[target]] = [lines[target], lines[index]];
        }

        return lines.filter((line) => line !== '').join('\n');
    }

    function removeDuplicates(text) {
        return [...new Set(text.split('\n'))].join('\n');
    }

    function extractIps(text) {
        const ips = text.match(/(((?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?\.){3}(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?))))/gi) || [];
        return [...new Set(ips)].join('\n');
    }

    const operations = Object.freeze({
        sortAscending,
        sortDescending,
        sortByLength,
        sortRandom,
        removeDuplicates,
        extractIps,
    });

    function initialize(documentRef = document, navigatorRef = navigator) {
        const textarea = documentRef.getElementById('text');
        if (!textarea) {
            return;
        }

        const status = documentRef.getElementById('text-mechanic-status');
        const announce = (message) => {
            if (status) {
                status.textContent = message;
            }
        };
        const bind = (id, action) => {
            const button = documentRef.getElementById(id);
            if (button) {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    action();
                });
            }
        };
        const fallbackCopy = () => {
            if (typeof documentRef.execCommand !== 'function') {
                return false;
            }

            const previous = documentRef.activeElement;
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const direction = textarea.selectionDirection;
            textarea.focus();
            textarea.select();
            const copied = documentRef.execCommand('copy');
            if (start !== null && end !== null) {
                textarea.setSelectionRange(start, end, direction || 'none');
            }
            previous?.focus?.();

            return copied;
        };

        bind('asc', () => { textarea.value = sortAscending(textarea.value); });
        bind('desc', () => { textarea.value = sortDescending(textarea.value); });
        bind('length', () => { textarea.value = sortByLength(textarea.value); });
        bind('random', () => { textarea.value = sortRandom(textarea.value); });
        bind('rduplicate', () => { textarea.value = removeDuplicates(textarea.value); });
        bind('ips', () => { textarea.value = extractIps(textarea.value); });
        bind('sall', () => textarea.select());
        bind('clear', () => { textarea.value = ''; });
        bind('copy', () => {
            const copy = navigatorRef?.clipboard?.writeText;
            if (typeof copy === 'function') {
                try {
                    Promise.resolve(copy.call(navigatorRef.clipboard, textarea.value))
                        .then(() => announce('Copied text to the clipboard.'))
                        .catch(() => announce(fallbackCopy() ? 'Copied text to the clipboard.' : 'Copy failed. Select the text and copy it manually.'));
                } catch {
                    announce(fallbackCopy() ? 'Copied text to the clipboard.' : 'Copy failed. Select the text and copy it manually.');
                }
                return;
            }

            announce(fallbackCopy() ? 'Copied text to the clipboard.' : 'Copy failed. Select the text and copy it manually.');
        });
    }

    window.AlphaLinkTextMechanic = Object.freeze({ operations, initialize });

    if (typeof document !== 'undefined') {
        initialize(document, typeof navigator === 'undefined' ? null : navigator);
    }
})(window, document, typeof navigator === 'undefined' ? null : navigator);
