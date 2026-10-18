<?php

namespace App\Support\Paperless;

use App\Models\PaperlessInstance;
use App\Models\PaperlessPendingUpload;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends scanned or uploaded documents to paperless and, when the instance is unreachable, keeps
 * them on disk so the scheduler can retry the upload later. This way a scan is never lost just
 * because paperless (or the VPN to it) is down for a moment.
 */
class PaperlessUploader
{
    private const DISK = 'local';

    private const DIRECTORY = 'paperless-queue';

    private const MAX_ATTEMPTS = 48;

    /**
     * Tries to upload the document right away; on any failure it is stored and queued. Returns
     * whether it was queued (true) or uploaded immediately (false).
     *
     * @param  list<int>  $tagIds  paperless tag ids chosen while scanning (the inbox tag is added)
     */
    public function store(PaperlessInstance $instance, User $user, int $householdId, string $contents, string $filename, string $title, array $tagIds = [], ?int $correspondentId = null, ?int $documentTypeId = null): bool
    {
        try {
            $client = PaperlessClient::for($instance, $user);
            $client->uploadContents($contents, $filename, $title, [...$this->inboxTags($client), ...$tagIds], null, $correspondentId, $documentTypeId);

            return false;
        } catch (Throwable) {
            $this->queue($instance, $user, $householdId, $contents, $filename, $title, $tagIds, $correspondentId, $documentTypeId);

            return true;
        }
    }

    /**
     * Stores the document on disk and records it for a later retry.
     *
     * @param  list<int>  $tagIds
     */
    public function queue(?PaperlessInstance $instance, User $user, int $householdId, string $contents, string $filename, string $title, array $tagIds = [], ?int $correspondentId = null, ?int $documentTypeId = null): PaperlessPendingUpload
    {
        $path = self::DIRECTORY.'/'.Str::uuid()->toString().'.pdf';
        Storage::disk(self::DISK)->put($path, $contents);

        return PaperlessPendingUpload::create([
            'household_id' => $householdId,
            'user_id' => $user->id,
            'paperless_instance_id' => $instance?->id,
            'title' => mb_substr($title, 0, 255),
            'filename' => mb_substr($filename, 0, 255),
            'path' => $path,
            'tag_ids' => array_values(array_unique(array_map('intval', $tagIds))),
            'correspondent_id' => $correspondentId,
            'document_type_id' => $documentTypeId,
        ]);
    }

    /**
     * Retries all queued uploads. Returns how many were sent to paperless successfully.
     */
    public function flush(): int
    {
        $sent = 0;

        $pending = PaperlessPendingUpload::query()
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->with(['instance', 'user'])
            ->get();

        foreach ($pending as $upload) {
            if ($this->flushOne($upload)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function flushOne(PaperlessPendingUpload $upload): bool
    {
        $instance = $upload->instance;
        $user = $upload->user;

        if ($instance === null || $user === null || ! Storage::disk(self::DISK)->exists($upload->path)) {
            // Nothing we can ever send: drop the orphaned record and its file.
            $this->discard($upload);

            return false;
        }

        try {
            $client = PaperlessClient::for($instance, $user);
            $tagIds = [...$this->inboxTags($client), ...array_map('intval', (array) ($upload->tag_ids ?? []))];
            $client->uploadContents((string) Storage::disk(self::DISK)->get($upload->path), $upload->filename, $upload->title, $tagIds, null, $upload->correspondent_id, $upload->document_type_id);
        } catch (Throwable $exception) {
            $upload->forceFill([
                'attempts' => $upload->attempts + 1,
                'last_error' => mb_substr(class_basename($exception).' '.$exception->getMessage(), 0, 255),
                'last_tried_at' => now(),
            ])->save();

            return false;
        }

        $this->discard($upload);

        return true;
    }

    private function discard(PaperlessPendingUpload $upload): void
    {
        Storage::disk(self::DISK)->delete($upload->path);
        $upload->delete();
    }

    /**
     * The instance's inbox tags, so scans land in the paperless inbox; falls back to creating an
     * "Inbox" tag when the instance has none configured.
     *
     * @return list<int>
     */
    private function inboxTags(PaperlessClient $client): array
    {
        $tags = $client->inboxTagIds();

        return $tags !== [] ? $tags : [$client->ensureTag('Inbox')];
    }
}
