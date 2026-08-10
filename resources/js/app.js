import './bootstrap';
import Alpine from 'alpinejs'

const baseUrl = document.querySelector('base')?.getAttribute('href') || '/';

Alpine.data('eqsearch', (initialQuery = '') => ({
    query: initialQuery,
    results: [],
    loading: false,

    async load() {
        const cleanQuery = this.query.replace(/[^a-zA-Z0-9 -_.'`]/g, '');
        this.query = cleanQuery;

        if (cleanQuery.length < 2) {
            this.results = [];
            this.loading = false;
            return;
        }

        this.loading = true;

        try {
            const res = await fetch(`${baseUrl}search/suggest?q=${encodeURIComponent(cleanQuery)}`);
            const data = await res.json();
            this.results = data;
        } catch (e) {

        } finally {
            this.loading = false;
        }
    }
}));

Alpine.data('itemDrops', (itemId) => ({
    itemId,
    loading: true,
    drops: [],
    top_npcs: [],
    zoneList: [],
    selectedZone: '',
    async load() {
        this.loading = true;
        try {
            const res = await fetch(`${baseUrl}items/drops_by_zone/${this.itemId}`);
            const data = await res.json();
            this.drops = data.drops_by_zone;
            this.top_npcs = data.top_npcs;

            this.zoneList = data.drops_by_zone.map(z => ({
                key: `${z.zone}:${z.version}`,
                label: `${z.zone_name} (${z.zone})`,
            }));
        } catch (e) {
            console.error('error loading npc droppers:', e);
        } finally {
            this.loading = false;
        }
    },
    scrollToZone(event) {
        if (event && event.preventDefault) event.preventDefault();
        if (!this.selectedZone) return;

        const targetId = this.selectedZone === 'zone-top-npcs' ? 'zone-top-npcs' : `zone-${this.selectedZone}`;
        const container = document.querySelector('.drops-by-zone');
        const el = document.getElementById(targetId);

        if (container && el) {
            container.scrollTo({
                top: el.offsetTop - container.offsetTop - 5,
                behavior: 'smooth',
            });
        }
    }
}));

Alpine.data('spellLevelSticky', () => ({
    show: true,
    init() {
        const sentinel = document.getElementById('extra');
        if (!sentinel) return;

        const observer = new IntersectionObserver(([entry]) => {
            const sentinelAboveViewport = entry.boundingClientRect.top < 0;
            this.show = !sentinelAboveViewport;
        }, {
            root: null,
            threshold: 0.01,
        });

        observer.observe(sentinel);
    }
}));

Alpine.store('otherSpells', {
    page: 1,
    spells: '',
    exclude: '',
    load(excludeIds) {
        this.exclude = excludeIds;
        this.loadMore(1);
    },
    loadMore(page = 1) {
        this.page = page;
        let params = new URLSearchParams(window.location.search);
        params.set('page', page);
        params.set('exclude', this.exclude);

        return fetch(`${baseUrl}spells/other?${params.toString()}`)
            .then(res => res.text())
            .then(html => {
                this.spells = html;
            });
    }
});

Alpine.store('itemSearch', {
    open: false,
    toggle() {
        this.open = !this.open;
        localStorage.setItem('item_search', this.open);
    },
    init() {
        this.open = localStorage.getItem('item_search') === 'true';
    }
});

// Which columns the item results table leaves out. Stored as the *hidden* set
// rather than the visible one, so a column added later shows up for everyone
// instead of being invisible to anyone with a saved list. Kept in localStorage
// rather than the query string: it is a preference, not part of the search, and
// every sort and paginate link would otherwise have to carry it.
Alpine.store('itemColumns', {
    hidden: [],

    init() {
        try {
            const stored = JSON.parse(localStorage.getItem('item_columns_hidden'));
            this.hidden = Array.isArray(stored) ? stored : [];
        } catch (e) {
            this.hidden = [];
        }
    },

    visible(column) {
        return !this.hidden.includes(column);
    },

    toggle(column) {
        this.hidden = this.visible(column)
            ? [...this.hidden, column]
            : this.hidden.filter(c => c !== column);

        this.save();
    },

    showAll() {
        this.hidden = [];
        this.save();
    },

    save() {
        localStorage.setItem('item_columns_hidden', JSON.stringify(this.hidden));
    },

    // Reassigning style.display rather than toggling a class: '' gives the cell
    // back to whatever responsive utilities it was rendered with, so a column
    // that is only shown at lg stays that way once it is switched back on.
    apply(root) {
        // Read outside the loop so x-effect still registers the dependency on a
        // page whose table rendered no toggleable cells at all.
        const hidden = this.hidden;

        root.querySelectorAll('[data-col]').forEach(cell => {
            cell.style.display = hidden.includes(cell.dataset.col) ? 'none' : '';
        });
    },
});

Alpine.store('tooltip', {
    content: '',
    visible: false,
    cache: new Map(),
    tooltipEl: null,

    async loadTooltip(url, triggerEl, event) {
        if (!triggerEl) return;
        if (event && event.preventDefault) event.preventDefault();

        this.loadingUrl = url;
        this.tooltipEl = document.getElementById('global-tooltip');

        const effectsOnly = triggerEl.dataset.effectsOnly === '1';
        if (effectsOnly) {
            url += '?effects-only=1';
        }

        if (this.cache.has(url)) {
            this.content = this.cache.get(url);
            this.loadingUrl = null;
        } else {
            try {
                const response = await fetch(url);
                const data = await response.json();
                this.cache.set(url, data.html);
                this.content = data.html;
            } catch (err) {
                this.content = '<div class="text-error">Failed to load tooltip.</div>';
            }
            this.loadingUrl = null;
        }

        this.visible = true;

        requestAnimationFrame(() => {
            this.positionTooltip(event, triggerEl);
        });
    },

    hideTooltip() {
        this.visible = false;
    },

    positionTooltip(e, triggerEl) {
        const tooltip = this.tooltipEl;
        if (!tooltip || !triggerEl) return;

        tooltip.style.visibility = 'hidden';
        tooltip.style.display = 'block';

        const tooltipHeight = tooltip.offsetHeight;
        const tooltipWidth = tooltip.offsetWidth;
        const rect = triggerEl.getBoundingClientRect();
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;

        let top = rect.top + rect.height / 2 - tooltipHeight / 2 + scrollY;
        let left;

        const spaceRight = window.innerWidth - (rect.right + 10);
        const spaceLeft = rect.left - 10;

        if (spaceRight >= tooltipWidth) {
            left = rect.right + 10 + scrollX;
        } else if (spaceLeft >= tooltipWidth) {
            left = rect.left - tooltipWidth - 10 + scrollX;
        } else {
            left = scrollX + rect.left + (rect.width / 2) - (tooltipWidth / 2);
        }

        // Vertical bounds
        const maxBottom = scrollY + window.innerHeight - 10;
        if (top + tooltipHeight > maxBottom) {
            top = maxBottom - tooltipHeight;
        }
        if (top < scrollY + 10) {
            top = scrollY + 10;
        }

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
        tooltip.style.visibility = 'visible';
    }
});

// Column sorting for the tables a page hands over whole -- the zone and NPC
// tabs, faction lists, pets, spells. The paginated searches sort in SQL instead
// (their headers are @sortablelink), because there a page of fifty rows is not
// the result set; here it is.
//
// Markup contract: `data-sortable` on the table, `data-sort` on every header
// that should be clickable ("number" to compare numerically, anything else
// compares as text), `data-sort-value` on any cell whose text does not sort the
// way it reads (thousands separators, "6m 40s", a badge saying Yes), and
// `data-sort-group` on a separator row -- rows sort within their group rather
// than across the whole table, so a grouped list keeps its groups.
const SORT_ICONS = {
    asc: 'sort-icon sort-ascending',
    desc: 'sort-icon sort-descending',
    none: 'sort-icon sort',
};

function sortableHeaderCells(table) {
    const row = table.querySelector('thead tr');

    return row ? Array.from(row.cells) : [];
}

function cellSortValue(cell, numeric) {
    if (!cell) {
        return null;
    }

    const raw = (cell.dataset.sortValue ?? cell.textContent).trim();

    if (!numeric) {
        return raw === '' ? null : raw;
    }

    const number = parseFloat(raw.replace(/[^\d.+-]/g, ''));

    return Number.isFinite(number) ? number : null;
}

function compareRows(a, b, numeric, sign) {
    // An empty cell is not a result either way, so blanks sit at the bottom
    // whichever way the column points -- what the item search does with the
    // items it has no era or potency for.
    if (a.value === null || b.value === null) {
        if (a.value === b.value) {
            return a.position - b.position;
        }

        return a.value === null ? 1 : -1;
    }

    const result = numeric
        ? a.value - b.value
        : String(a.value).localeCompare(String(b.value), undefined, { numeric: true, sensitivity: 'base' });

    return result === 0 ? a.position - b.position : result * sign;
}

function rowGroups(body) {
    const groups = [];
    let current = [];

    for (const row of Array.from(body.rows)) {
        if (row.hasAttribute('data-sort-group')) {
            if (current.length) {
                groups.push(current);
            }

            current = [];
            continue;
        }

        current.push(row);
    }

    if (current.length) {
        groups.push(current);
    }

    return groups;
}

function sortTable(table, headers, index) {
    const header = headers[index];
    const direction = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
    const numeric = header.dataset.sort === 'number';
    const sign = direction === 'asc' ? 1 : -1;

    headers.forEach(cell => {
        delete cell.dataset.sortDirection;
        const icon = cell.querySelector('.sort-icon');

        if (icon) {
            icon.className = SORT_ICONS.none;
        }
    });

    header.dataset.sortDirection = direction;
    header.querySelector('.sort-icon').className = SORT_ICONS[direction];

    Array.from(table.tBodies).forEach(body => {
        rowGroups(body).forEach(rows => {
            // Everything in the group is re-inserted ahead of whatever followed
            // it, so a group lands back where it started rather than at the end
            // of the table.
            const anchor = rows[rows.length - 1].nextSibling;

            rows
                .map((row, position) => ({
                    row,
                    position,
                    value: cellSortValue(row.cells[index], numeric),
                }))
                .sort((a, b) => compareRows(a, b, numeric, sign))
                .forEach(entry => body.insertBefore(entry.row, anchor));
        });
    });
}

function initSortableTable(table) {
    const headers = sortableHeaderCells(table);

    headers.forEach((header, index) => {
        if (!header.hasAttribute('data-sort')) {
            return;
        }

        // Built here rather than in the template so every sortable header is
        // one attribute in Blade, and so it comes out as the same anchor-then-
        // icon pair @sortablelink renders on the search pages.
        const label = document.createElement('button');
        label.type = 'button';
        label.className = 'link-accent link-hover cursor-pointer';
        label.append(...header.childNodes);

        const icon = document.createElement('i');
        icon.className = SORT_ICONS.none;

        header.replaceChildren(label, ' ', icon);
        header.classList.add('cursor-pointer');
        header.addEventListener('click', () => sortTable(table, headers, index));
    });
}

window.Alpine = Alpine
Alpine.start()

document.addEventListener("DOMContentLoaded", function () {
    const logo = document.getElementById('eqemu-desktop-logo');
    const navbartrigger = document.getElementById('navbar-trigger');

    const observer = new IntersectionObserver(([entry]) => {
        if (entry.intersectionRatio === 0) {
            logo.classList.add('stuck');
        } else {
            logo.classList.remove('stuck');
        }
    });

    observer.observe(navbartrigger);

    // faction select
    const select = document.getElementById('select-faction');
    if (select) {
        select.addEventListener('change', (e) => {
            const value = e.target.value;
            if (value) {
                window.location.href = `${baseUrl}factions/${value}`;
            }
        });
    }

    // pet class select
    const petSelect = document.getElementById('select-pet-class');
    if (petSelect) {
        petSelect.addEventListener('change', (e) => {
            const value = e.target.value;
            if (value) {
                window.location.href = `${baseUrl}pets/${value}`;
            }
        });
    }

    document.querySelectorAll('table[data-sortable]').forEach(initSortableTable);

    // populate task reward column with something at least
    const rewardCol = document.querySelectorAll('td.task-rewards');
    rewardCol.forEach(function (reward) {
        if (reward.textContent.trim() === '') {
            reward.textContent = '-';
        }
    });
});

document.body.addEventListener('click', () => {
    Alpine.store('tooltip').hideTooltip();
});
