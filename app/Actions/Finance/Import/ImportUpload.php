<?php

namespace App\Actions\Finance\Import;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An uploaded file waiting to be mapped or imported, kept on the private disk under its owner's
 * id. Another user's upload id resolves to nothing.
 */
final readonly class ImportUpload
{
    private const ROOT = 'finance-imports';

    public function __construct(public string $id, public string $filename, private string $path) {}

    public static function store(User $user, UploadedFile $file): self
    {
        self::pruneStale($user);

        $id = (string) Str::uuid();
        $filename = Str::limit(basename($file->getClientOriginalName()), 200, '') ?: 'import.csv';
        $path = $file->storeAs(self::directory($user, $id), $filename, 'local');

        return new self($id, $filename, (string) $path);
    }

    public static function find(User $user, string $id): ?self
    {
        $path = Str::isUuid($id) ? Storage::disk('local')->files(self::directory($user, $id))[0] ?? null : null;

        return $path === null ? null : new self($id, basename($path), $path);
    }

    public static function findOrFail(User $user, string $id): self
    {
        return self::find($user, $id) ?? abort(404);
    }

    public function bytes(): string
    {
        return (string) Storage::disk('local')->get($this->path);
    }

    public function delete(): void
    {
        Storage::disk('local')->deleteDirectory(dirname($this->path));
    }

    private static function directory(User $user, string $id): string
    {
        return self::ROOT."/{$user->id}/{$id}";
    }

    /**
     * Drop the user's uploads that were never imported, after a day.
     */
    private static function pruneStale(User $user): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->directories(self::ROOT."/{$user->id}") as $directory) {
            $files = $disk->files($directory);

            if ($files === [] || $disk->lastModified($files[0]) < now()->subDay()->getTimestamp()) {
                $disk->deleteDirectory($directory);
            }
        }
    }
}
