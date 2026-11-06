<?php

namespace App\Support;

use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;

/**
 * Shared hosting sometimes lacks PHP extensions (fileinfo, zip, dom, xmlreader) that only the host can add.
 * The app then runs in a limited mode instead of failing: storage without fileinfo, backups encrypted with
 * OpenSSL instead of an AES ZIP, CSV instead of Excel, printable pages instead of PDFs.
 */
final class Compat
{
    public static function fileinfo(): bool
    {
        return class_exists(\finfo::class);
    }

    public static function zip(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public static function readXlsx(): bool
    {
        return self::zip() && extension_loaded('xmlreader') && extension_loaded('dom');
    }

    public static function writeXlsx(): bool
    {
        return self::zip();
    }

    public static function pdf(): bool
    {
        return extension_loaded('dom') && extension_loaded('mbstring');
    }

    /** What works differently on this server, in plain words (empty when everything is available). */
    public static function limitations(): array
    {
        return array_values(array_filter([
            self::fileinfo() ? null : 'file types are told by their names (fileinfo missing)',
            self::writeXlsx() ? null : 'exports are CSV instead of Excel, and backups are encrypted with OpenSSL instead of a ZIP (zip missing)',
            self::readXlsx() ? null : 'student and faculty files must be uploaded as CSV, not Excel (zip, xmlreader or dom missing)',
            self::pdf() ? null : 'printouts open as printable pages instead of PDF files (dom missing)',
        ]));
    }

    /** Without fileinfo, the local disks find a file's type by its extension instead of failing. */
    public static function registerStorageFallback(): void
    {
        if (self::fileinfo()) {
            return;
        }
        Storage::extend('local', function ($app, array $config) {
            $visibility = PortableVisibilityConverter::fromArray($config['permissions'] ?? [], $config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE);
            $links = ($config['links'] ?? null) === 'skip' ? LocalAdapter::SKIP_LINKS : LocalAdapter::DISALLOW_LINKS;
            $adapter = new LocalAdapter($config['root'], $visibility, $config['lock'] ?? LOCK_EX, $links, new ExtensionMimeTypeDetector);
            $flysystem = new Filesystem($adapter, Arr::only($config, ['directory_visibility', 'disable_asserts', 'retain_visibility', 'temporary_url', 'url', 'visibility']));

            return new LocalFilesystemAdapter($flysystem, $adapter, $config);
        });
    }
}
