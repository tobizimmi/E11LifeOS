<?php

use App\Livewire\Concerns\InteractsWithHousehold;
use App\Models\PaperlessInstance;
use App\Support\Paperless\PaperlessUploader;
use App\Support\Paperless\ScanPdf;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Scan documents with the phone camera: capture several pages, enhance them, join them into one
 * PDF and send it to paperless. If paperless is unreachable the PDF is cached and the scheduler
 * retries the upload later, so a scan is never lost.
 */
new #[Layout('layouts.app')] class extends Component
{
    use InteractsWithHousehold;

    public string $title = '';

    public ?int $instanceId = null;

    public ?string $message = null;

    public bool $queued = false;

    public function mount(): void
    {
        $this->resolveHousehold();
        $this->instanceId = PaperlessInstance::defaultFor($this->currentUser())?->id;
    }

    /**
     * Builds the PDF from the captured pages and sends it to paperless (or queues it).
     *
     * @param  list<string>  $pages  data:image/...;base64,... – one per page
     */
    public function save(array $pages, string $title): void
    {
        $this->message = null;
        $user = $this->currentUser();
        $instance = PaperlessInstance::query()->availableTo($user)->find($this->instanceId)
            ?? PaperlessInstance::defaultFor($user);

        abort_if($instance === null, 404);

        if (count($pages) === 0 || count($pages) > ScanPdf::MAX_PAGES) {
            $this->message = __('Please capture between 1 and :max pages.', ['max' => ScanPdf::MAX_PAGES]);

            return;
        }

        $title = trim($title) !== '' ? trim($title) : __('Scan :date', ['date' => now()->format('d.m.Y H:i')]);

        try {
            $pdf = ScanPdf::fromImages($pages);
        } catch (\InvalidArgumentException) {
            $this->message = __('The scan could not be processed. Please try again.');

            return;
        }

        $filename = \Illuminate\Support\Str::slug($title) ?: 'scan';
        $this->queued = app(PaperlessUploader::class)->store($instance, $user, (int) $this->householdId, $pdf, $filename.'.pdf', $title);

        $this->message = $this->queued
            ? __('paperless was not reachable – the scan is saved and will be uploaded automatically as soon as the connection is back.')
            : __('Scan sent to paperless. It appears in the inbox for filing.');

        $this->title = '';
        $this->dispatch('scan-done');
        $this->dispatch('inbox-updated');
    }

    public function with(): array
    {
        $user = $this->currentUser();

        return [
            'instances' => PaperlessInstance::query()->availableTo($user)->orderBy('name')->get(),
            'pendingCount' => \App\Models\PaperlessPendingUpload::where('household_id', $this->householdId)->count(),
            'maxPages' => ScanPdf::MAX_PAGES,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">📷 {{ __('Scan document') }}</h2>
    </x-slot>

    <div class="py-6">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            @if ($instances->isEmpty())
                <div class="rounded-2xl bg-white p-6 text-sm text-gray-600 shadow-xs dark:bg-gray-800 dark:text-gray-300">
                    {{ __('No paperless connection set up yet. Add one under Profile → paperless to store scans.') }}
                    <a href="{{ route('profile') }}" wire:navigate class="text-indigo-600 underline dark:text-indigo-400">{{ __('To the settings') }}</a>
                </div>
            @else
                <div
                    class="space-y-4 rounded-2xl bg-white p-5 shadow-xs dark:bg-gray-800"
                    x-data="documentScanner({ max: {{ $maxPages }} })"
                >
                    @if ($instances->count() > 1)
                        <div>
                            <x-input-label for="scan-instance" :value="__('paperless instance')" />
                            <select id="scan-instance" wire:model="instanceId" class="mt-1 block w-full rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ($instances as $paperlessInstance)
                                    <option value="{{ $paperlessInstance->id }}">{{ $paperlessInstance->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div>
                        <x-input-label for="scan-title" :value="__('Title (optional)')" />
                        <x-text-input id="scan-title" wire:model="title" class="mt-1 block w-full" placeholder="{{ __('e.g. electricity bill 2026') }}" />
                    </div>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" x-model="enhance" class="rounded-sm border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                        {{ __('Auto-optimise (document mode: grey, higher contrast)') }}
                    </label>

                    {{-- Live camera --}}
                    <div x-show="cameraOn" x-cloak class="overflow-hidden rounded-lg bg-black">
                        <video x-ref="video" autoplay playsinline muted class="max-h-80 w-full object-contain"></video>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <x-primary-button type="button" x-show="!cameraOn" x-on:click="startCamera()">📷 {{ __('Start camera') }}</x-primary-button>
                        <x-primary-button type="button" x-show="cameraOn" x-cloak x-on:click="capture()">➕ {{ __('Capture page') }}</x-primary-button>
                        <x-secondary-button type="button" x-show="cameraOn" x-cloak x-on:click="stopCamera()">{{ __('Stop camera') }}</x-secondary-button>
                        <x-secondary-button type="button" x-on:click="$refs.fileInput.click()">🖼 {{ __('From photos / files') }}</x-secondary-button>
                        <input type="file" x-ref="fileInput" accept="image/*" multiple capture="environment" class="hidden" x-on:change="addFiles($event.target.files); $event.target.value = ''">
                    </div>

                    <p x-show="error" x-cloak x-text="error" class="text-sm text-red-600 dark:text-red-400"></p>

                    {{-- Captured pages --}}
                    <div x-show="pages.length" x-cloak>
                        <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300" x-text="pages.length + ' {{ __('page(s)') }}'"></p>
                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            <template x-for="(page, index) in pages" :key="page.id">
                                <div class="relative rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                                    <img :src="page.src" alt="" class="h-28 w-full object-cover bg-gray-50">
                                    <span class="absolute left-1 top-1 rounded-sm bg-black/60 px-1 text-xs text-white" x-text="index + 1"></span>
                                    <div class="absolute inset-x-0 bottom-0 flex justify-between bg-black/50 px-1 py-0.5 text-white">
                                        <button type="button" x-on:click="move(index, -1)" :disabled="index === 0" class="disabled:opacity-30">↑</button>
                                        <button type="button" x-on:click="move(index, 1)" :disabled="index === pages.length - 1" class="disabled:opacity-30">↓</button>
                                        <button type="button" x-on:click="remove(index)" class="text-red-300">✕</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                        <x-primary-button type="button" x-bind:disabled="pages.length === 0 || saving" x-on:click="submit()">
                            <span x-show="!saving">📤 {{ __('Send to paperless') }}</span>
                            <span x-show="saving" x-cloak>{{ __('Processing …') }}</span>
                        </x-primary-button>
                        <button type="button" x-show="pages.length" x-cloak x-on:click="pages = []" class="text-sm text-gray-500 underline">{{ __('Discard all') }}</button>
                    </div>

                    @if ($message)
                        <p class="rounded-md px-3 py-2 text-sm {{ $queued ? 'bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-200' : 'bg-green-50 text-green-800 dark:bg-green-900/30 dark:text-green-200' }}" role="status">{{ $message }}</p>
                    @endif

                    <canvas x-ref="canvas" class="hidden"></canvas>
                </div>

                @if ($pendingCount > 0)
                    <p class="mt-3 text-xs text-amber-700 dark:text-amber-300">⏳ {{ trans_choice(':count scan is waiting to be uploaded to paperless.|:count scans are waiting to be uploaded to paperless.', $pendingCount) }}</p>
                @endif

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ __('Tip: fill the frame with the document on a dark, plain surface for the best result. Up to :max pages per document.', ['max' => $maxPages]) }}</p>
            @endif
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('documentScanner', ({ max }) => ({
        cameraOn: false,
        enhance: true,
        pages: [],
        saving: false,
        error: '',
        stream: null,
        nextId: 1,

        async startCamera() {
            this.error = '';
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                this.$refs.video.srcObject = this.stream;
                this.cameraOn = true;
            } catch (e) {
                this.error = @js(__('The camera could not be opened. Use “From photos / files” instead.'));
            }
        },
        stopCamera() {
            if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; }
            this.cameraOn = false;
        },
        capture() {
            const video = this.$refs.video;
            if (!video.videoWidth) { return; }
            this.addPage(video, video.videoWidth, video.videoHeight);
        },
        addFiles(files) {
            Array.from(files).forEach(file => {
                if (!file.type.startsWith('image/')) { return; }
                const img = new Image();
                img.onload = () => { this.addPage(img, img.naturalWidth, img.naturalHeight); URL.revokeObjectURL(img.src); };
                img.src = URL.createObjectURL(file);
            });
        },
        addPage(source, w, h) {
            if (this.pages.length >= max) { this.error = @js(__('The maximum number of pages is reached.')); return; }
            const maxDim = 1600;
            const scale = Math.min(1, maxDim / Math.max(w, h));
            const cw = Math.round(w * scale), ch = Math.round(h * scale);
            const canvas = this.$refs.canvas;
            canvas.width = cw; canvas.height = ch;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(source, 0, 0, cw, ch);
            if (this.enhance) { this.optimise(ctx, cw, ch); }
            this.pages.push({ id: this.nextId++, src: canvas.toDataURL('image/jpeg', 0.82) });
            this.error = '';
        },
        optimise(ctx, w, h) {
            const image = ctx.getImageData(0, 0, w, h);
            const d = image.data;
            const contrast = 1.45, intercept = 128 * (1 - contrast);
            for (let i = 0; i < d.length; i += 4) {
                const gray = 0.3 * d[i] + 0.59 * d[i + 1] + 0.11 * d[i + 2];
                const v = Math.max(0, Math.min(255, contrast * gray + intercept));
                d[i] = d[i + 1] = d[i + 2] = v;
            }
            ctx.putImageData(image, 0, 0);
        },
        move(index, delta) {
            const to = index + delta;
            if (to < 0 || to >= this.pages.length) { return; }
            const copy = this.pages.slice();
            [copy[index], copy[to]] = [copy[to], copy[index]];
            this.pages = copy;
        },
        remove(index) { this.pages.splice(index, 1); },
        async submit() {
            if (!this.pages.length || this.saving) { return; }
            this.saving = true;
            this.stopCamera();
            try {
                await this.$wire.save(this.pages.map(p => p.src), this.$wire.title || '');
                this.pages = [];
            } finally {
                this.saving = false;
            }
        },
        init() {
            this.$wire.$on('scan-done', () => { this.pages = []; });
            window.addEventListener('beforeunload', () => this.stopCamera());
        },
    }));
</script>
@endscript
