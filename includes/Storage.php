<?php

declare(strict_types=1);

namespace SepoLms;

/**
 * Private file storage for assignment uploads and proctoring recordings.
 *
 * Files live under uploads/sepo-lms-private/, which is closed to direct web
 * access (.htaccess for Apache/LiteSpeed, web.config for IIS; nginx needs the
 * rule shown on the settings page). Stored names are random, so a path can
 * never be guessed, and every download goes through a permission check in
 * Downloads.
 */
final class Storage
{
    public const DIR = 'sepo-lms-private';

    /** Extensions that are never stored, whatever an assignment allows. */
    private const BLOCKED = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'cgi', 'pl', 'py', 'rb',
        'asp', 'aspx', 'jsp', 'sh', 'bash', 'bat', 'cmd', 'exe', 'com', 'msi', 'dll', 'scr', 'vbs', 'js', 'mjs',
        'htaccess', 'htm', 'html', 'shtml', 'svg', 'xml', 'jar', 'apk',
    ];

    public static function base(): string
    {
        $up = wp_upload_dir(null, false);
        return trailingslashit((string)$up['basedir']) . self::DIR;
    }

    public static function dir(string $sub): string
    {
        $dir = self::base() . '/' . trim($sub, '/');
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            self::protect();
        }
        return $dir;
    }

    /** Write the deny rules and blank index files. Safe to call repeatedly. */
    public static function protect(): void
    {
        $base = self::base();
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return;
        }
        $files = [
            '.htaccess'  => "# Sepo LMS private files: served only through WordPress.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\nOptions -Indexes\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
        ];
        foreach ($files as $name => $body) {
            $path = $base . '/' . $name;
            if (!is_file($path)) {
                file_put_contents($path, $body); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (!is_file($sub . '/index.php')) {
                file_put_contents($sub . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.WP.AlternativeFunctions
            }
        }
    }

    /** The nginx rule that closes the folder, for the settings page. */
    public static function nginxRule(): string
    {
        $up = wp_upload_dir(null, false);
        $path = (string)wp_parse_url(trailingslashit((string)$up['baseurl']) . self::DIR . '/', PHP_URL_PATH);
        return 'location ^~ ' . $path . ' { deny all; }';
    }

    public static function isBlocked(string $ext): bool
    {
        return in_array(strtolower($ext), self::BLOCKED, true);
    }

    /**
     * Move one uploaded file into private storage.
     *
     * @param array<string,mixed> $file  One entry of $_FILES.
     * @param list<string>        $allowed Lower-case extensions.
     * @return array{name:string,path:string,size:int,mime:string}
     * @throws \DomainException With a message for the learner.
     */
    public static function storeUpload(array $file, string $sub, array $allowed, int $maxBytes): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \DomainException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('That file is larger than the server accepts.', 'sepo-lms'),
                UPLOAD_ERR_PARTIAL => __('The upload was interrupted. Try again.', 'sepo-lms'),
                UPLOAD_ERR_NO_FILE => __('No file was received.', 'sepo-lms'),
                default            => __('The server could not store that file.', 'sepo-lms'),
            });
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \DomainException(__('No file was received.', 'sepo-lms'));
        }
        $original = sanitize_file_name(wp_basename((string)($file['name'] ?? 'file')));
        $size = (int)filesize($tmp);
        if ($size <= 0) {
            throw new \DomainException(__('That file is empty.', 'sepo-lms'));
        }
        if ($maxBytes > 0 && $size > $maxBytes) {
            throw new \DomainException(sprintf(
                /* translators: 1: file name, 2: size limit */
                __('“%1$s” is larger than the %2$s limit.', 'sepo-lms'),
                $original,
                size_format($maxBytes)
            ));
        }

        $parts = explode('.', strtolower($original));
        $ext = count($parts) > 1 ? (string)end($parts) : '';
        foreach (array_slice($parts, 1) as $segment) {
            if (self::isBlocked($segment)) {
                throw new \DomainException(sprintf(/* translators: %s: file name */ __('“%s” is a type of file that cannot be uploaded.', 'sepo-lms'), $original));
            }
        }
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            throw new \DomainException(sprintf(
                /* translators: 1: file name, 2: allowed types */
                __('“%1$s” is not an accepted file type. Accepted: %2$s.', 'sepo-lms'),
                $original,
                implode(', ', $allowed)
            ));
        }

        // The content must match the extension (a renamed executable is refused).
        $check = wp_check_filetype_and_ext($tmp, $original);
        $mime = (string)($check['type'] ?: '');
        if ($mime === '' && !in_array($ext, ['txt', 'csv', 'md', 'rtf'], true)) {
            throw new \DomainException(sprintf(/* translators: %s: file name */ __('“%s” does not look like the type its name says.', 'sepo-lms'), $original));
        }
        if ($mime === '') {
            $mime = 'text/plain';
        }

        $dir = self::dir($sub);
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new \DomainException(__('The server could not store that file.', 'sepo-lms'));
        }
        @chmod($dest, 0640); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        return [
            'name' => $original,
            'path' => trim($sub, '/') . '/' . $stored,
            'size' => $size,
            'mime' => $mime,
        ];
    }

    /** Absolute path for a stored relative path, or '' when it escapes the folder or is missing. */
    public static function resolve(string $relative): string
    {
        $relative = ltrim(str_replace(['\\', "\0"], ['/', ''], $relative), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }
        $path = self::base() . '/' . $relative;
        return is_file($path) ? $path : '';
    }

    public static function delete(string $relative): void
    {
        $path = self::resolve($relative);
        if ($path !== '') {
            wp_delete_file($path);
        }
    }

    /**
     * Send a stored file to the browser and stop. Supports byte ranges so
     * video can be scrubbed.
     */
    /**
     * Send a stored file, with HTTP range support for media.
     *
     * $policy sets the Content-Security-Policy for files opened in the browser:
     *  - 'media' (and downloads): no scripts, no plugins, sandboxed.
     *  - 'pdf': the browser's own PDF viewer runs the file in its own isolated
     *    process and needs an unsandboxed document; it can only be framed by
     *    this site.
     */
    public static function stream(string $path, string $mime, string $downloadName, bool $inline, string $policy = 'media'): void
    {
        $size = (int)filesize($path);
        $start = 0;
        $end = $size - 1;

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        if ($inline && $policy === 'pdf') {
            header('Content-Security-Policy: frame-ancestors \'self\'');
        } else {
            header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; media-src \'self\'; style-src \'unsafe-inline\'; frame-ancestors \'self\'; sandbox');
        }
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        $safeName = str_replace(['"', "\r", "\n"], '', $downloadName);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));

        $range = isset($_SERVER['HTTP_RANGE']) ? (string)$_SERVER['HTTP_RANGE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && $size > 0) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int)$m[2]);
            } else {
                $start = (int)$m[1];
                $end = $m[2] !== '' ? min((int)$m[2], $size - 1) : $size - 1;
            }
            if ($start > $end || $start >= $size) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            status_header(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        } else {
            status_header(200);
        }
        header('Content-Length: ' . ($end - $start + 1));

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $fh = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ($fh === false) {
            exit;
        }
        fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh)) {
            $chunk = fread($fh, (int)min(65536, $left)); // phpcs:ignore WordPress.WP.AlternativeFunctions
            if ($chunk === false) {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
            $left -= strlen($chunk);
            flush();
        }
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /** Remove the whole private folder (uninstall). */
    public static function purgeAll(): void
    {
        $base = self::base();
        if (!is_dir($base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }
        @rmdir($base); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
    }
}
