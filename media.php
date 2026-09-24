<?php
declare(strict_types=1);

function uploaded_asset_url(string $path): string
{
    if (!preg_match('~^assets/uploads/[a-f0-9]{32}\.(?:png|jpg|webp|ico)$~', $path)) return '';
    return is_file(__DIR__ . '/' . $path) ? $path : '';
}

function store_uploaded_image(?array $upload, string $kind): ?string
{
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (!in_array($kind, ['logo', 'favicon', 'qr', 'signature'], true)) throw new InvalidArgumentException('আপলোডের ধরন সঠিক নয়।');
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('ছবি আপলোড করা যায়নি। আবার চেষ্টা করুন।');
    }
    $temp = (string)($upload['tmp_name'] ?? '');
    if ($temp === '' || !is_uploaded_file($temp)) throw new InvalidArgumentException('সঠিক আপলোড ফাইল পাওয়া যায়নি।');
    $size = filesize($temp);
    $limit = $kind === 'favicon' ? 1024 * 1024 : 3 * 1024 * 1024;
    if ($size === false || $size < 1 || $size > $limit) {
        throw new InvalidArgumentException($kind === 'favicon' ? 'Favicon সর্বোচ্চ ১ MB হতে পারে।' : 'ছবি সর্বোচ্চ ৩ MB হতে পারে।');
    }

    $extension = null;
    $image = @getimagesize($temp);
    if ($image) {
        $extension = match ($image[2]) {
            IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_ICO => $kind === 'favicon' ? 'ico' : null,
            default => null,
        };
        $maxDimension = $kind === 'favicon' ? 512 : 4096;
        if (!$extension || $image[0] < 1 || $image[1] < 1 || $image[0] > $maxDimension || $image[1] > $maxDimension) {
            throw new InvalidArgumentException('ছবির ফরম্যাট বা আকার সঠিক নয়।');
        }
        if ($kind === 'favicon' && $extension === 'jpg') {
            throw new InvalidArgumentException('Favicon-এর জন্য PNG, WebP বা ICO ব্যবহার করুন।');
        }
    } elseif ($kind === 'favicon') {
        $handle = fopen($temp, 'rb');
        $header = $handle ? fread($handle, 6) : false;
        if ($handle) fclose($handle);
        if ($header !== false && strlen($header) === 6 && substr($header, 0, 4) === "\x00\x00\x01\x00" && unpack('v', substr($header, 4, 2))[1] > 0) {
            $extension = 'ico';
        }
    }
    if (!$extension) throw new InvalidArgumentException('PNG, JPG বা WebP ছবি দিন। Favicon-এর জন্য ICO-ও চলবে।');

    $directory = __DIR__ . '/assets/uploads';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Upload directory could not be created.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $directory . '/' . $filename;
    if (!move_uploaded_file($temp, $target)) throw new RuntimeException('Uploaded image could not be saved.');
    return 'assets/uploads/' . $filename;
}

function delete_uploaded_asset(string $path): void
{
    if (uploaded_asset_url($path) === '') return;
    @unlink(__DIR__ . '/' . $path);
}
