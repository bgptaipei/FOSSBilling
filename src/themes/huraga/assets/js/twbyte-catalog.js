document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('tb-catalog');
    if (!root) {
        return;
    }
    const cards = Array.from(root.querySelectorAll('[data-tb-product]'));
    const state = { type: 'all', region: 'all', sort: 'price-asc', view: 'cards' };

    function apply() {
        const visible = cards.filter(function (card) {
            const typeOk = state.type === 'all' || card.dataset.type === state.type;
            const regionOk = state.region === 'all' || card.dataset.region === state.region;
            return typeOk && regionOk;
        });
        visible.sort(function (a, b) {
            const left = Number(a.dataset.price || 0);
            const right = Number(b.dataset.price || 0);
            return state.sort === 'price-desc' ? right - left : left - right;
        });
        const grid = root.querySelector('[data-tb-grid]');
        const tableBody = root.querySelector('[data-tb-table]');
        if (tableBody) {
            tableBody.replaceChildren();
        }
        visible.forEach(function (card) {
            card.classList.remove('tb-hidden');
            if (state.view === 'cards' && grid) {
                grid.appendChild(card);
            }
            if (state.view === 'table' && tableBody) {
                const row = card.querySelector('[data-tb-row]');
                if (row) {
                    tableBody.appendChild(row.cloneNode(true));
                }
            }
        });
        cards.forEach(function (card) {
            if (!visible.includes(card)) {
                card.classList.add('tb-hidden');
            }
        });
        root.querySelector('[data-tb-grid]').classList.toggle('tb-hidden', state.view !== 'cards');
        root.querySelector('[data-tb-table-wrap]').classList.toggle('tb-hidden', state.view !== 'table');
        const count = root.querySelector('[data-tb-count]');
        if (count) {
            count.textContent = String(visible.length);
        }
        const chips = root.querySelector('[data-tb-chips]');
        if (chips) {
            const parts = [];
            if (state.type !== 'all') {
                parts.push(state.type === 'dedicated' ? '独立服务器' : '云服务器');
            }
            if (state.region !== 'all') {
                parts.push(state.region);
            }
            chips.textContent = parts.length ? parts.join('，') : '全部';
        }
    }

    root.querySelectorAll('[data-tb-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            const kind = button.dataset.tbFilter;
            state[kind] = button.dataset.value;
            root.querySelectorAll('[data-tb-filter="' + kind + '"]').forEach(function (item) {
                item.classList.toggle('is-on', item === button);
            });
            apply();
        });
    });
    const sort = root.querySelector('[data-tb-sort]');
    if (sort) {
        sort.addEventListener('change', function () {
            state.sort = sort.value;
            apply();
        });
    }
    root.querySelectorAll('[data-tb-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            state.view = button.dataset.tbView;
            apply();
        });
    });
    const clear = root.querySelector('[data-tb-clear]');
    if (clear) {
        clear.addEventListener('click', function () {
            state.type = 'all';
            state.region = 'all';
            root.querySelectorAll('[data-tb-filter]').forEach(function (button) {
                button.classList.toggle('is-on', button.dataset.value === 'all');
            });
            apply();
        });
    }
    apply();
});
