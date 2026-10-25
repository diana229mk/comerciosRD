<?php

namespace App\Services;

use App\Support\TenantStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrivateDocumentStorage
{
    public function directoryForCurrentBusiness(string $base): string
    {
        $businessId = TenantStorage::currentBusinessId();

        abort_unless($businessId, 403);

        return TenantStorage::directory($base, $businessId);
    }

    /**
     * Download a document belonging to an already-authorized business record.
     *
     * Legacy public-disk paths are supported temporarily so existing records
     * keep working until an operator runs the documented migration.
     *
     * @param  list<string>  $legacyPrefixes
     */
    public function download(
        string $path,
        string $businessId,
        string $privatePrefix,
        array $legacyPrefixes,
        string $downloadName,
    ): StreamedResponse {
        abort_unless($this->isSafeRelativePath($path), 404);

        $private = Storage::disk('private');
        if ($this->matchesPrefix($path, $privatePrefix.'/'.$businessId.'/') && $private->exists($path)) {
            return $private->download($path, $downloadName);
        }

        foreach ($legacyPrefixes as $prefix) {
            if ($this->isLegacyPathForBusiness($path, $prefix, $businessId)
                && Storage::disk('public')->exists($path)
            ) {
                return Storage::disk('public')->download($path, $downloadName);
            }
        }

        abort(404);
    }

    public function materializeForProcessing(string $path, string $base): string
    {
        $businessId = TenantStorage::currentBusinessId();
        abort_unless($businessId, 403);
        abort_unless(
            $this->isSafeRelativePath($path)
                && $this->matchesPrefix($path, $base.'/'.$businessId.'/'),
            404,
        );

        $disk = Storage::disk('private');
        abort_unless($disk->exists($path), 404);
        $input = $disk->readStream($path);
        if (! is_resource($input)) {
            throw new RuntimeException('No se pudo abrir el archivo privado para procesarlo.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'comerciosrd-');
        if ($temporaryPath === false) {
            fclose($input);
            throw new RuntimeException('No se pudo crear un archivo temporal seguro.');
        }
        if (! chmod($temporaryPath, 0600)) {
            fclose($input);
            unlink($temporaryPath);
            throw new RuntimeException('No se pudieron proteger los permisos del archivo temporal.');
        }
        $completed = false;

        try {
            $output = fopen($temporaryPath, 'wb');
            if (! is_resource($output)) {
                throw new RuntimeException('No se pudo preparar el archivo privado para procesarlo.');
            }

            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new RuntimeException('No se pudo leer el archivo privado completo.');
                }
                $completed = true;
            } finally {
                fclose($output);
            }
        } finally {
            fclose($input);
            if (! $completed) {
                unlink($temporaryPath);
            }
        }

        return $temporaryPath;
    }

    public function assertStoredForBusiness(string $path, string $base, string $businessId, string $field): void
    {
        if (! $this->isSafeRelativePath($path)
            || ! $this->matchesPrefix($path, $base.'/'.$businessId.'/')
            || ! Storage::disk('private')->exists($path)
        ) {
            throw ValidationException::withMessages([
                $field => 'El archivo no pertenece a este negocio o ya no está disponible.',
            ]);
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === ''
            || preg_match('//u', $path) !== 1
            || str_contains($path, "\0")
            || str_contains($path, '\\')
        ) {
            return false;
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path)) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                return false;
            }
        }

        return true;
    }

    private function matchesPrefix(string $path, string $prefix): bool
    {
        return str_starts_with($path, $prefix);
    }

    private function isLegacyPathForBusiness(string $path, string $prefix, string $businessId): bool
    {
        if ($this->matchesPrefix($path, $prefix.'/'.$businessId.'/')) {
            return true;
        }

        $legacyPath = substr($path, strlen($prefix) + 1);

        return $this->matchesPrefix($path, $prefix.'/')
            && $legacyPath !== ''
            && ! str_contains($legacyPath, '/');
    }
}
