// The shared "Hiện thế nào trên Google" part (<x-bs-admin::seo-fields>, Alpine.data 'bsSeoFields'):
// the length meters of the short description and the Google title and description, and the Google
// preview with its "Google cắt từ đây" mark. Everything is computed in the browser from the form's
// deferred `wire:model` values ($wire), so typing sends no request. The limits come from the server
// (App\Modules\Admin\Support\SeoFields, the one table) and the words from the language files.
//
// The card opens and closes on the header button and stays as the person left it, on every editor
// that uses the part (this browser only; a closed card opens when one of its fields has an error).

const OPEN_KEY = 'bs.admin.seoOpen';

function readOpen() {
    try {
        return window.localStorage.getItem(OPEN_KEY) === '1';
    } catch {
        return false;
    }
}

function writeOpen(open) {
    try {
        window.localStorage.setItem(OPEN_KEY, open ? '1' : '0');
    } catch {
        // Storage off (private window, blocked site data): the card still opens, just not remembered.
    }
}

// Characters as the server counts them (mb_strlen): code points, not UTF-16 units.
const chars = (value) => Array.from(String(value ?? ''));

const fill = (template, values) => String(template).replace(/:(display|note|lo|hi|cap|n)\b/g, (match, key) => (key in values ? String(values[key]) : match));

document.addEventListener('alpine:init', () => {
    window.Alpine.data('bsSeoFields', (config) => ({
        open: Boolean(config.open) || readOpen(),

        toggle() {
            this.open = !this.open;
            writeOpen(this.open);
        },

        value(key) {
            const model = config.models[key];

            return model ? String(this.$wire[model] ?? '') : '';
        },

        // The band of a length (empty · short · near · good · long · over), its plain words and the
        // bar's geometry in percent of `max`, exactly as the design's meter draws them.
        meter(field, value) {
            const limits = config.limits[field];
            const words = config.words;
            const n = chars(value).length;
            let band = 'over';
            if (n === 0) {
                band = 'empty';
            } else if (n < limits.min) {
                band = 'short';
            } else if (n < limits.best[0]) {
                band = 'near';
            } else if (n <= limits.best[1]) {
                band = 'good';
            } else if (n <= limits.max) {
                band = 'long';
            }
            const note = band === 'long' ? words.cut[field] : (words.notes[band] ?? '');
            let text;
            if (n === 0) {
                text = fill(words.empty, { lo: limits.best[0], hi: limits.best[1] });
            } else if (n > limits.display) {
                text = fill(words.over, { n, note });
            } else {
                text = fill(note ? words.countNote : words.count, { n, display: limits.display, note });
            }

            return {
                band,
                text,
                pct: Math.min(100, (n / limits.max) * 100),
                tick: Math.min(100, (limits.display / limits.max) * 100),
                lo: (limits.best[0] / limits.max) * 100,
                zw: ((limits.best[1] - limits.best[0]) / limits.max) * 100,
            };
        },

        get excerptMeter() {
            return this.meter('excerpt', this.value('excerpt'));
        },

        get titleMeter() {
            return this.meter('seoTitle', this.value('title'));
        },

        get descriptionMeter() {
            return this.meter('seoDesc', this.value('description'));
        },

        // What Google would show, split where it cuts the line: [shown, cut off].
        split(field, text) {
            const all = chars(text);
            const at = config.limits[field].display;

            return all.length > at ? [all.slice(0, at).join(''), all.slice(at).join('')] : [all.join(''), ''];
        },

        get previewTitle() {
            return this.split('seoTitle', this.value('title').trim() || this.value('pageTitle').trim() || config.words.emptyTitle);
        },

        get previewDescription() {
            const own = this.value('description').trim() || this.value('excerpt').replace(/\s+/g, ' ').trim();

            return this.split('seoDesc', own || config.words.emptyDescription);
        },
    }));
});
