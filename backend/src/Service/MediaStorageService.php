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

    /** Stockage global des illustrations du bestiaire (indépendant des campagnes). */
    public function storeBeast(UploadedFile $file): string
    {
        $this->validate($file);
        // Limite plus stricte pour les vignettes du catalogue.
        if (($file->getSize() ?: 0) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Illustration limitée à 5 Mo.');
        }
        $dimensions = @getimagesize($file->getPathname());
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 16000000) {
            throw new \InvalidArgumentException('Illustration invalide ou supérieure à 16 millions de pixels.');
        }
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png',
            'image/webp' => 'webp', 'image/gif' => 'gif',
            default => throw new \InvalidArgumentException('Format invalide.'),
        };
        $directory = $this->projectDir . '/public/uploads/beasts';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire du bestiaire.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $extension;
        try { $file->move($directory, $filename); }
        catch (FileException $e) { throw new \RuntimeException('Impossible de stocker l’illustration.', previous: $e); }
        return $filename;
    }

    public function removeBeast(string $filename): void
    {
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/D', $filename)) {
            throw new \InvalidArgumentException('Nom de fichier invalide.');
        }
        $directory = $this->projectDir . '/public/uploads/beasts';
        $path = $directory . '/' . $filename;
        if (is_link($path) || (is_file($path) && !unlink($path))) {
            throw new \RuntimeException('Suppression de l’illustration impossible.');
        }
        $thumbnail = $directory . '/thumbnails/' . $filename . '.webp';
        if (is_link($thumbnail) || (is_file($thumbnail) && !unlink($thumbnail))) {
            throw new \RuntimeException('Suppression de la miniature impossible.');
        }
    }

    /** Lazy generation also covers illustrations uploaded before thumbnails existed. */
    public function beastThumbnail(string $filename): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/D', $filename)) throw new \InvalidArgumentException('Nom de fichier invalide.');
        $directory = $this->projectDir . '/public/uploads/beasts';
        $source = $directory . '/' . $filename;
        if (!is_file($source) || is_link($source)) throw new \RuntimeException('Illustration introuvable.');
        $target = $directory . '/thumbnails/' . $filename . '.webp';
        if (is_file($target) && !is_link($target)) return $target;
        if (!extension_loaded('gd')) throw new \RuntimeException('Extension GD requise pour les miniatures.');
        $dimensions = @getimagesize($source);
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 16000000) throw new \InvalidArgumentException('Illustration trop grande.');
        $image = match ($dimensions[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source), IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source), IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };
        if (!$image) throw new \InvalidArgumentException('Image illisible.');
        $ratio = min(1, 256 / max($dimensions[0], $dimensions[1]));
        $width = max(1, (int) round($dimensions[0] * $ratio));
        $height = max(1, (int) round($dimensions[1] * $ratio));
        $thumb = imagecreatetruecolor($width, $height);
        imagealphablending($thumb, false); imagesavealpha($thumb, true);
        imagefill($thumb, 0, 0, imagecolorallocatealpha($thumb, 0, 0, 0, 127));
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, $dimensions[0], $dimensions[1]);
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) throw new \RuntimeException('Stockage des miniatures inaccessible.');
        $temporary = $target . '.' . bin2hex(random_bytes(8));
        try {
            if (!imagewebp($thumb, $temporary, 80) || !rename($temporary, $target)) throw new \RuntimeException('Création de miniature impossible.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
            imagedestroy($thumb); imagedestroy($image);
        }
        return $target;
    }

    public function remove(
        string $filename,
        int $campaignId,
    ): void {
        if ($campaignId <= 0 || !preg_match('/^[a-f0-9]{32}\.(?:jpe?g|png|webp|gif|bin)$/D', $filename)) {
            throw new \RuntimeException('Le fichier ne fait pas partie du stockage des médias.');
        }

        $path = sprintf(
            '%s/public/uploads/campaigns/%d/%s',
            $this->projectDir,
            $campaignId,
            $filename,
        );

        $root = realpath($this->projectDir . '/public/uploads/campaigns');
        $directory = realpath(dirname($path));
        $expectedRoot = realpath($this->projectDir)
            . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'campaigns';
        if (
            is_link($path)
            || ($root !== false && $root !== $expectedRoot)
            || ($directory !== false && ($root === false || $directory !== $root . DIRECTORY_SEPARATOR . $campaignId))
        ) {
            throw new \RuntimeException('Le fichier ne fait pas partie du stockage des médias.');
        }

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
