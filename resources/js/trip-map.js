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
    let searchRun = 0;
    let areaLayer = null;
    let locationMarker = null;
    let nearbyTimer = null;

    return {
        groups: [],
        hidden: [],
        query: '',
        results: [],
        searching: false,
        searched: false,
        active: 0,
        showPois: false,
        locating: false,
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

            map = L.map(this.$refs.map, { zoomControl: false, tap: true }).setView(center ? [center.lat, center.lng] : [48.14, 11.58], center ? 13 : 4);
            L.control.zoom({ position: 'bottomright' }).addTo(map);
            // Tile servers want to know the site (the app itself sends no referrer): only the domain, no path.
            L.tileLayer(texts.tiles || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
                maxZoom: 19,
                referrerPolicy: 'strict-origin',
            }).addTo(map);
            pinLayer = L.layerGroup().addTo(map);
            poiLayer = L.layerGroup().addTo(map);

            map.on('click', (event) => this.pick(event.latlng.lat, event.latlng.lng));
            // With "places nearby" switched on, the places follow the visible map area.
            map.on('moveend', () => {
                if (this.showPois) {
                    clearTimeout(nearbyTimer);
                    nearbyTimer = setTimeout(() => this.loadNearby(), 350);
                }
            });
            this.$refs.map.addEventListener('click', (event) => this.popupAction(event));
            new MutationObserver(() => {
                this.groups = this.readGroups();
                this.render();
                this.drawArea();
            }).observe(this.$refs.data, { attributes: true });
            this.drawArea();

            this.ready = true;
            // The container may still change size while the page settles.
            setTimeout(() => {
                map.invalidateSize();
                this.render();
            }, 150);
        },

        /** The trip area as a light dashed frame. */
        drawArea() {
            let area = null;

            try {
                area = JSON.parse(this.$refs.data.dataset.area || 'null');
            } catch {
                area = null;
            }

            areaLayer?.remove();
            areaLayer = Array.isArray(area) && area.length === 4
                ? L.rectangle([[area[0], area[1]], [area[2], area[3]]], { color: '#6366f1', weight: 1.5, dashArray: '4 6', fill: false, interactive: false }).addTo(map)
                : null;
        },

        /** Jumps to where the device is (with permission) and offers to add the place. */
        locate() {
            if (!navigator.geolocation) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: texts.noLocation } }));

                return;
            }

            this.locating = true;
            navigator.geolocation.getCurrentPosition((position) => {
                this.locating = false;
                const point = [position.coords.latitude, position.coords.longitude];
                locationMarker?.remove();
                locationMarker = L.layerGroup([
                    L.circle(point, { radius: Math.min(position.coords.accuracy, 500), color: '#3b82f6', weight: 1, fillOpacity: 0.12, interactive: false }),
                    L.circleMarker(point, { radius: 8, color: '#ffffff', weight: 3, fillColor: '#3b82f6', fillOpacity: 1 })
                        .on('click', () => this.pick(point[0], point[1])),
                ]).addTo(map);
                map.setView(point, Math.max(map.getZoom(), 16));
            }, () => {
                this.locating = false;
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: texts.noLocation } }));
            }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
        },

        useAreaFromMap() {
            this.$wire.setMapArea(this.bounds());
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
                    marker.bindPopup(`<div class="space-y-1"><p class="text-xs font-semibold" style="color:${group.color}">${escapeHtml(group.label)}</p><p class="text-sm font-semibold">${escapeHtml(pin.icon)} ${escapeHtml(pin.title)}</p>${pin.leg ? `<p class="text-xs text-gray-500">↓ ${escapeHtml(pin.leg)} ${escapeHtml(texts.fromPrevious)}</p>` : ''}<button type="button" data-trip-edit="${pin.id}" class="mt-1 rounded-full bg-indigo-600 px-3 py-1 text-xs font-medium text-white">${escapeHtml(texts.edit)}</button></div>`);
                    marker.addTo(pinLayer);
                    line.push([pin.lat, pin.lng]);
                    all.push([pin.lat, pin.lng]);
                }

                if (group.key !== 'ideas' && group.line) {
                    // The way along the streets, with a light outline so it stays visible on every map.
                    L.polyline(group.line, { color: '#ffffff', weight: 7, opacity: 0.8, interactive: false }).addTo(pinLayer);
                    L.polyline(group.line, { color: group.color, weight: 4, opacity: 0.9 }).bindTooltip(group.total || '', { sticky: true }).addTo(pinLayer);
                } else if (group.key !== 'ideas' && line.length > 1) {
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
                ${place.info?.cuisine ? `<p class="text-xs text-gray-500">🍴 ${escapeHtml(place.info.cuisine)}</p>` : ''}
                ${place.info?.opening_hours ? `<p class="text-xs text-gray-500">🕘 ${escapeHtml(place.info.opening_hours)}</p>` : ''}
                ${place.info?.website ? `<p class="text-xs"><a href="${escapeHtml(place.info.website)}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 underline">${escapeHtml(texts.website)}</a></p>` : ''}
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
            const run = ++searchRun;
            this.active = 0;

            if (query.length < 2) {
                this.results = [];
                this.searching = false;
                this.searched = false;

                return;
            }

            // Places already in the plan appear at once, the rest follows from the server.
            const needle = query.toLowerCase();
            const planned = this.groups.flatMap((group) => group.pins.filter((pin) => pin.title.toLowerCase().includes(needle))
                .map((pin) => ({ id: pin.id, name: pin.title, address: group.label, lat: pin.lat, lng: pin.lng, kind: 'plan', icon: pin.icon }))).slice(0, 3);
            this.results = planned;
            this.searching = true;
            const merge = (found) => [...planned, ...found.filter((place) => ! planned.some((pin) => pin.name === place.name && Math.abs(pin.lat - place.lat) < 0.0005))];

            // 1. The places loaded for the trip area (instant) …
            const local = map ? await this.$wire.searchPlaces(query, this.bounds(), false) : [];

            if (run !== searchRun) {
                return; // An older search answered after a newer one.
            }

            this.results = merge(local);

            // 2. … then the OpenStreetMap search for everything else.
            const found = map ? await this.$wire.searchPlaces(query, this.bounds(), true) : [];

            if (run !== searchRun) {
                return;
            }

            this.results = merge(found);
            this.searching = false;
            this.searched = true;
        },

        move(step) {
            if (this.results.length > 0) {
                this.active = (this.active + step + this.results.length) % this.results.length;
            }
        },

        chooseActive() {
            if (this.results[this.active]) {
                this.choose(this.results[this.active]);
            } else {
                this.search();
            }
        },

        closeResults() {
            this.results = [];
            this.searched = false;
        },

        resultIcon(place) {
            return place.kind === 'plan' ? place.icon : (texts.icons[place.kind] ?? '📍');
        },

        choose(place) {
            this.closeResults();
            this.query = '';
            map.setView([place.lat, place.lng], Math.max(map.getZoom(), 16));

            if (place.kind === 'plan') {
                pinLayer.eachLayer((layer) => layer.getLatLng && layer.getLatLng().equals([place.lat, place.lng]) && layer.openPopup());

                return;
            }

            this.showPlace(place);
        },

        nearby() {
            this.showPois = !this.showPois;

            if (this.showPois) {
                this.loadNearby(true);
            } else {
                poiLayer.clearLayers();
            }
        },

        async loadNearby(announce = false) {
            this.loadingNearby = true;
            const places = await this.$wire.placesNearby(this.bounds());
            this.loadingNearby = false;
            poiLayer.clearLayers();

            if (!this.showPois) {
                return;
            }

            if (places.length === 0 && announce) {
                window.dispatchEvent(new CustomEvent('toast', { detail: { message: texts.zoomIn } }));

                return;
            }

            // Zoomed out only the icons, names from street level on (otherwise the labels cover the map).
            const withNames = map.getZoom() >= 15;

            for (const place of places) {
                const marker = L.marker([place.lat, place.lng], {
                    icon: L.divIcon({ className: '', iconSize: null, iconAnchor: [10, 10], html: `<span class="trip-poi" title="${escapeHtml(place.name)}">${escapeHtml(texts.icons[place.kind] ?? '📍')}${withNames ? ' ' + escapeHtml(place.name) : ''}</span>` }),
                });
                marker.on('click', () => this.showPlace(place));
                marker.addTo(poiLayer);
            }
        },
    };
};
