<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaStorageService
{
    private const MAX_FILE_SIZE = 25 * 1024 * 1024;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function store(
        UploadedFile $file,
        int $campaignId,
    ): string {
        $this->validate($file);

        $extension =
            $file->guessExtension() ?: 'bin';

        $filename = sprintf(
            '%s.%s',
            bin2hex(random_bytes(16)),
            $extension,
        );

        $directory = sprintf(
            '%s/public/uploads/campaigns/%d',
            $this->projectDir,
            $campaignId,
        );

        if (
            !is_dir($directory) &&
            !mkdir(
                $directory,
                0775,
                true,
            ) &&
            !is_dir($directory)
        ) {
            throw new \RuntimeException(
                'Impossible de créer le répertoire des médias.',
            );
        }

        try {
            $file->move(
                $directory,
                $filename,
            );
        } catch (FileException $exception) {
            throw new \RuntimeException(
                'Impossible d’enregistrer le fichier.',
                previous: $exception,
            );
        }

        return $filename;
    }

    public function remove(
        string $filename,
        int $campaignId,
    ): void {
        $path = sprintf(
            '%s/public/uploads/campaigns/%d/%s',
            $this->projectDir,
            $campaignId,
            $filename,
        );

        if (
            is_file($path) &&
            !unlink($path)
        ) {
            throw new \RuntimeException(
                'Impossible de supprimer le fichier.',
            );
        }
    }

    private function validate(
        UploadedFile $file,
    ): void {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException(
                'Le fichier envoyé est invalide.',
            );
        }

        if (
            !in_array(
                $file->getMimeType(),
                self::ALLOWED_MIME_TYPES,
                true,
            )
        ) {
            throw new \InvalidArgumentException(
                'Format non autorisé. Formats acceptés : JPEG, PNG, WebPP et GIF.',
            );
        }

        if (
            $file->getSize() === false ||
            $file->getSize() >
                self::MAX_FILE_SIZE
        ) {
            throw new \InvalidArgumentException(
                'Le fichier ne doit pas dépasser 25 Mo.',
            );
        }
    }
}
