/**
 * Trip map (Lambus style): pins per day in the day's colour and numbered in the planned order,
 * ideas in yellow, a route line per day, place search, places nearby and "tap the map to add".
 * Leaflet is loaded only when a trip map is on the page. The server data comes from the
 * data-groups attribute (updated by Livewire), so the map follows every change of the plan.
 */
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);

export default (texts, center) => {
    // Leaflet objects stay outside Alpine's reactivity (proxies break them).
    let L = null;
    let map = null;
    let pinLayer = null;
    let poiLayer = null;
    let tempMarker = null;
    let fitted = false;
    let pendingPlace = null;

    return {
        groups: [],
        hidden: [],
        query: '',
        results: [],
        searching: false,
        loadingNearby: false,
        ready: false,
        failed: false,

        async init() {
            this.groups = this.readGroups();

            try {
                L = (await import('leaflet')).default;
            } catch {
                this.failed = true;

                return;
            }

            const dark = document.documentElement.classList.contains('dark') || window.matchMedia('(prefers-color-scheme: dark)').matches;
            map = L.map(this.$refs.map, { zoomControl: false, tap: true }).setView(center ? [center.lat, center.lng] : [48.14, 11.58], center ? 13 : 4);
            L.control.zoom({ position: 'bottomright' }).addTo(map);
            L.tileLayer(`https://{s}.basemaps.cartocdn.com/${dark ? 'dark_all' : 'rastertiles/voyager'}/{z}/{x}/{y}{r}.png`, {
                attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> · © <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>',
                subdomains: 'abcd',
                maxZoom: 20,
            }).addTo(map);
            pinLayer = L.layerGroup().addTo(map);
            poiLayer = L.layerGroup().addTo(map);

            map.on('click', (event) => this.pick(event.latlng.lat, event.latlng.lng));
            this.$refs.map.addEventListener('click', (event) => this.popupAction(event));
            new MutationObserver(() => {
                this.groups = this.readGroups();
                this.render();
            }).observe(this.$refs.data, { attributes: true });

            this.ready = true;
            // The container may still change size while the page settles.
            setTimeout(() => {
                map.invalidateSize();
                this.render();
            }, 150);
        },

        readGroups() {
            try {
                return JSON.parse(this.$refs.data.dataset.groups || '[]');
            } catch {
                return [];
            }
        },

        bounds() {
            const box = map.getBounds();

            return [box.getSouth(), box.getWest(), box.getNorth(), box.getEast()];
        },

        pinIcon(color, label, number) {
            return L.divIcon({
                className: '',
                iconSize: null,
                iconAnchor: [13, 13],
                html: `<span class="trip-pin" style="--pin: ${color}"><span class="trip-pin-n">${number ?? '•'}</span><span class="trip-pin-t">${escapeHtml(label)}</span></span>`,
            });
        },

        render() {
            if (! map) {
                return;
            }

            pinLayer.clearLayers();
            const all = [];

            for (const group of this.groups) {
                if (this.hidden.includes(group.key) || group.pins.length === 0) {
                    continue;
                }

                const line = [];

                for (const pin of group.pins) {
                    const marker = L.marker([pin.lat, pin.lng], { icon: this.pinIcon(group.color, pin.title, group.key === 'ideas' ? pin.icon : pin.n), riseOnHover: true });
                    marker.bindPopup(`<div class="space-y-1"><p class="text-xs font-semibold" style="color:${group.color}">${escapeHtml(group.label)}</p><p class="text-sm font-semibold">${escapeHtml(pin.icon)} ${escapeHtml(pin.title)}</p><button type="button" data-trip-edit="${pin.id}" class="mt-1 rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white">${escapeHtml(texts.edit)}</button></div>`);
                    marker.addTo(pinLayer);
                    line.push([pin.lat, pin.lng]);
                    all.push([pin.lat, pin.lng]);
                }

                if (group.key !== 'ideas' && line.length > 1) {
                    L.polyline(line, { color: group.color, weight: 3, opacity: 0.7, dashArray: '6 8' }).addTo(pinLayer);
                }
            }

            if (! fitted && all.length > 0) {
                fitted = true;
                map.fitBounds(all, { paddingTopLeft: [30, 40], paddingBottomRight: [150, 40], maxZoom: 15 });
            }
        },

        fitAll() {
            const all = this.groups.filter((group) => ! this.hidden.includes(group.key)).flatMap((group) => group.pins.map((pin) => [pin.lat, pin.lng]));

            if (all.length > 0) {
                map.fitBounds(all, { paddingTopLeft: [30, 40], paddingBottomRight: [150, 40], maxZoom: 15 });
            } else if (center) {
                map.setView([center.lat, center.lng], 13);
            }
        },

        toggle(key) {
            this.hidden = this.hidden.includes(key) ? this.hidden.filter((item) => item !== key) : [...this.hidden, key];
            this.render();
        },

        dayOptions() {
            return this.groups.map((group) => `<option value="${escapeHtml(group.key)}">${escapeHtml(group.key === 'ideas' ? `💡 ${texts.idea}` : group.label)}</option>`).join('');
        },

        showPlace(place) {
            pendingPlace = place;

            if (tempMarker) {
                tempMarker.remove();
            }

            tempMarker = L.marker([place.lat, place.lng], { icon: this.pinIcon('#111827', place.name, '＋') }).addTo(map);
            tempMarker.bindPopup(`<div class="space-y-2" style="min-width: 190px">
                <p class="text-sm font-semibold">${escapeHtml(place.name)}</p>
                ${place.address ? `<p class="text-xs text-gray-500">${escapeHtml(place.address)}</p>` : ''}
                <select data-trip-day class="block w-full rounded-md border-gray-300 py-1 text-xs">${this.dayOptions()}</select>
                <button type="button" data-trip-add class="w-full rounded-full bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white">＋ ${escapeHtml(texts.add)}</button>
            </div>`).openPopup();
            tempMarker.on('popupclose', () => {
                tempMarker?.remove();
                tempMarker = null;
            });
        },

        async pick(lat, lng) {
            this.showPlace({ name: texts.searching, address: '', lat, lng, kind: 'sight' });
            const place = await this.$wire.placeAt(lat, lng);

            if (tempMarker && pendingPlace && pendingPlace.lat === lat) {
                this.showPlace(place ? { ...place, lat, lng } : { name: texts.pinned, address: '', lat, lng, kind: 'sight' });
            }
        },

        async popupAction(event) {
            const edit = event.target.closest('[data-trip-edit]');

            if (edit) {
                map.closePopup();
                this.$wire.editStop(Number(edit.dataset.tripEdit));

                return;
            }

            const add = event.target.closest('[data-trip-add]');

            if (add && pendingPlace) {
                const day = add.parentElement.querySelector('[data-trip-day]')?.value || 'ideas';
                const place = pendingPlace;
                pendingPlace = null;
                map.closePopup();
                await this.$wire.addPlace({ name: place.name === texts.searching ? texts.pinned : place.name, address: place.address, lat: place.lat, lng: place.lng, kind: place.kind }, day);
            }
        },

        async search() {
            const query = this.query.trim();

            if (query.length < 2) {
                this.results = [];

                return;
            }

            this.searching = true;
            this.results = await this.$wire.searchPlaces(query, this.bounds());
            this.searching = false;
        },

        choose(place) {
            this.results = [];
            this.query = '';
            map.setView([place.lat, place.lng], Math.max(map.getZoom(), 16));
            this.showPlace(place);
        },

        async nearby() {
            this.loadingNearby = true;
            const places = await this.$wire.placesNearby(this.bounds());
            this.loadingNearby = false;
            poiLayer.clearLayers();

            if (places.length === 0) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: texts.zoomIn } }));

                return;
            }

            for (const place of places) {
                const marker = L.marker([place.lat, place.lng], {
                    icon: L.divIcon({ className: '', iconSize: null, iconAnchor: [10, 10], html: `<span class="trip-poi">${escapeHtml(texts.icons[place.kind] ?? '📍')} ${escapeHtml(place.name)}</span>` }),
                });
                marker.on('click', () => this.showPlace(place));
                marker.addTo(poiLayer);
            }
        },
    };
};
