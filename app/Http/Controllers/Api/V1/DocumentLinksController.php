<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ExternalLink;
use App\Models\PaperlessInstance;
use App\Support\ExternalLinks;
use App\Support\Paperless\PaperlessClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Documents and mails attached to entries, pages, trips and so on. Paperless documents stay in
 * paperless – only the id is stored; the link is visible to exactly those who see the entry.
 */
class DocumentLinksController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(ExternalLinks::TYPES)], 'id' => ['required', 'integer']]);
        $householdId = $this->householdId();
        abort_if(ExternalLinks::resolve($this->user(), $householdId, $data['type'], (int) $data['id']) === null, 404);

        return $this->ok(ExternalLink::where('household_id', $householdId)->where('linkable_type', $data['type'])->where('linkable_id', (int) $data['id'])->orderBy('id')->get()->map(fn (ExternalLink $link) => $this->linkData($link))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(ExternalLinks::TYPES)],
            'id' => ['required', 'integer'],
            'kind' => ['required', Rule::in([ExternalLink::PAPERLESS, ExternalLink::MAIL])],
            'instance' => ['required_if:kind,paperless', 'nullable', 'integer'],
            'document' => ['required_if:kind,paperless', 'nullable', 'integer'],
            'url' => ['required_if:kind,mail', 'nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);
        $householdId = $this->householdId();
        abort_if(ExternalLinks::resolve($this->user(), $householdId, $data['type'], (int) $data['id']) === null, 404);
        $base = ['household_id' => $householdId, 'created_by' => $this->user()->id, 'linkable_type' => $data['type'], 'linkable_id' => (int) $data['id']];

        if ($data['kind'] === ExternalLink::MAIL) {
            abort_unless(ExternalLinks::isMailUrl((string) $data['url']), 422, __('Paste the link to the e-mail (e.g. message://, https://mail.google.com/…, Outlook link).'));
            $link = ExternalLink::create($base + ['kind' => ExternalLink::MAIL, 'url' => trim((string) $data['url']), 'title' => trim((string) ($data['title'] ?? '')) ?: __('E-mail (:app)', ['app' => ExternalLinks::mailApp((string) $data['url'])])]);

            return $this->ok($this->linkData($link), 201);
        }

        $instance = PaperlessInstance::query()->availableTo($this->user())->find((int) $data['instance']) ?? abort(404);
        $documentId = (int) $data['document'];

        // The document must really exist on that instance – otherwise any id could be attached.
        try {
            $exists = PaperlessClient::for($instance, $this->user())->document($documentId)['id'] === $documentId;
        } catch (Throwable) {
            $exists = false;
        }

        abort_unless($exists, 404, __('The document was not found in paperless.'));

        $link = ExternalLink::where($base)->where('paperless_instance_id', $instance->id)->where('paperless_document_id', $documentId)->first()
            ?? ExternalLink::create($base + ['kind' => ExternalLink::PAPERLESS, 'paperless_instance_id' => $instance->id, 'paperless_document_id' => $documentId, 'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: '#'.$documentId, 0, 255)]);

        return $this->ok($this->linkData($link), 201);
    }

    public function destroy(int $link): JsonResponse
    {
        $model = ExternalLink::where('household_id', $this->householdId())->findOrFail($link);
        abort_unless(ExternalLinks::canSee($this->user(), $model), 404);
        $model->delete();

        return $this->ok();
    }

    private function householdId(): int
    {
        return ($this->user()->currentHousehold() ?? abort(404, __('You are not a member of a household yet.')))->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkData(ExternalLink $link): array
    {
        return [
            'id' => $link->id,
            'kind' => $link->kind,
            'title' => $link->title,
            'url' => $link->kind === ExternalLink::MAIL ? $link->url : null,
            'instance' => $link->paperless_instance_id,
            'document' => $link->paperless_document_id,
            'pending' => $link->isPending(),
        ];
    }
}
