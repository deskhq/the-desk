<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Pest\Browser\Drivers\LaravelHttpServer;

/**
 * pest-plugin-browser v4.3.1 serves the app in-process from a server that reads
 * `application/x-www-form-urlencoded` bodies and nothing else, and hands
 * `Request::create()` an empty file bag behind a `@TODO files...`. Every upload
 * a browser makes therefore reaches the application as no file at all: the
 * composer's attachment pre-upload 422s on `file` being required, the staged
 * chip is dropped, and no attachment behaviour is coverable in a browser test —
 * which is why `ComposerAttachmentTest` was retired in #483 rather than fixed.
 *
 * `tests/Browser/Support/LaravelHttpServer.php` shadows the vendor class (see
 * `tests/Unit/BrowserAssetDeliveryTest.php` for the rest of what it patches) and
 * parses the multipart body itself, so #920's mobile tray suite can stage a real
 * attachment. These tests pin that parsing.
 */
it('reads a file part into an uploaded file Laravel will accept', function (): void {
    [$parameters, $files] = parseMultipart([
        ['name' => 'file', 'filename' => 'venue.png', 'type' => 'image/png', 'content' => "\x89PNG\r\n\x1a\nbytes"],
    ]);

    expect($parameters)->toBe([])
        ->and($files['file'])->toBeInstanceOf(UploadedFile::class);

    $file = $files['file'];
    assert($file instanceof UploadedFile);

    expect($file->getClientOriginalName())->toBe('venue.png')
        ->and($file->getClientMimeType())->toBe('image/png')
        // Binary content survives the split verbatim: the trailing CRLF that
        // belongs to the boundary comes off, nothing inside the part does.
        ->and(file_get_contents($file->getPathname()))->toBe("\x89PNG\r\n\x1a\nbytes")
        // `is_uploaded_file()` is false for a file this process wrote, so
        // without the test flag the `file` validation rule rejects every upload.
        ->and($file->isValid())->toBeTrue();
});

it('reads scalar fields alongside the files, with PHP array shapes intact', function (): void {
    [$parameters, $files] = parseMultipart([
        ['name' => '_method', 'content' => 'PUT'],
        ['name' => 'tags[]', 'content' => 'offsite'],
        ['name' => 'tags[]', 'content' => 'venue'],
        ['name' => 'filters[type]', 'content' => 'image'],
        ['name' => 'file', 'filename' => 'quote.pdf', 'type' => 'application/pdf', 'content' => 'pdf'],
    ]);

    expect($parameters)->toBe([
        '_method' => 'PUT',
        'tags' => ['offsite', 'venue'],
        'filters' => ['type' => 'image'],
    ])->and($files)->toHaveKey('file');
});

it('places bracketed file fields where PHP would have put them', function (): void {
    [, $files] = parseMultipart([
        ['name' => 'photos[]', 'filename' => 'one.png', 'content' => 'a'],
        ['name' => 'photos[]', 'filename' => 'two.png', 'content' => 'b'],
        ['name' => 'docs[cover]', 'filename' => 'cover.pdf', 'content' => 'c'],
    ]);

    expect($files['photos'])->toHaveCount(2)
        ->and($files['photos'][0]->getClientOriginalName())->toBe('one.png')
        ->and($files['photos'][1]->getClientOriginalName())->toBe('two.png')
        ->and($files['docs']['cover']->getClientOriginalName())->toBe('cover.pdf');
});

it('leaves a body with no boundary to split on alone', function (): void {
    $server = new LaravelHttpServer('127.0.0.1', 1);

    $parsed = new ReflectionMethod($server, 'parseMultipartBody')
        ->invoke($server, 'whatever', 'multipart/form-data');

    expect($parsed)->toBe([[], []]);
});

it('skips a part carrying no headers or no name', function (): void {
    [$parameters, $files] = parseMultipart([
        ['name' => 'kept', 'content' => 'yes'],
        ['raw' => "Content-Disposition: form-data\r\n\r\nnameless"],
        ['raw' => 'no blank line so no body'],
    ]);

    expect($parameters)->toBe(['kept' => 'yes'])
        ->and($files)->toBe([]);
});

it('discards the temporary file of an upload the application never moved', function (): void {
    [, $files] = parseMultipart([
        ['name' => 'file', 'filename' => 'venue.png', 'content' => 'bytes'],
    ]);

    $file = $files['file'];
    assert($file instanceof UploadedFile);
    $path = $file->getPathname();

    expect($path)->toBeFile();

    new ReflectionMethod(new LaravelHttpServer('127.0.0.1', 1), 'discardTemporaryUploads')
        ->invoke(new LaravelHttpServer('127.0.0.1', 1), $files);

    expect(file_exists($path))->toBeFalse();
});

/**
 * Build a multipart body out of the given parts and run it through the shadow's
 * parser.
 *
 * A part is either `raw` (spliced in verbatim, for the malformed cases) or a
 * `name` with optional `filename`, `type` and `content`.
 *
 * @param  array<int, array<string, string>>  $parts
 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
 */
function parseMultipart(array $parts): array
{
    $boundary = '----PestBoundary1234';

    $body = '';

    foreach ($parts as $part) {
        $body .= "--{$boundary}\r\n";

        if (isset($part['raw'])) {
            $body .= $part['raw']."\r\n";

            continue;
        }

        $disposition = "Content-Disposition: form-data; name=\"{$part['name']}\"";

        if (isset($part['filename'])) {
            $disposition .= "; filename=\"{$part['filename']}\"";
        }

        $body .= $disposition."\r\n";

        if (isset($part['type'])) {
            $body .= "Content-Type: {$part['type']}\r\n";
        }

        $body .= "\r\n".($part['content'] ?? '')."\r\n";
    }

    $body .= "--{$boundary}--\r\n";

    $parsed = new ReflectionMethod(new LaravelHttpServer('127.0.0.1', 1), 'parseMultipartBody')
        ->invoke(new LaravelHttpServer('127.0.0.1', 1), $body, "multipart/form-data; boundary={$boundary}");

    assert(is_array($parsed));

    return $parsed;
}
