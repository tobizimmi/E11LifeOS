<?php

namespace App\Support\Drives;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * A drive that can be browsed by slash-separated folder names relative to its root.
 */
interface DriveClient
{
    /**
     * Folders first, then files, each alphabetically.
     *
     * @return list<array{name: string, path: string, is_dir: bool, size: ?int, modified: ?Carbon, type: ?string}>
     */
    public function list(string $path = ''): array;

    public function download(string $path): Response;

    /**
     * @param  resource  $stream
     */
    public function upload(string $folder, string $name, $stream): void;
}
